<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Controllers\AttendanceController;
use App\Controllers\VisaController;
use App\Controllers\OrderController;
use App\Controllers\DocumentController;
use App\Controllers\StimulusController;
use App\Controllers\VacationCampaignController;
use App\Controllers\AppealController;
use App\Controllers\ChatController;

/**
 * Данные главной страницы-дашборда «Корпоративного портала»:
 *   - карточки по подсистемам (проектам), где участвует пользователь, со счётчиком «в работе»;
 *   - корзины задач (как на бывшем «Рабочем столе»): на подписи/согласовании/контроле/исполнении;
 *   - сводка чата и уведомлений;
 *   - состояние рабочего дня (явка) — только для тех, кто её ведёт.
 *
 * Переиспользует готовые счётчики inboxCount() контроллеров и SQL из DeskController.
 */
class DashboardService
{
    public static function forUser(int $uid): array
    {
        $role = Auth::role();
        // Кнопку «Приступить к работе» показываем ВСЕМ вошедшим (по требованию). Открытие дня засчитывает явку в табель.
        $tracksAttendance = Auth::check();
        // Расчётный листок — только сдельщикам и админу (остальным он неинформативен).
        $worksPiece = $role === 'employee' && Auth::has('anketa_worker', 'visa_worker', 'piecework_worker');

        return [
            'tracksAttendance' => $tracksAttendance,
            'showPayslip'      => $worksPiece || Auth::isAdmin(),
            'workday'          => AttendanceController::today($uid),
            'cards'            => self::cards($uid),
            'tasks'            => self::tasks($uid),
            'chat'             => ['unread' => ChatController::unreadCount($uid), 'recent' => ChatController::recent($uid, 5)],
            'notifs'           => [
                'unread' => NotificationService::unreadCount($uid),
                'recent' => Database::all('SELECT id, title, body, is_read, created_at FROM notifications WHERE employee_id = ? ORDER BY id DESC LIMIT 5', [$uid]),
            ],
        ];
    }

    /** Карточки подсистем, в которых участвует пользователь (показываются даже при счётчике 0). */
    private static function cards(int $uid): array
    {
        $cards = [];
        $scalar = fn(string $sql, array $p = []) => (int) Database::scalar($sql, $p);

        // --- Визы ---
        if (Auth::has('visa_worker')) {
            $cards[] = self::card('visa', '🛂', 'Визы на проверку', VisaController::inboxCount($uid), '/visas', 'назначено мне, не проверено');
        }
        if (Auth::has('visa_manager')) {
            $n = $scalar('SELECT COUNT(*) FROM visa_rows WHERE assigned_to IS NULL');
            $cards[] = self::card('visa_mgr', '🛂', 'Визы: распределить', $n, '/visas/manage', 'строк без специалиста');
        }

        // --- Анкеты / квота ---
        if (Auth::has('anketa_worker')) {
            $n = $scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to = ? AND checked_at IS NULL', [$uid]);
            $cards[] = self::card('anketa', '🗂', 'Анкеты на проверку', $n, '/dossiers', 'назначено мне, не проверено');
        }
        if (Auth::has('anketa_manager')) {
            $n = $scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to IS NULL');
            $cards[] = self::card('anketa_mgr', '🗂', 'Анкеты: распределить', $n, '/manager', 'досье без специалиста');
        }
        if (Auth::has('controller')) {
            $cards[] = self::card('inspect', '🔎', 'Контроль анкет', null, '/inspect', 'выборочная проверка');
        }

        // --- Поручения и документы доступны всем пользователям ---
        $cards[] = self::card('orders', '📌', 'Поручения', OrderController::inboxCount($uid), '/orders', 'на исполнении у меня');
        $cards[] = self::card('docs', '📄', 'Документы', DocumentController::inboxCount($uid), '/docs', 'на подписи/согласовании');

        // --- Служебки о стимуле ---
        if (Auth::has('dept_head', 'deputy_director', 'director', 'accountant')) {
            $cards[] = self::card('memos', '💸', 'Служебки о стимуле', StimulusController::inboxCount($uid), '/memos', 'ждут моего действия');
        }

        // --- Отпуска (кампания): мои заявки на изменение графика + те, что жду решить сам ---
        if (Auth::role() === 'employee' || Auth::has('dept_head', 'deputy_director', 'hr', 'hr_manager', 'director')) {
            $my = $scalar("SELECT COUNT(*) FROM vacation_change_requests WHERE employee_id = ? AND status = 'pending'", [$uid]);
            $n = VacationCampaignController::changeRequestsInboxCount($uid) + (int) $my;
            $cards[] = self::card('vacations', '🏖', 'Отпуска', $n, '/vacation-campaign/booking', 'заявки на изменение графика');
        }

        // --- Табели ---
        if (Auth::has('timekeeper', 'dept_head', 'hr', 'accountant')) {
            $n = $scalar("SELECT COUNT(*) FROM tabels WHERE status = 'draft'");
            $cards[] = self::card('tabels', '📅', 'Табели', $n, '/timesheet2', 'черновики табеля');
        }

        // --- Обращения граждан ---
        if (Auth::has('docs_manager') || AppealController::inboxCount($uid) > 0 || Auth::isAdmin()) {
            $cards[] = self::card('appeals', '📨', 'Обращения граждан', AppealController::inboxCount($uid), '/appeals', 'на исполнении у меня');
        }

        return $cards;
    }

