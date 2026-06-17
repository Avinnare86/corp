<?php
namespace App\Services;

use App\Core\Database;

class PenaltyService
{
    /** Период (YYYY-MM) рабочего дня. */
    public static function periodOf(string $date): string
    {
        return substr($date, 0, 7);
    }

    /**
     * Зафиксировать результат проверки анкеты.
     * При ошибке считает снижение с эскалацией за повторы того же типа у сотрудника в периоде.
     */
    public static function applyReview(array $inspection, bool $isCorrect, ?int $errorTypeId, int $controllerId, ?string $comment = null): void
    {
        $penalty = 0.0;
        $occurrence = 0;

        if (!$isCorrect && $errorTypeId) {
            $base = (float) Database::scalar('SELECT penalty FROM error_types WHERE id = ?', [$errorTypeId]);
            $step = Settings::penaltyStep();          // надбавка за повтор
            $maxMult = Settings::penaltyMaxMultiplier(); // потолок множителя

            $workDate = (string) Database::scalar('SELECT checked_at FROM assignment_items WHERE id = ?', [$inspection['dossier_id']]);
            $period = self::periodOf($workDate);

            // Сколько раз этот тип ошибки уже зафиксирован у сотрудника в ЭТОМ периоде (счётчик сбрасывается помесячно).
            $prior = (int) Database::scalar(
                "SELECT COUNT(*)
                   FROM inspections i
                   JOIN assignment_items ai ON ai.id = i.dossier_id
                  WHERE i.employee_id = ?
                    AND i.error_type_id = ?
                    AND i.is_correct = 0
                    AND i.id <> ?
                    AND substr(ai.checked_at,1,7) = ?",
                [$inspection['employee_id'], $errorTypeId, $inspection['id'], $period]
            );
            $occurrence = $prior + 1;
            // Ступенчатая прогрессия с плато: множитель = min(maxMult, 1 + step*(n-1)).
            // 1-й раз = base; далее дороже, но не выше потолка (прогрессивная дисциплина, Emons/Miceli).
            $mult = min($maxMult, 1 + $step * ($occurrence - 1));
            $penalty = round($base * $mult, 2);
        }

        // Комментарий хранится только у ошибки; для корректной анкеты сбрасывается.
        $commentToStore = (!$isCorrect && $comment !== null && $comment !== '') ? $comment : null;

        Database::run(
            'UPDATE inspections
                SET is_correct = ?, error_type_id = ?, penalty_amount = ?, occurrence = ?,
                    controller_id = ?, reviewed_at = ?, controller_comment = ?
              WHERE id = ?',
            [$isCorrect ? 1 : 0, $isCorrect ? null : $errorTypeId, $penalty, $occurrence, $controllerId, date('Y-m-d H:i:s'), $commentToStore, $inspection['id']]
        );

        // Брак: анкета встаёт в очередь на повторную проверку ДРУГИМ специалистом —
        // копия в пуле менеджера с исключением допустившего ошибку.
        if (!$isCorrect) {
            $item = Database::one('SELECT * FROM assignment_items WHERE id = ?', [$inspection['dossier_id']]);
            if ($item && !Database::scalar('SELECT 1 FROM assignment_items WHERE source_item_id = ?', [$item['id']])) {
                Database::insert(
                    'INSERT INTO assignment_items (list_id, reg_number, country_code, recheck, source_item_id, excluded_user)
                     VALUES (?,?,?,?,?,?)',
                    [$item['list_id'], $item['reg_number'], $item['country_code'], 1, $item['id'], $item['assigned_to']]
                );
            }
        }
    }
}
