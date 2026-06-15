<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\Xlsx;

/**
 * Обращения граждан (59-ФЗ): регистрация (срок 30 дней), назначение исполнителя,
 * однократное продление до 30 дней с причиной, ответ, контроль сроков.
 */
class AppealController extends Controller
{
    public const STATUS = [
        'registered' => 'Зарегистрировано',
        'work'       => 'В работе',
        'extended'   => 'Срок продлён',
        'answered'   => 'Отвечено',
    ];
    public const SOURCE = ['personal' => 'Личный приём', 'mail' => 'Почта', 'internet' => 'Интернет-приёмная'];

    private function canManage(array $me): bool
    {
        return in_array($me['role'], ['admin', 'manager'], true);
    }

    public static function inboxCount(int $uid): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM appeals WHERE assignee_id = ? AND status IN ('registered','work','extended')", [$uid]);
    }

    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $manage = $this->canManage($me);
        $st = (string) $this->input('status');

        $where = $manage ? '1=1' : 'a.assignee_id = ' . $uid;
        $params = [];
        if ($st !== '') { $where .= ' AND a.status = ?'; $params[] = $st; }

        $rows = Database::all(
            "SELECT a.*, u.full_name AS assignee_name FROM appeals a LEFT JOIN users u ON u.id = a.assignee_id
              WHERE $where ORDER BY CASE WHEN a.status='answered' THEN 1 ELSE 0 END, a.due_date", $params);

        if ($this->input('export') && $manage) {
            Xlsx::download('appeals-' . date('Y-m-d') . '.xlsx', [[
                'name' => 'Обращения',
                'headers' => ['№', 'Заявитель', 'Тема', 'Источник', 'Поступило', 'Срок', 'Исполнитель', 'Статус', 'Отвечено'],
                'rows' => array_map(fn($r) => [$r['number'], $r['applicant'], $r['subject'],
                    self::SOURCE[$r['source']] ?? $r['source'], $r['received_at'], $r['due_date'],
                    $r['assignee_name'] ?? '', self::STATUS[$r['status']] ?? $r['status'],
                    $r['answered_at'] ? substr($r['answered_at'], 0, 10) : ''], $rows),
            ]]);
        }

        $this->view('appeals/index', [
            'title' => 'Обращения граждан',
            'rows' => $rows,
            'manage' => $manage,
            'st' => $st,
            'users' => Database::all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name'),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canManage($me)) { flash('Регистрируют обращения админ/менеджер.', 'error'); $this->redirect('/appeals'); }
        $applicant = trim((string) $this->input('applicant'));
        $subject = trim((string) $this->input('subject'));
        if ($applicant === '' || $subject === '') { flash('Укажите заявителя и тему.', 'error'); $this->redirect('/appeals'); }
        $received = $this->input('received_at') ?: date('Y-m-d');
        $due = date('Y-m-d', strtotime($received . ' +30 days')); // 59-ФЗ: 30 дней
        $n = 1 + (int) Database::scalar("SELECT COUNT(*) FROM appeals WHERE substr(received_at,1,4) = ?", [substr($received, 0, 4)]);
        $number = 'ОГ-' . $n . '/' . substr($received, 2, 2);
        $assignee = $this->input('assignee_id') ? (int) $this->input('assignee_id') : null;
        $aid = Database::insert(
            'INSERT INTO appeals (number, applicant, contact, source, subject, body, received_at, due_date, assignee_id, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$number, $applicant, trim((string) $this->input('contact')), $this->input('source') ?: 'internet',
             $subject, (string) $this->input('body'), $received, $due, $assignee, $assignee ? 'work' : 'registered', $me['id']]
        );
        $this->log((int) $aid, "Зарегистрировано, № {$number}, срок {$due}");
        if ($assignee) {
            NotificationService::create($assignee, 'Обращение гражданина', "№{$number} «{$subject}» — срок ответа {$due}.");
            $this->log((int) $aid, 'Назначен исполнитель');
        }
        flash("Обращение зарегистрировано: {$number}, срок ответа {$due}.");
        $this->redirect('/appeals/' . $aid);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $a = Database::one(
            'SELECT a.*, u.full_name AS assignee_name FROM appeals a LEFT JOIN users u ON u.id=a.assignee_id WHERE a.id = ?', [$id]);
        if (!$a) { $this->redirect('/appeals'); }
        $manage = $this->canManage($me);
        if (!$manage && (int) $a['assignee_id'] !== (int) $me['id']) { flash('Нет доступа.', 'error'); $this->redirect('/appeals'); }
        $this->view('appeals/show', [
            'title' => 'Обращение ' . $a['number'],
            'a' => $a,
            'log' => Database::all('SELECT * FROM appeal_log WHERE appeal_id = ? ORDER BY id', [$id]),
            'manage' => $manage,
            'isAssignee' => (int) $a['assignee_id'] === (int) $me['id'],
            'users' => Database::all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name'),
            'wasExtended' => (bool) Database::scalar("SELECT 1 FROM appeal_log WHERE appeal_id=? AND event LIKE 'Срок продлён%'", [$id]),
        ]);
    }

    public function action(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $a = Database::one('SELECT * FROM appeals WHERE id = ?', [$id]);
        if (!$a) { $this->redirect('/appeals'); }
        $manage = $this->canManage($me);
        $isAssignee = (int) $a['assignee_id'] === (int) $me['id'];
        $act = (string) $this->input('act');

        if ($act === 'assign' && $manage) {
            $to = (int) $this->input('assignee_id');
            Database::run("UPDATE appeals SET assignee_id=?, status=CASE WHEN status='registered' THEN 'work' ELSE status END WHERE id=?", [$to ?: null, $id]);
            if ($to) {
                NotificationService::create($to, 'Обращение гражданина', "№{$a['number']} «{$a['subject']}» — срок {$a['due_date']}.");
                $this->log((int) $id, 'Исполнитель: ' . Database::scalar('SELECT full_name FROM users WHERE id=?', [$to]));
            }
            flash('Исполнитель назначен.');
        } elseif ($act === 'extend' && ($manage || $isAssignee) && $a['status'] !== 'answered') {
            // 59-ФЗ: однократное продление не более чем на 30 дней с уведомлением заявителя
            if (Database::scalar("SELECT 1 FROM appeal_log WHERE appeal_id=? AND event LIKE 'Срок продлён%'", [$id])) {
                flash('Срок уже продлевался — повторное продление не допускается (59-ФЗ).', 'error');
                $this->redirect('/appeals/' . $id);
            }
            $reason = trim((string) $this->input('reason'));
            if ($reason === '') { flash('Укажите причину продления.', 'error'); $this->redirect('/appeals/' . $id); }
            $newDue = date('Y-m-d', strtotime($a['due_date'] . ' +30 days'));
            Database::run("UPDATE appeals SET due_date=?, status='extended' WHERE id=?", [$newDue, $id]);
            $this->log((int) $id, "Срок продлён до {$newDue}: {$reason}");
            flash("Срок продлён до {$newDue}. Не забудьте уведомить заявителя.");
        } elseif ($act === 'answer' && ($manage || $isAssignee) && $a['status'] !== 'answered') {
            $answer = trim((string) $this->input('answer'));
            if ($answer === '') { flash('Введите текст ответа.', 'error'); $this->redirect('/appeals/' . $id); }
            Database::run("UPDATE appeals SET status='answered', answer=?, answered_at=? WHERE id=?", [$answer, date('Y-m-d H:i:s'), $id]);
            $overdue = date('Y-m-d') > $a['due_date'] ? ' (С ПРОСРОЧКОЙ)' : '';
            $this->log((int) $id, 'Дан ответ заявителю' . $overdue);
            flash('Ответ зафиксирован.');
        }
        $this->redirect('/appeals/' . $id);
    }

    private function log(int $appealId, string $event): void
    {
        Database::insert('INSERT INTO appeal_log (appeal_id, event, user_name) VALUES (?,?,?)',
            [$appealId, $event, $_SESSION['name'] ?? '']);
    }
}
