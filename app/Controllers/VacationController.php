<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;

/**
 * График отпусков: заявка сотрудника → согласование руководителем отдела (с правом редактуры дат)
 * → утверждение (manager/admin). Автоотказ при пересечении с запретными зонами дат.
 * Изменение сроков — заявка kind=change, по утверждении заменяет исходную.
 */
class VacationController extends Controller
{
    public const STATUS = [
        'on_head'       => 'У руководителя',
        'on_approve'    => 'На утверждении',
        'approved'      => 'Утверждён',
        'rejected'      => 'Отклонён',
        'auto_rejected' => 'Автоотказ',
        'replaced'      => 'Заменён',
    ];

    private function isApprover(array $me): bool
    {
        return in_array($me['role'], ['admin', 'manager'], true);
    }

    private function headDeptId(int $uid): ?int
    {
        $d = Database::scalar('SELECT id FROM departments WHERE head_id = ?', [$uid]);
        return $d === false ? null : (int) $d;
    }

    public static function inboxCount(int $uid): int
    {
        $me = Database::one('SELECT * FROM users WHERE id = ?', [$uid]);
        $n = 0;
        $head = Database::scalar('SELECT id FROM departments WHERE head_id = ?', [$uid]);
        if ($head) {
            $n += (int) Database::scalar(
                "SELECT COUNT(*) FROM vacation_requests v JOIN users u ON u.id = v.employee_id
                  WHERE v.status='on_head' AND u.department_id = ?", [$head]);
        }
        if ($me && in_array($me['role'], ['admin', 'manager'], true)) {
            $n += (int) Database::scalar("SELECT COUNT(*) FROM vacation_requests WHERE status='on_approve'");
        }
        return $n;
    }

    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $year = (int) ($this->input('year') ?: date('Y') + 1);
        $headDept = $this->headDeptId($uid);

        $my = Database::all('SELECT * FROM vacation_requests WHERE employee_id = ? AND year = ? ORDER BY id DESC', [$uid, $year]);

        // очередь согласования руководителю
        $queueHead = $headDept ? Database::all(
            "SELECT v.*, u.full_name FROM vacation_requests v JOIN users u ON u.id = v.employee_id
              WHERE v.status='on_head' AND u.department_id = ? ORDER BY v.id", [$headDept]) : [];
        // очередь утверждения
        $queueApprove = $this->isApprover($me) ? Database::all(
            "SELECT v.*, u.full_name, d.name AS dept_name FROM vacation_requests v
               JOIN users u ON u.id = v.employee_id LEFT JOIN departments d ON d.id = u.department_id
              WHERE v.status='on_approve' ORDER BY v.id") : [];

        // сводный график (утверждённые + в работе)
        $schedule = Database::all(
            "SELECT v.*, u.full_name, d.name AS dept_name FROM vacation_requests v
               JOIN users u ON u.id = v.employee_id LEFT JOIN departments d ON d.id = u.department_id
              WHERE v.year = ? AND v.status IN ('approved','on_head','on_approve')
              ORDER BY d.name, u.full_name, v.start_date", [$year]);

        // запретные зоны: руководителю/админу — управление
        $blackouts = ($headDept || $this->isApprover($me)) ? Database::all(
            "SELECT b.*, d.name AS dept_name, u.full_name FROM vacation_blackouts b
               LEFT JOIN departments d ON d.id = b.department_id LEFT JOIN users u ON u.id = b.employee_id
              ORDER BY b.start_date") : [];

        $this->view('vacations/index', [
            'title' => 'График отпусков',
            'year' => $year,
            'my' => $my,
            'queueHead' => $queueHead,
            'queueApprove' => $queueApprove,
            'schedule' => $schedule,
            'blackouts' => $blackouts,
            'isApprover' => $this->isApprover($me),
            'isHead' => (bool) $headDept,
            'employees' => Database::all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name'),
            'departments' => Database::all('SELECT * FROM departments ORDER BY name'),
            'approvedMine' => Database::all(
                "SELECT * FROM vacation_requests WHERE employee_id=? AND year=? AND status='approved'", [$uid, $year]),
        ]);
    }

    /** Подача заявки (initial или change). */
    public function store(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $start = (string) $this->input('start_date');
        $end = (string) $this->input('end_date');
        $year = (int) ($this->input('year') ?: date('Y', strtotime($start)));
        $kind = $this->input('kind') === 'change' ? 'change' : 'initial';
        $replaces = $kind === 'change' ? (int) $this->input('replaces_id') : null;

        if (!$start || !$end || $end < $start) {
            flash('Укажите корректные даты отпуска.', 'error');
            $this->redirect('/vacations');
        }
        $days = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;

        // АВТООТКАЗ: пересечение с запретной зоной для этого работника.
        $bl = Database::one(
            "SELECT b.*, d.name AS dept_name FROM vacation_blackouts b
               LEFT JOIN departments d ON d.id = b.department_id
              WHERE (b.employee_id = ? OR (b.employee_id IS NULL AND (b.department_id IS NULL OR b.department_id = ?)))
                AND b.start_date <= ? AND b.end_date >= ?
              LIMIT 1",
            [$uid, $me['department_id'] ?: 0, $end, $start]
        );
        if ($bl) {
            $reason = "Период пересекается с запретной зоной {$bl['start_date']}–{$bl['end_date']}" . ($bl['reason'] ? " ({$bl['reason']})" : '');
            Database::insert(
                'INSERT INTO vacation_requests (employee_id, year, start_date, end_date, days, kind, replaces_id, status, comment, decided_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$uid, $year, $start, $end, $days, $kind, $replaces, 'auto_rejected', $reason, date('Y-m-d H:i:s')]
            );
            NotificationService::create($uid, 'Отпуск: автоотказ', $reason . '. Выберите другие даты.');
            flash('Автоотказ: ' . $reason, 'error');
            $this->redirect('/vacations');
        }

        // куда направить: главе отдела; если её нет или сам глава — сразу на утверждение
        $head = $me['department_id'] ? Database::scalar('SELECT head_id FROM departments WHERE id = ?', [$me['department_id']]) : null;
        $status = ($head && (int) $head !== $uid) ? 'on_head' : 'on_approve';

        $vid = Database::insert(
            'INSERT INTO vacation_requests (employee_id, year, start_date, end_date, days, kind, replaces_id, status)
             VALUES (?,?,?,?,?,?,?,?)',
            [$uid, $year, $start, $end, $days, $kind, $replaces, $status]
        );
        if ($status === 'on_head') {
            NotificationService::create((int) $head, 'Заявка на отпуск', "{$me['full_name']}: {$start} — {$end} ({$days} дн.)" . ($kind === 'change' ? ' [изменение сроков]' : ''));
        } else {
            $this->notifyApprovers("Заявка на отпуск: {$me['full_name']} {$start} — {$end}");
        }
        flash('Заявка подана.');
        $this->redirect('/vacations');
    }

    /** Решение руководителя/утверждающего. Руководитель может отредактировать даты. */
    public function decide(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $v = Database::one('SELECT v.*, u.full_name, u.department_id FROM vacation_requests v JOIN users u ON u.id=v.employee_id WHERE v.id = ?', [$id]);
        if (!$v) { $this->redirect('/vacations'); }
        $act = (string) $this->input('act');
        $comment = trim((string) $this->input('comment'));
        $now = date('Y-m-d H:i:s');

        $isHeadOf = $v['department_id'] && Database::scalar('SELECT 1 FROM departments WHERE id = ? AND head_id = ?', [$v['department_id'], $uid]);

        if ($v['status'] === 'on_head' && $isHeadOf) {
            // редактура дат руководителем
            $ns = (string) ($this->input('start_date') ?: $v['start_date']);
            $ne = (string) ($this->input('end_date') ?: $v['end_date']);
            if ($ne >= $ns && ($ns !== $v['start_date'] || $ne !== $v['end_date'])) {
                $days = (int) ((strtotime($ne) - strtotime($ns)) / 86400) + 1;
                Database::run('UPDATE vacation_requests SET start_date=?, end_date=?, days=? WHERE id=?', [$ns, $ne, $days, $id]);
                $comment = trim($comment . ' Даты скорректированы руководителем: ' . $ns . ' — ' . $ne . '.');
            }
            if ($act === 'approve') {
                Database::run("UPDATE vacation_requests SET status='on_approve', comment=? WHERE id=?", [$comment ?: null, $id]);
                NotificationService::create((int) $v['employee_id'], 'Отпуск согласован руководителем', 'Заявка передана на утверждение.' . ($comment ? " Комментарий: {$comment}" : ''));
                $this->notifyApprovers("Отпуск на утверждение: {$v['full_name']}");
                flash('Согласовано, передано на утверждение.');
            } else {
                Database::run("UPDATE vacation_requests SET status='rejected', comment=?, decided_at=? WHERE id=?", [$comment ?: 'Отклонено руководителем', $now, $id]);
                NotificationService::create((int) $v['employee_id'], 'Отпуск отклонён', $comment ?: 'Отклонено руководителем.');
                flash('Заявка отклонена.');
            }
        } elseif ($v['status'] === 'on_approve' && $this->isApprover($me)) {
            if ($act === 'approve') {
                Database::run("UPDATE vacation_requests SET status='approved', decided_at=? WHERE id=?", [$now, $id]);
                if ($v['kind'] === 'change' && $v['replaces_id']) {
                    Database::run("UPDATE vacation_requests SET status='replaced' WHERE id=?", [$v['replaces_id']]);
                }
                NotificationService::create((int) $v['employee_id'], 'Отпуск утверждён', "Период {$v['start_date']} — {$v['end_date']} утверждён.");
                // Если у сотрудника есть право подписи, а на период отпуска не назначен И.о./ВРИО — мягко предупреждаем.
                if (\App\Services\Acting::hasSigningAuthority((int) $v['employee_id'])
                    && !\App\Services\Acting::coversRange((int) $v['employee_id'], (string) $v['start_date'], (string) $v['end_date'])) {
                    flash("Отпуск утверждён. ВНИМАНИЕ: {$v['full_name']} вправе подписывать/утверждать документы, но на период отпуска не назначен И.о./ВРИО. Назначьте замещающего в разделе «Замещение» (меню справа).", 'error');
                } else {
                    flash('Отпуск утверждён.');
                }
            } else {
                Database::run("UPDATE vacation_requests SET status='rejected', comment=?, decided_at=? WHERE id=?", [$comment ?: 'Отклонено при утверждении', $now, $id]);
                NotificationService::create((int) $v['employee_id'], 'Отпуск отклонён', $comment ?: 'Отклонено при утверждении.');
                flash('Заявка отклонена.');
            }
        } else {
            flash('Нет прав на это решение.', 'error');
        }
        $this->redirect('/vacations');
    }

    /** Уведомление об отпуске: печатная форма + электронная отправка. */
    public function notice(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $v = Database::one(
            "SELECT v.*, u.full_name, u.position, d.name AS dept_name FROM vacation_requests v
               JOIN users u ON u.id = v.employee_id LEFT JOIN departments d ON d.id = u.department_id
              WHERE v.id = ? AND v.status = 'approved'", [$id]);
        if (!$v) { flash('Уведомление доступно только для утверждённого отпуска.', 'error'); $this->redirect('/vacations'); }
        $canIssue = $this->isApprover($me) || ($v['dept_name'] && $this->headDeptId((int) $me['id']));
        if (!$canIssue && (int) $v['employee_id'] !== (int) $me['id']) {
            flash('Нет прав.', 'error'); $this->redirect('/vacations');
        }
        $this->view('vacations/notice', ['title' => 'Уведомление об отпуске', 'v' => $v, 'canIssue' => $canIssue], false);
    }

    public function sendNotice(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isApprover($me) && !$this->headDeptId((int) $me['id'])) { flash('Нет прав.', 'error'); $this->redirect('/vacations'); }
        $v = Database::one("SELECT * FROM vacation_requests WHERE id = ? AND status='approved'", [$id]);
        if ($v) {
            Database::run('UPDATE vacation_requests SET notified_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
            NotificationService::create((int) $v['employee_id'], 'УВЕДОМЛЕНИЕ об отпуске',
                "Уведомляем о начале ежегодного оплачиваемого отпуска с {$v['start_date']} по {$v['end_date']} ({$v['days']} календ. дн.). Основание: график отпусков.");
            flash('Электронное уведомление направлено сотруднику.');
        }
        $this->redirect('/vacations/' . $id . '/notice');
    }

    // ---- запретные зоны (руководитель/админ/менеджер) ----
    public function storeBlackout(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isApprover($me) && !$this->headDeptId((int) $me['id'])) { flash('Нет прав.', 'error'); $this->redirect('/vacations'); }
        $s = (string) $this->input('start_date'); $e = (string) $this->input('end_date');
        if (!$s || !$e || $e < $s) { flash('Некорректные даты зоны.', 'error'); $this->redirect('/vacations'); }
        Database::insert(
            'INSERT INTO vacation_blackouts (department_id, employee_id, start_date, end_date, reason) VALUES (?,?,?,?,?)',
            [$this->input('department_id') ?: null, $this->input('employee_id') ?: null, $s, $e, trim((string) $this->input('reason'))]
        );
        flash('Запретная зона добавлена — заявки на эти даты будут отклоняться автоматически.');
        $this->redirect('/vacations');
    }

    public function deleteBlackout(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isApprover($me) && !$this->headDeptId((int) $me['id'])) { flash('Нет прав.', 'error'); $this->redirect('/vacations'); }
        Database::run('DELETE FROM vacation_blackouts WHERE id = ?', [$id]);
        flash('Зона удалена.');
        $this->redirect('/vacations');
    }

    private function notifyApprovers(string $text): void
    {
        foreach (Database::all("SELECT id FROM users WHERE role IN ('admin','manager') AND is_active=1") as $u) {
            NotificationService::create((int) $u['id'], 'Отпуска: на утверждение', $text);
        }
    }
}
