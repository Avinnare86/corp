<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\Org;

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

    /** Виды поручений (МосЭДО). */
    public const KIND = [
        'order'   => 'Поручение',
        'control' => 'Контрольное поручение',
        'request' => 'Запрос информации',
        'info'    => 'К сведению',
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
        $kind = (string) $this->input('kind');
        $kind = isset(self::KIND[$kind]) ? $kind : 'order';
        $onControl = $kind === 'control' ? 1 : 0;
        $oid = Database::insert(
            'INSERT INTO orders (author_id, assignee_id, title, body, due_date, parent_id, doc_id, kind, on_control, controller_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$me['id'], $assignee, $title, (string) $this->input('body'), $this->input('due_date') ?: null,
             $parentId, $parentId ? (Database::scalar('SELECT doc_id FROM orders WHERE id=?', [$parentId]) ?: null) : null,
             $kind, $onControl, $onControl ? $me['id'] : null]
        );
        OrderService::event($oid, 'created', 'исполнитель: ' . (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$assignee]));
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
        $isCo = in_array($uid, array_map(fn($c)=>(int)$c['user_id'], $cos), true);
        $isAuthor = $uid === (int)$o['author_id'];
        $isPrivileged = in_array($me['role'], ['admin','manager'], true);
        $this->view('orders/show', [
            'title' => 'Поручение',
            'o' => $o,
            'cos' => $cos,
            'reports' => Database::all('SELECT r.*, u.full_name FROM order_reports r JOIN users u ON u.id=r.user_id WHERE r.order_id = ? ORDER BY r.id', [$id]),
            'children' => Database::all('SELECT o.*, ue.full_name AS assignee_name FROM orders o JOIN users ue ON ue.id=o.assignee_id WHERE o.parent_id = ? ORDER BY o.id', [$id]),
            'dueLog' => Database::all('SELECT * FROM order_due_log WHERE order_id = ? ORDER BY id', [$id]),
            'events' => Database::all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id DESC', [$id]),
            'meId' => $uid,
            'isAuthor' => $isAuthor,
            'isAssignee' => $uid === (int)$o['assignee_id'],
            'isCo' => $isCo,
            'myCo' => $isCo ? (Database::one('SELECT * FROM order_coexecutors WHERE order_id=? AND user_id=?', [$id, $uid]) ?: null) : null,
            'isPrivileged' => $isPrivileged,
            'canReassign' => $isAuthor || $isPrivileged || Org::canOverseeUser($uid, (int)$o['assignee_id']),
            'coexecAllDone' => OrderService::coexecAllDone((int)$id),
            'eventLabels' => OrderService::EVENTS,
            'kindLabels' => self::KIND,
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
               AND (last_remind_at IS NULL OR substr(" . Database::txt('last_remind_at') . ",1,10) < ?)", [$today]);
        $soon = 0; $over = 0;
        foreach ($rows as $o) {
            $daysLeft = (strtotime($o['due_date']) - strtotime($today)) / 86400;
            if ($daysLeft < 0) {
                NotificationService::create((int) $o['assignee_id'], '⚠ Поручение просрочено', "«{$o['title']}» — срок был {$o['due_date']}. Исполните и отчитайтесь.");
                NotificationService::create((int) $o['author_id'], '⚠ Просрочка по поручению', "«{$o['title']}» (исполнитель) просрочено с {$o['due_date']}.");
                OrderService::escalateOverdue($o); // эскалация начальникам исполнителя
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

    /** Действия по поручению: приём/отчёт, соисполнитель, продление, переадресация, контроль, приёмка/возврат/снятие. */
    public function action(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $o = Database::one('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o) { $this->redirect('/orders'); }
        $act = (string) $this->input('act');
        $now = date('Y-m-d H:i:s');
        $isPriv = in_array(Auth::role(), ['admin', 'manager'], true);
        $isAuthor = (int) $o['author_id'] === $uid;

        $isCo = (bool) Database::scalar('SELECT 1 FROM order_coexecutors WHERE order_id=? AND user_id=?', [$id, $uid]);

        if ($act === 'accept' && (int) $o['assignee_id'] === $uid && $o['status'] === 'new') {
            Database::run("UPDATE orders SET status='work' WHERE id=?", [$id]);
            OrderService::event($id, 'accepted');
        } elseif ($act === 'interim' && ((int) $o['assignee_id'] === $uid || $isCo) && in_array($o['status'], ['new', 'work', 'review'], true)) {
            $text = trim((string) $this->input('report'));
            if ($text !== '') {
                Database::insert("INSERT INTO order_reports (order_id, user_id, kind, text) VALUES (?,?,'interim',?)", [$id, $uid, $text]);
                OrderService::event($id, 'interim', $text);
                NotificationService::create((int) $o['author_id'], 'Промежуточный отчёт', "«{$o['title']}»: {$text}");
                flash('Промежуточный отчёт добавлен.');
            }
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'coexec_report' && $isCo) {
            // соисполнитель закрывает свою часть; ответственный собирает итог
            $text = trim((string) $this->input('report'));
            Database::run("UPDATE order_coexecutors SET status='done', report=?, done_at=? WHERE order_id=? AND user_id=?", [$text, $now, $id, $uid]);
            OrderService::event($id, 'coexec_done', $text !== '' ? $text : null);
            NotificationService::create((int) $o['assignee_id'], 'Соисполнитель закрыл свою часть',
                "«{$o['title']}»: " . ($_SESSION['name'] ?? '') . ($text !== '' ? " — {$text}" : ''));
            flash('Ваша часть отмечена исполненной.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'ext_request' && ((int) $o['assignee_id'] === $uid || $isCo) && !in_array($o['status'], ['done', 'canceled'], true)) {
            // исполнитель/соисполнитель просит продлить срок
            $date = (string) $this->input('new_date');
            $reason = trim((string) $this->input('reason'));
            if ($date && $reason !== '') {
                Database::run('UPDATE orders SET ext_req_date=?, ext_req_reason=?, ext_req_by=?, ext_req_at=? WHERE id=?', [$date, $reason, $uid, $now, $id]);
                OrderService::event($id, 'ext_requested', "до {$date}: {$reason}");
                NotificationService::create((int) $o['author_id'], 'Запрос продления срока', "«{$o['title']}»: просят продлить до {$date} ({$reason}).");
                flash('Запрос на продление отправлен автору.');
            } else { flash('Укажите желаемый срок и обоснование.', 'error'); }
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'ext_approve' && $isAuthor && !empty($o['ext_req_date'])) {
            Database::insert('INSERT INTO order_due_log (order_id, old_date, new_date, reason, user_name) VALUES (?,?,?,?,?)',
                [$id, $o['due_date'], $o['ext_req_date'], 'Продление по запросу: ' . (string) $o['ext_req_reason'], $_SESSION['name'] ?? '']);
            Database::run('UPDATE orders SET due_date=?, ext_req_date=NULL, ext_req_reason=NULL, ext_req_by=NULL, ext_req_at=NULL WHERE id=?', [$o['ext_req_date'], $id]);
            OrderService::event($id, 'ext_approved', 'новый срок ' . $o['ext_req_date']);
            NotificationService::create((int) $o['ext_req_by'], 'Продление согласовано', "«{$o['title']}»: новый срок {$o['ext_req_date']}.");
            flash('Продление согласовано, срок обновлён.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'ext_reject' && $isAuthor && !empty($o['ext_req_date'])) {
            $reason = trim((string) $this->input('comment'));
            Database::run('UPDATE orders SET ext_req_date=NULL, ext_req_reason=NULL, ext_req_by=NULL, ext_req_at=NULL WHERE id=?', [$id]);
            OrderService::event($id, 'ext_rejected', $reason !== '' ? $reason : null);
            NotificationService::create((int) $o['ext_req_by'], 'Продление отклонено', "«{$o['title']}»: продление отклонено" . ($reason !== '' ? " — {$reason}" : '') . '.');
            flash('Запрос на продление отклонён.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'reassign' && ($isAuthor || $isPriv || Org::canOverseeUser($uid, (int) $o['assignee_id'])) && !in_array($o['status'], ['done', 'canceled'], true)) {
            $to = (int) $this->input('assignee_id');
            $reason = trim((string) $this->input('reason'));
            if ($to && $to !== (int) $o['assignee_id'] && Database::scalar('SELECT 1 FROM users WHERE id=? AND is_active=1', [$to])) {
                $fromName = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [(int) $o['assignee_id']]);
                $toName = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$to]);
                Database::run("UPDATE orders SET assignee_id=?, status=CASE WHEN status='review' THEN 'work' ELSE status END WHERE id=?", [$to, $id]);
                OrderService::event($id, 'reassigned', "от {$fromName} → {$toName}" . ($reason !== '' ? ": {$reason}" : ''));
                NotificationService::create($to, 'Вам переадресовано поручение', "«{$o['title']}»" . ($reason !== '' ? " ({$reason})" : ''));
                NotificationService::create((int) $o['assignee_id'], 'Поручение переадресовано', "«{$o['title']}» передано: {$toName}.");
                flash('Поручение переадресовано: ' . $toName . '.');
            } else { flash('Выберите другого исполнителя.', 'error'); }
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'postpone' && ($isAuthor || $isPriv) && !in_array($o['status'], ['done', 'canceled'], true)) {
            $newDate = (string) $this->input('new_date');
            $reason = trim((string) $this->input('reason'));
            if ($newDate && $reason !== '') {
                Database::insert('INSERT INTO order_due_log (order_id, old_date, new_date, reason, user_name) VALUES (?,?,?,?,?)',
                    [$id, $o['due_date'], $newDate, $reason, $_SESSION['name'] ?? '']);
                Database::run('UPDATE orders SET due_date=? WHERE id=?', [$newDate, $id]);
                OrderService::event($id, 'postponed', "{$o['due_date']} → {$newDate}: {$reason}");
                NotificationService::create((int) $o['assignee_id'], 'Срок поручения перенесён', "«{$o['title']}»: новый срок {$newDate} ({$reason})");
                flash('Срок перенесён.');
            } else { flash('Укажите новую дату и причину переноса.', 'error'); }
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'report' && (int) $o['assignee_id'] === $uid && in_array($o['status'], ['new', 'work'], true)) {
            $text = trim((string) $this->input('report'));
            Database::run("UPDATE orders SET status='review', report=? WHERE id=?", [$text, $id]);
            if ($text !== '') { Database::insert("INSERT INTO order_reports (order_id, user_id, kind, text) VALUES (?,?,'final',?)", [$id, $uid, $text]); }
            OrderService::event($id, 'reported', $text !== '' ? $text : null);
            NotificationService::create((int) $o['author_id'], 'Поручение исполнено', "«{$o['title']}» — итоговый отчёт ждёт проверки.");
        } elseif ($act === 'control_on' && ($isAuthor || $isPriv) && !in_array($o['status'], ['done', 'canceled'], true)) {
            $rd = max(0, (int) $this->input('remind_days', 3));
            Database::run("UPDATE orders SET on_control=1, controller_id=?, control_off_at=NULL, control_result=NULL, remind_days=? WHERE id=?", [$uid, $rd, $id]);
            OrderService::event($id, 'control_on');
            NotificationService::create((int) $o['assignee_id'], 'Поручение на контроле', "«{$o['title']}» поставлено на контроль" . ($o['due_date'] ? ", срок {$o['due_date']}" : '') . '.');
            flash('Поручение поставлено на контроль.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'control_off' && ($isAuthor || $isPriv) && (int) $o['on_control'] === 1) {
            Database::run("UPDATE orders SET on_control=0, control_off_at=? WHERE id=?", [$now, $id]);
            OrderService::event($id, 'control_off');
            flash('Снято с контроля.');
            $this->redirect('/orders/' . $id);
        } elseif ($act === 'accept_done' && $isAuthor && $o['status'] === 'review') {
            // при завершении на контроле фиксируем результат (в срок / с нарушением)
            $result = null;
            if ((int) $o['on_control'] === 1) {
                $result = ($o['due_date'] && substr($now, 0, 10) > $o['due_date']) ? 'violated' : 'in_time';
            }
            Database::run("UPDATE orders SET status='done', done_at=?, control_result=?, control_off_at=CASE WHEN on_control=1 THEN ? ELSE control_off_at END WHERE id=?", [$now, $result, $now, $id]);
            OrderService::event($id, 'accepted_done', $result === 'violated' ? 'с нарушением срока' : ($result === 'in_time' ? 'в срок' : null));
            if (!empty($o['doc_id'])) { OrderService::syncDocControl((int) $o['doc_id']); }
            NotificationService::create((int) $o['assignee_id'], 'Поручение принято', "«{$o['title']}» принято руководителем." . ($result === 'violated' ? ' (исполнено с нарушением срока)' : ($result === 'in_time' ? ' (в срок)' : '')));
        } elseif ($act === 'return' && $isAuthor && $o['status'] === 'review') {
            Database::run("UPDATE orders SET status='work' WHERE id=?", [$id]);
            OrderService::event($id, 'returned', trim((string) $this->input('comment')) ?: null);
            NotificationService::create((int) $o['assignee_id'], 'Поручение возвращено', "«{$o['title']}» возвращено на доработку: " . trim((string) $this->input('comment')));
        } elseif ($act === 'cancel' && $isAuthor && !in_array($o['status'], ['done', 'canceled'], true)) {
            Database::run("UPDATE orders SET status='canceled' WHERE id=?", [$id]);
            OrderService::event($id, 'canceled');
            if (!empty($o['doc_id'])) { OrderService::syncDocControl((int) $o['doc_id']); }
            NotificationService::create((int) $o['assignee_id'], 'Поручение снято', "«{$o['title']}» снято руководителем.");
        }
        $this->redirect('/orders/' . $id);
    }
}
