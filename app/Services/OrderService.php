<?php
namespace App\Services;

use App\Core\Database;

/**
 * Вспомогательная логика поручений (МосЭДО): лента событий, эскалация просрочки вверх по
 * иерархии, синхронизация контроля документа, готовность соисполнителей.
 */
class OrderService
{
    /** Человекочитаемые названия событий ленты. */
    public const EVENTS = [
        'created'      => 'Создано',
        'accepted'     => 'Принято в работу',
        'interim'      => 'Промежуточный отчёт',
        'coexec_done'  => 'Соисполнитель закрыл свою часть',
        'reported'     => 'Подан итоговый отчёт',
        'accepted_done'=> 'Исполнение принято',
        'returned'     => 'Возвращено на доработку',
        'postponed'    => 'Срок перенесён',
        'ext_requested'=> 'Запрошено продление срока',
        'ext_approved' => 'Продление согласовано',
        'ext_rejected' => 'Продление отклонено',
        'reassigned'   => 'Переадресовано',
        'control_on'   => 'Поставлено на контроль',
        'control_off'  => 'Снято с контроля',
        'canceled'     => 'Снято',
    ];

    /** Запись события в ленту поручения (автор — текущий пользователь сессии). */
    public static function event(int $orderId, string $event, ?string $detail = null): void
    {
        try {
            Database::insert(
                'INSERT INTO order_events (order_id, user_id, user_name, event, detail) VALUES (?,?,?,?,?)',
                [$orderId, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? '', mb_substr($event, 0, 40), $detail]);
        } catch (\Throwable $e) {
            // лента не должна ломать основное действие
        }
    }

    /** Эскалация просрочки: уведомить начальников исполнителя (вверх по иерархии). */
    public static function escalateOverdue(array $order): void
    {
        $assignee = (int) ($order['assignee_id'] ?? 0);
        if (!$assignee) { return; }
        foreach (Org::superiorUserIds($assignee) as $boss) {
            NotificationService::create($boss, '⚠ Просрочка поручения у подчинённого',
                "«{$order['title']}» (исполнитель — id {$assignee}) просрочено с {$order['due_date']}.");
        }
    }

    /** Все ли соисполнители закрыли свою часть (подсказка ответственному перед итоговым отчётом). */
    public static function coexecAllDone(int $orderId): bool
    {
        $total = (int) Database::scalar('SELECT COUNT(*) FROM order_coexecutors WHERE order_id=?', [$orderId]);
        if (!$total) { return true; }
        $open = (int) Database::scalar("SELECT COUNT(*) FROM order_coexecutors WHERE order_id=? AND status<>'done'", [$orderId]);
        return $open === 0;
    }

    /**
     * Связь контроля документа и поручений: если документ на контроле и ВСЕ его поручения
     * закрыты (done/canceled) — снять документ с контроля и уведомить контролёра.
     */
    public static function syncDocControl(int $docId): void
    {
        if (!$docId) { return; }
        $doc = Database::one('SELECT id, title, on_control, controller_id FROM documents WHERE id=?', [$docId]);
        if (!$doc || (int) $doc['on_control'] !== 1) { return; }
        $open = (int) Database::scalar("SELECT COUNT(*) FROM orders WHERE doc_id=? AND status NOT IN ('done','canceled')", [$docId]);
        if ($open > 0) { return; }
        Database::run('UPDATE documents SET on_control=0, control_off_at=? WHERE id=?', [date('Y-m-d H:i:s'), $docId]);
        if (!empty($doc['controller_id'])) {
            NotificationService::create((int) $doc['controller_id'], 'Документ снят с контроля',
                "«{$doc['title']}»: все поручения по документу исполнены.");
        }
    }
}
