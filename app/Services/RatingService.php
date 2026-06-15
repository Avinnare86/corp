<?php
namespace App\Services;

use App\Core\Database;

class RatingService
{
    /**
     * Рейтинг специалистов за период.
     * Количество — число досье; качество — доля корректных среди проверенных анкет.
     * Сортировка: по качеству, затем по количеству.
     */
    public static function ranking(string $period): array
    {
        $rows = Database::all(
            "SELECT u.id, u.full_name, u.position,
                    COALESCE(cnt.dossiers, 0)  AS dossiers,
                    COALESCE(chk.checked, 0)   AS checked,
                    COALESCE(chk.errors, 0)    AS errors
               FROM users u
               LEFT JOIN (
                    SELECT assigned_to AS employee_id, COUNT(*) AS dossiers
                      FROM assignment_items
                     WHERE checked_at IS NOT NULL AND substr(checked_at,1,7) = ?
                     GROUP BY assigned_to
               ) cnt ON cnt.employee_id = u.id
               LEFT JOIN (
                    SELECT i.employee_id,
                           COUNT(*) AS checked,
                           SUM(CASE WHEN i.is_correct = 0 THEN 1 ELSE 0 END) AS errors
                      FROM inspections i
                      JOIN assignment_items ai ON ai.id = i.dossier_id
                     WHERE i.is_correct IS NOT NULL
                       AND substr(ai.checked_at,1,7) = ?
                     GROUP BY i.employee_id
               ) chk ON chk.employee_id = u.id
              WHERE u.role = 'employee' AND u.is_active = 1",
            [$period, $period]
        );

        foreach ($rows as &$r) {
            $checked = (int) $r['checked'];
            $errors  = (int) $r['errors'];
            $r['quality'] = $checked > 0 ? round(($checked - $errors) / $checked * 100, 1) : null;
        }
        unset($r);

        usort($rows, function ($a, $b) {
            // Сначала те, у кого есть проверки; по качеству убыв., затем по количеству убыв.
            $qa = $a['quality'] ?? -1;
            $qb = $b['quality'] ?? -1;
            if ($qa !== $qb) {
                return $qb <=> $qa;
            }
            return (int) $b['dossiers'] <=> (int) $a['dossiers'];
        });

        $rank = 1;
        foreach ($rows as &$r) {
            $r['rank'] = $rank++;
        }
        unset($r);

        return $rows;
    }
}
