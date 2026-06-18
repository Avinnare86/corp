<?php
namespace App\Services;

use App\Core\Database;

/**
 * Возврат визовой строки на доработку после отказа МИД.
 * Строка уходит в статус «rework», исключается первичный проверяющий (нельзя назначить повторно),
 * сбрасывается зачёт сделки (новую проверку оплатят новому специалисту), фиксируется вычет
 * с первичного проверяющего (стоимость проверки строки ИЛИ 0 — решает менеджер).
 */
class VisaReworkService
{
    /** Стоимость проверки одной визовой строки = цена операции «Виза — этап 2». */
    public static function checkPrice(): float
    {
        $p = Database::scalar("SELECT unit_price FROM operations WHERE name LIKE '%этап 2%' AND is_active=1 ORDER BY id LIMIT 1");
        return $p !== false && $p !== null ? (float) $p : 15.0;
    }

    /**
     * Отклонить строку (отказ МИД) → доработка.
     * @param array  $row    строка visa_rows (минимум id, assigned_to)
     * @param int    $opisId опись, из которой пришёл отказ (0 — без описи)
     * @param float  $amount вычет с первичного проверяющего (0 = не его вина)
     * @param int    $managerId кто решил
     * @param string $note   причина/комментарий отказа
     */
    public static function refuse(array $row, int $opisId, float $amount, int $managerId, string $note): void
    {
        $rowId = (int) $row['id'];
        $checker = isset($row['assigned_to']) ? (int) $row['assigned_to'] : 0;
        $now = date('Y-m-d H:i:s');

        $gridNote = 'Отказ МИД' . ($note !== '' ? ': ' . $note : '');
        Database::run(
            "UPDATE visa_rows
                SET status='rework', recheck=1, source_row_id=id, excluded_user=?,
                    mid_refused_at=?, mid_refuse_note=?, rework_note=?,
                    assigned_to=NULL, checked_at=NULL, opis_id=NULL, credited_at=NULL
              WHERE id=?",
            [$checker ?: null, $now, $note, $gridNote, $rowId]);

        // Вычет с первичного проверяющего (даже нулевой — для аудита), если известен.
        if ($checker) {
            Database::insert(
                'INSERT INTO visa_deductions (row_id, opis_id, employee_id, amount, period, decided_by, reason) VALUES (?,?,?,?,?,?,?)',
                [$rowId, $opisId ?: null, $checker, round($amount, 2), date('Y-m'), $managerId, $note]);
        }
    }

    /**
     * Доработка по сроку действия паспорта (не вина оператора): строка → статус 'rework_pass',
     * возвращается в пул доработки БЕЗ вычета. Оператора не исключаем — он может поправить даты
     * (если есть другой паспорт) и вернуть строку в работу.
     */
    public static function passportRework(int $rowId, int $byUserId): void
    {
        $now = date('Y-m-d H:i:s');
        Database::run(
            "UPDATE visa_rows
                SET status='rework_pass', recheck=1, source_row_id=COALESCE(source_row_id, id),
                    rework_note=?, rework_by=?, rework_at=?,
                    assigned_to=NULL, checked_at=NULL, opis_id=NULL, credited_at=NULL
              WHERE id=?",
            ['Срок действия паспорта', $byUserId ?: null, $now, $rowId]);
        // Вычет НЕ создаётся — это не ошибка проверяющего.
    }

    /**
     * Удалить строку из УЖЕ внесённого визового указания → доработка («повторно»).
     * Равнозначно отказу МИД, но с обязательным комментарием и пометкой об удалении из указания.
     * Сохраняет исходную (отклонённую) строку source_row_id и исключает первичного проверяющего.
     */
    public static function removeFromInstruction(array $row, int $opisId, float $amount, int $managerId, string $comment): void
    {
        $rowId = (int) $row['id'];
        // Исполнитель: текущий assigned_to, иначе ранее исключённый (на instructed-строке исполнитель обычно сохранён).
        $checker = isset($row['assigned_to']) && (int) $row['assigned_to'] > 0
            ? (int) $row['assigned_to']
            : (isset($row['excluded_user']) ? (int) $row['excluded_user'] : 0);
        $now = date('Y-m-d H:i:s');
        $note = 'повторно, удалён из визового указания' . ($comment !== '' ? ': ' . $comment : '');

        Database::run(
            "UPDATE visa_rows
                SET status='rework', recheck=1, source_row_id=COALESCE(source_row_id, id), excluded_user=?,
                    mid_refused_at=?, mid_refuse_note=?, rework_note=?,
                    assigned_to=NULL, checked_at=NULL, opis_id=NULL, credited_at=NULL
              WHERE id=?",
            [$checker ?: null, $now, $note, $note, $rowId]);

        if ($checker) {
            Database::insert(
                'INSERT INTO visa_deductions (row_id, opis_id, employee_id, amount, period, decided_by, reason) VALUES (?,?,?,?,?,?,?)',
                [$rowId, $opisId ?: null, $checker, round($amount, 2), date('Y-m'), $managerId, $note]);
        }
    }
}
