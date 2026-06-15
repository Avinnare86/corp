<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;

/** Поручения руководителя: новое → в работе → на проверке → исполнено / снято. */
class OrderController extends Controller
{
    public const STATUS = [
        'new'      => 'Новое',
        'work'     => 'В работе',
        'review'   => 'На проверке',
        'done'     => 'Исполнено',
        'canceled' => 'Снято',
    ];

    /** Руководитель ли (может давать поручения): глава подразделения, manager, admin, controller? нет. */
    private function isBoss(array $me): bool
    {
        if (in_array($me['role'], ['admin', 'manager'], true)) { return true; }
        return (bool) Database::scalar('SELECT 1 FROM departments WHERE head_id = ?', [$me['id']]);
    }

    public static function inboxCount(int $uid): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM orders WHERE assignee_id = ? AND status IN ('new','work')", [$uid]);
    }

    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $tab = (string) $this->input('tab', 'in');

        $base = "SELECT o.*, ua.full_name AS author_name, ue.full_name AS assignee_name,
                        (SELECT COUNT(*) FROM order_coexecutors c2 WHERE c2.order_id = o.id) AS co_count,
                        (SELECT COUNT(*) FROM orders ch WHERE ch.parent_id = o.id) AS child_count
                   FROM orders o JOIN users ua ON ua.id = o.author_id JOIN users ue ON ue.id = o.assignee_id";
        if ($tab === 'out') {
            $rows = Database::all("$base WHERE o.author_id = ? ORDER BY o.id DESC", [$uid]);
        } elseif ($tab === 'control') {
            // на контроле: поставленные мной (или все — для admin/manager), активные/недавно снятые
            $rows = in_array($me['role'], ['admin', 'manager'], true)
                ? Database::all("$base WHERE o.on_control = 1 OR o.control_result IS NOT NULL ORDER BY o.status IN ('done','canceled'), o.due_date IS NULL, o.due_date, o.id DESC")
                : Database::all("$base WHERE (o.on_control = 1 OR o.control_result IS NOT NULL) AND (o.author_id = ? OR o.controller_id = ?) ORDER BY o.status IN ('done','canceled'), o.due_date IS NULL, o.due_date, o.id DESC", [$uid, $uid]);
        } else {
            $rows = Database::all("$base WHERE o.assignee_id = ? OR o.id IN (SELECT order_id FROM order_coexecutors WHERE user_id = ?)
                 ORDER BY CASE WHEN o.status IN ('new','work','review') THEN 0 ELSE 1 END, o.due_date IS NULL, o.due_date, o.id DESC", [$uid, $uid]);
        }

        // подчинённые для формы (руководителю — его отдел; manager/admin — все)
        $subordinates = [];
        if ($this->isBoss($me)) {
            if (in_array($me['role'], ['admin', 'manager'], true)) {
                $subordinates = Database::all('SELECT id, full_name FROM users WHERE is_active = 1 AND id <> ? ORDER BY full_name', [$uid]);
            } else {
                $subordinates = Database::all(
                    'SELECT u.id, u.full_name FROM users u JOIN departments d ON d.id = u.department_id
                      WHERE d.head_id = ? AND u.is_active = 1 AND u.id <> ? ORDER BY u.full_name', [$uid, $uid]);
            }
        }

        $this->view('orders/index', [
            'title' => 'Поручения',
            'tab' => $tab,
            'rows' => $rows,
            'isBoss' => $this->isBoss($me),
            'subordinates' => $subordinates,
            'meId' => $uid,
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $title = trim((string) $this->input('title'));
        $assignee = (int) $this->input('assignee_id');
        if ($title === '' || !$assignee) { flash('Укажите текст и исполнителя.', 'error'); $this->redirect('/orders?tab=out'); }
        $parentId = $this->input('parent_id') ? (int) $this->input('parent_id') : null;
        if ($parentId) {
            // вложенную резолюцию расписывает ОТВЕТСТВЕННЫЙ исполнитель родительского поручения (право руководителя не требуется)
            $parent = Database::one('SELECT * FROM orders WHERE id = ?', [$parentId]);
            if (!$parent || (int) $parent['assignee_id'] !== (int) $me['id']) { flash('Нет прав на вложенную резолюцию.', 'error'); $this->redirect('/orders'); }
        } elseif (!$this->isBoss($me)) {
            flash('Поручения дают руководители.', 'error');
            $this->redirect('/orders');
        }
        $oid = Database::insert(
            'INSERT INTO orders (author_id, assignee_id, title, body, due_date, parent_id, doc_id) VALUES (?,?,?,?,?,?,?)',
            [$me['id'], $assignee, $title, (string) $this->input('body'), $this->input('due_date') ?: null,
             $parentId, $parentId ? (Database::scalar('SELECT doc_id FROM orders WHERE id=?', [$parentId]) ?: null) : null]
        );
        foreach (array_unique(array_map('intval', $_POST['coexecutors'] ?? [])) as $co) {
            if ($co && $co !== $assignee && Database::scalar('SELECT 1 FROM users WHERE id=? AND is_active=1', [$co])) {
                Database::insert('INSERT INTO order_coexecutors (order_id, user_id) VALUES (?,?)', [$oid, $co]);
                NotificationService::create($co, 'Вы соисполнитель поручения', "«{$title}»");
            }
        }
        NotificationService::create($assignee, 'Новое поручение', "«{$title}»" . ($this->input('due_date') ? ' — срок ' . $this->input('due_date') : ''));
        flash('Поручение дано.');
        $this->redirect($parentId ? '/orders/' . $parentId : '/orders?tab=out');
    }

    /** Карточка поручения: отчёты, соисполнители, вложенные, переносы сроков. */
    public function show(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $o = Database::one(
            "SELECT o.*, ua.full_name AS author_name, ue.full_name AS assignee_name, d.title AS doc_title, d.reg_number AS doc_reg
               FROM orders o JOIN users ua ON ua.id=o.author_id JOIN users ue ON ue.id=o.assignee_id
               LEFT JOIN documents d ON d.id = o.doc_id WHERE o.id = ?", [$id]);
        if (!$o) { $this->redirect('/orders'); }
        $cos = Database::all('SELECT c.*, u.full_name FROM order_coexecutors c JOIN users u ON u.id=c.user_id WHERE c.order_id = ?', [$id]);
        $isParticipant = $uid === (int)$o['author_id'] || $uid === (int)$o['assignee_id']
            || in_array($uid, array_map(fn($c)=>(int)$c['user_id'], $cos), true)
            || in_array($me['role'], ['admin','manager'], true);
        if (!$isParticipant) { flash('Нет доступа к поручению.', 'error'); $this->redirect('/orders'); }
        $this->view('orders/show', [
            'title' => 'Поручение',
            'o' => $o,
            'cos' => $cos,
            'reports' => Database::all('SELECT r.*, u.full_name FROM order_reports r JOIN users u ON u.id=r.user_id WHERE r.order_id = ? ORDER BY r.id', [$id]),
            'children' => Database::all('SELECT o.*, ue.full_name AS assignee_name FROM orders o JOIN users ue ON ue.id=o.assignee_id WHERE o.parent_id = ? ORDER BY o.id', [$id]),
            'dueLog' => Database::all('SELECT * FROM order_due_log WHERE order_id = ? ORDER BY id', [$id]),
            'meId' => $uid,
            'isAuthor' => $uid === (int)$o['author_id'],
            'isAssignee' => $uid === (int)$o['assignee_id'],
            'isCo' => in_array($uid, array_map(fn($c)=>(int)$c['user_id'], $cos), true),
            'isPrivileged' => in_array($me['role'], ['admin','manager'], true),
            'allUsers' => Database::all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name'),
        ]);
    }

    /** Рассылка напоминаний и эскалация по поручениям на контроле (раз в день на поручение). */
    public function remind(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isBoss($me)) { flash('Доступно руководителям.', 'error'); $this->redirect('/orders'); }
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $rows = Database::all(
            "SELECT * FROM orders WHERE on_control=1 AND status IN ('new','work','review') AND due_date IS NOT NULL
               AND (last_remind_at IS NULL OR substr(last_remind_at,1,10) < ?)", [$today]);
        $soon = 0; $over = 0;
        foreach ($rows as $o) {
            $daysLeft = (strtotime($o['due_date']) - strtotime($today)) / 86400;
            if ($daysLeft < 0) {
                NotificationService::create((int) $o['assignee_id'], '⚠ Поручение просрочено', "«{$o['title']}» — срок был {$o['due_date']}. Исполните и отчитайтесь.");
                NotificationService::create((int) $o['author_id'], '⚠ Просрочка по поручению', "«{$o['title']}» (исполнитель) просрочено с {$o['due_date']}.");
                $over++;
            } elseif ($daysLeft <= (int) $o['remind_days']) {
                NotificationService::create((int) $o['assignee_id'], '⏰ Приближается срок поручения', "«{$o['title']}» — срок {$o['due_date']}.");
                $soon++;
            } else { continue; }
            Database::run('UPDATE orders SET last_remind_at=? WHERE id=?', [$now, $o['id']]);
        }
        flash("Напоминания разосланы: приближается срок — {$soon}, просрочено (эскалация автору) — {$over}.");
        $this->redirect('/orders?tab=control');
    }

    /** Отчёт по исполнительской дисциплине. */
    public function report(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isBoss($me)) { flash('Отчёт доступен руководителям.', 'error'); $this->redirect('/orders'); }
        $rows = $this->disciplineRows();
        if ($this->input('export')) {
            \App\Services\Xlsx::download('discipline-' . date('Y-m-d') . '.xlsx', [[
                'name' => 'Исполнительская дисциплина',
                'headers' => ['Исполнитель', 'Всего', 'Исполнено', 'Исполнено в срок', 'Просрочено (в работе)', 'В работе', '% в срок'],
                'rows' => array_map(fn($r) => [$r['name'], $r['total'], $r['done'], $r['done_on_time'], $r['overdue'], $r['active'], $r['pct'] . '%'], $rows),
            ]]);
        }
        $this->view('orders/report', ['title' => 'Исполнительская дисциплина', 'rows' => $rows]);
    }

    private function disciplineRows(): array
    {
        $today = date('Y-m-d');
        $out = [];
        foreach (Database::all("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name") as $u) {
            $t = Database::one(
                "SELECT COUNT(*) total,
                        SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) done,
                        SUM(CASE WHEN status='done' AND (due_date IS NULL OR substr(done_at,1,10) <= " . Database::txt('due_date') . ") THEN 1 ELSE 0 END) done_on_time,
                        SUM(CASE WHEN status IN ('new','work','review') AND due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) overdue,
                        SUM(CASE WHEN status IN ('new','work','review') THEN 1 ELSE 0 END) active
                   FROM orders WHERE assignee_id = ?", [$today, $u['id']]);
            if (!(int) $t['total']) { continue; }
            $out[] = [
                'name' => $u['full_name'], 'total' => (int) $t['total'], 'done' => (int) $t['done'],
                'done_on_time' => (int) $t['done_on_time'], 'overdue' => (int) $t['overdue'], 'active' => (int) $t['active'],
                'pct' => (int) $t['done'] > 0 ? round((int) $t['done_on_time'] / (int) $t['done'] * 100) : 0,
            ];
        }
        return $out;
    }

    /** Действия: исполнитель — accept/report; автор — accept_done/return/cancel. */
    public function action(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $o = Database::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) { $this->redirect('/orders'); }
        $act = (string) $this->input('act');
        $now = date('Y-m-d H:i:s');

        $isCo = (bool) Database::scalar('SELECT 1 FROM order_coexecutors WHERE order_id=? AND user_id=?', [$id, $uid]);

        if ($act === 'accept' && (int) $o['assignee_id'] === $uid && $o['status'] === 'new') {
            Database::run("UPDATE orders SET status='work' WHERE id=?", [$id]);
        } elseif ($act === 'interim' && ((int) $o['assignee_id'] === $uid || $isCo) && in_array($o['status'], ['new', 'work', 'review'], true)) {
            $text = trim((string) $this->input('report'));
            if ($text !== '') {
                Database::insert("INSERT INTO order_reports (order_id, user_id, kind, text) VALUES (?,?,'interim',?)", [$id, $uid, $text]);
                NotificationService::create((int) $o['author_id'], 'Промежуточный отчёт', "«{$o['title']}»: {$text}");
                flash('Промежуточный отчёт добавлен.');
            }
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'postpone' && ((int) $o['author_id'] === $uid || in_array(Auth::role(), ['admin','manager'], true)) && !in_array($o['status'], ['done','canceled'], true)) {
            $newDate = (string) $this->input('new_date');
            $reason = trim((string) $this->input('reason'));
            if ($newDate && $reason !== '') {
                Database::insert('INSERT INTO order_due_log (order_id, old_date, new_date, reason, user_name) VALUES (?,?,?,?,?)',
                    [$id, $o['due_date'], $newDate, $reason, $_SESSION['name'] ?? '']);
                Database::run('UPDATE orders SET due_date=? WHERE id=?', [$newDate, $id]);
                NotificationService::create((int) $o['assignee_id'], 'Срок поручения перенесён', "«{$o['title']}»: новый срок {$newDate} ({$reason})");
                flash('Срок перенесён.');
            } else { flash('Укажите новую дату и причину переноса.', 'error'); }
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'report' && (int) $o['assignee_id'] === $uid && in_array($o['status'], ['new', 'work'], true)) {
            $text = trim((string) $this->input('report'));
            Database::run("UPDATE orders SET status='review', report=? WHERE id=?", [$text, $id]);
            if ($text !== '') { Database::insert("INSERT INTO order_reports (order_id, user_id, kind, text) VALUES (?,?,'final',?)", [$id, $uid, $text]); }
            NotificationService::create((int) $o['author_id'], 'Поручение исполнено', "«{$o['title']}» — итоговый отчёт ждёт проверки.");
        } elseif ($act === 'control_on' && ((int) $o['author_id'] === $uid || in_array(Auth::role(), ['admin','manager'], true)) && !in_array($o['status'], ['done','canceled'], true)) {
            $rd = max(0, (int) $this->input('remind_days', 3));
            Database::run("UPDATE orders SET on_control=1, controller_id=?, control_off_at=NULL, control_result=NULL, remind_days=? WHERE id=?", [$uid, $rd, $id]);
            NotificationService::create((int) $o['assignee_id'], 'Поручение на контроле', "«{$o['title']}» поставлено на контроль" . ($o['due_date'] ? ", срок {$o['due_date']}" : '') . '.');
            flash('Поручение поставлено на контроль.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'control_off' && ((int) $o['author_id'] === $uid || in_array(Auth::role(), ['admin','manager'], true)) && (int) $o['on_control'] === 1) {
            Database::run("UPDATE orders SET on_control=0, control_off_at=? WHERE id=?", [$now, $id]);
            flash('Снято с контроля.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'accept_done' && (int) $o['author_id'] === $uid && $o['status'] === 'review') {
            // при завершении на контроле фиксируем результат (в срок / с нарушением)
            $result = null;
            if ((int) $o['on_control'] === 1) {
                $result = ($o['due_date'] && substr($now, 0, 10) > $o['due_date']) ? 'violated' : 'in_time';
            }
            Database::run("UPDATE orders SET status='done', done_at=?, control_result=?, control_off_at=CASE WHEN on_control=1 THEN ? ELSE control_off_at END WHERE id=?", [$now, $result, $now, $id]);
            NotificationService::create((int) $o['assignee_id'], 'Поручение принято', "«{$o['title']}» принято руководителем." . ($result === 'violated' ? ' (исполнено с нарушением срока)' : ($result === 'in_time' ? ' (в срок)' : '')));
        } elseif ($act === 'return' && (int) $o['author_id'] === $uid && $o['status'] === 'review') {
            Database::run("UPDATE orders SET status='work' WHERE id=?", [$id]);
            NotificationService::create((int) $o['assignee_id'], 'Поручение возвращено', "«{$o['title']}» возвращено на доработку: " . trim((string) $this->input('comment')));
        } elseif ($act === 'cancel' && (int) $o['author_id'] === $uid && !in_array($o['status'], ['done', 'canceled'], true)) {
            Database::run("UPDATE orders SET status='canceled' WHERE id=?", [$id]);
            NotificationService::create((int) $o['assignee_id'], 'Поручение снято', "«{$o['title']}» снято руководителем.");
        }
        $this->redirect('/orders/' . $id);
    }
}
