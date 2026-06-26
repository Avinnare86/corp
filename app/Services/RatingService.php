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
    public static function ranking(string $period, string $from = '', string $to = ''): array
    {
        // Период: диапазон дат (если задан хотя бы один край) имеет приоритет над месяцем.
        if ($from !== '' || $to !== '') {
            $cntCond = ''; $chkCond = ''; $cntP = []; $chkP = [];
            if ($from !== '') { $cntCond .= ' AND checked_at >= ?'; $chkCond .= ' AND ai.checked_at >= ?'; $cntP[] = $from . ' 00:00:00'; $chkP[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $cntCond .= ' AND checked_at <= ?'; $chkCond .= ' AND ai.checked_at <= ?'; $cntP[] = $to . ' 23:59:59'; $chkP[] = $to . ' 23:59:59'; }
        } else {
            $cntCond = ' AND substr(checked_at,1,7) = ?'; $chkCond = ' AND substr(ai.checked_at,1,7) = ?';
            $cntP = [$period]; $chkP = [$period];
        }
        $rows = Database::all(
            "SELECT u.id, u.full_name, u.position,
                    COALESCE(cnt.dossiers, 0)  AS dossiers,
                    COALESCE(chk.checked, 0)   AS checked,
                    COALESCE(chk.errors, 0)    AS errors
               FROM users u
               LEFT JOIN (
                    SELECT assigned_to AS employee_id, COUNT(*) AS dossiers
                      FROM assignment_items
                     WHERE checked_at IS NOT NULL$cntCond
                     GROUP BY assigned_to
               ) cnt ON cnt.employee_id = u.id
               LEFT JOIN (
                    SELECT i.employee_id,
                           COUNT(*) AS checked,
                           SUM(CASE WHEN i.is_correct = 0 THEN 1 ELSE 0 END) AS errors
                      FROM inspections i
                      JOIN assignment_items ai ON ai.id = i.dossier_id
                     WHERE i.is_correct IS NOT NULL$chkCond
                     GROUP BY i.employee_id
               ) chk ON chk.employee_id = u.id
              WHERE u.role = 'employee' AND u.is_active = 1
                AND EXISTS (SELECT 1 FROM assignment_items ai3 WHERE ai3.assigned_to = u.id)",
            array_merge($cntP, $chkP)
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
