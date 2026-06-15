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
}