    private static function card(string $key, string $icon, string $title, ?int $count, string $url, string $hint): array
    {
        return ['key' => $key, 'icon' => $icon, 'title' => $title, 'count' => $count, 'url' => $url, 'hint' => $hint];
    }

    /** Корзины задач СЭД/поручений (логика перенесена из DeskController). */
    private static function tasks(int $uid): array
    {
        $buckets = [];

        $docByStage = fn(string $stage) => Database::all(
            "SELECT doc.id, doc.title, doc.reg_number FROM documents doc
               JOIN doc_approvers a ON a.document_id=doc.id AND a.step_no=doc.current_step AND a.user_id=? AND a.status='pending' AND a.stage_type=?
              WHERE doc.status='on_approval' ORDER BY doc.sent_at DESC LIMIT 8", [$uid, $stage]);

        $docMap = fn($r) => ['/docs/' . $r['id'], trim(($r['reg_number'] ?: '') . ' ' . $r['title'])];
        foreach ([['sign', '🖋 На подписи'], ['approve', '✍ На согласовании'], ['ack', '👁 На ознакомлении']] as [$stage, $label]) {
            $rows = $docByStage($stage);
            if ($rows) { $buckets[] = self::bucket($label, '/docs?folder=inbox', $rows, $docMap); }
        }

        $exec = Database::all(
            "SELECT id, title, due_date FROM orders WHERE assignee_id=? AND status IN ('new','work') ORDER BY due_date IS NULL, due_date LIMIT 8", [$uid]);
        if ($exec) {
            $buckets[] = self::bucket('📌 На исполнении (поручения)', '/orders', $exec,
                fn($r) => ['/orders/' . $r['id'], $r['title'] . ($r['due_date'] ? ' — до ' . $r['due_date'] : '')]);
        }

        $ctlOrders = Database::all(
            "SELECT id, title, due_date FROM orders WHERE on_control=1 AND status IN ('new','work','review') AND (author_id=? OR controller_id=?) ORDER BY due_date IS NULL, due_date LIMIT 8", [$uid, $uid]);
        $ctlDocs = Database::all(
            "SELECT id, title, reg_number, control_due FROM documents WHERE on_control=1 AND (author_id=? OR controller_id=?) ORDER BY control_due IS NULL, control_due LIMIT 8", [$uid, $uid]);
        $ctl = [];
        foreach ($ctlOrders as $r) { $ctl[] = ['/orders/' . $r['id'], '📌 ' . $r['title'] . ($r['due_date'] ? ' — ' . $r['due_date'] : '')]; }
        foreach ($ctlDocs as $r)   { $ctl[] = ['/docs/' . $r['id'], '📄 ' . ($r['reg_number'] ?: '') . ' ' . $r['title'] . ($r['control_due'] ? ' — ' . $r['control_due'] : '')]; }
        if ($ctl) { $buckets[] = ['label' => '🔍 На контроле', 'link' => '/orders?tab=control', 'count' => count($ctlOrders) + count($ctlDocs), 'items' => array_slice($ctl, 0, 10)]; }

        return $buckets;
    }

    private static function bucket(string $label, string $link, array $rows, callable $map): array
    {
        $items = [];
        foreach ($rows as $r) { [$href, $text] = $map($r); $items[] = [$href, trim($text)]; }
        return ['label' => $label, 'link' => $link, 'count' => count($rows), 'items' => $items];
    }
}
