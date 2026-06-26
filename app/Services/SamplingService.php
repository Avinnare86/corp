<?php
namespace App\Services;

use App\Core\Database;

class SamplingService
{
    /**
     * Сформировать выборку на проверку за указанный рабочий день.
     * Для каждого специалиста берётся N% его досье (минимум 1), случайно.
     * Возвращает id пакета (batch). Идемпотентно: если пакет за день уже есть — возвращает его.
     */
    public static function generateForDate(string $workDate, int $controllerId): int
    {
        $existing = Database::one('SELECT * FROM sample_batches WHERE work_date = ?', [$workDate]);
        if ($existing) {
            return (int) $existing['id'];
        }

        $percent = Settings::inspectionPercent();
        $batchId = Database::insert(
            'INSERT INTO sample_batches (work_date, controller_id) VALUES (?,?)',
            [$workDate, $controllerId]
        );

        // Специалисты, проверившие анкеты в этот день.
        $employees = Database::all(
            "SELECT DISTINCT ai.assigned_to AS employee_id
               FROM assignment_items ai
               JOIN users u ON u.id = ai.assigned_to AND u.role = 'employee'
              WHERE ai.checked_at IS NOT NULL AND substr(ai.checked_at,1,10) = ?",
            [$workDate]
        );

        foreach ($employees as $row) {
            $empId = (int) $row['employee_id'];
            $dossiers = Database::all(
                "SELECT id FROM assignment_items WHERE assigned_to = ? AND substr(checked_at,1,10) = ?",
                [$empId, $workDate]
            );
            $ids = array_map(fn($r) => (int) $r['id'], $dossiers);
            $total = count($ids);
            if ($total === 0) {
                continue;
            }
            $take = (int) max(1, ceil($total * $percent / 100));
            $take = min($take, $total);

            shuffle($ids);
            $picked = array_slice($ids, 0, $take);

            foreach ($picked as $dId) {
                Database::insert(
                    'INSERT INTO inspections (batch_id, dossier_id, employee_id) VALUES (?,?,?)',
                    [$batchId, $dId, $empId]
                );
            }
        }

        // ПОВТОРНЫЕ проверки (после брака): попадают в выборку ОБЯЗАТЕЛЬНО,
        // вне зависимости от дня проверки и СВЕРХ квоты.
        $rechecks = Database::all(
            "SELECT ai.id, ai.assigned_to FROM assignment_items ai
              WHERE ai.recheck = 1 AND ai.checked_at IS NOT NULL AND ai.assigned_to IS NOT NULL
                AND ai.id NOT IN (SELECT dossier_id FROM inspections)"
        );
        foreach ($rechecks as $r) {
            Database::insert(
                'INSERT INTO inspections (batch_id, dossier_id, employee_id) VALUES (?,?,?)',
                [$batchId, (int) $r['id'], (int) $r['assigned_to']]
            );
        }

        return $batchId;
    }

    /** Вчерашняя дата (по умолчанию объект проверки — прошлый день). */
    public static function yesterday(): string
    {
        return date('Y-m-d', strtotime('-1 day'));
    }

    /**
     * Даты, по которым специалисты проверяли анкеты, но выборка контроля ещё НЕ сформирована.
     * @return array<int,array{d:string,cnt:int}> [{дата, число проверенных анкет за день}], по возрастанию даты.
     */
    public static function unsampledDates(): array
    {
        return array_map(
            fn($r) => ['d' => (string) $r['d'], 'cnt' => (int) $r['cnt']],
            Database::all(
                "SELECT substr(ai.checked_at,1,10) AS d, COUNT(*) AS cnt
                   FROM assignment_items ai
                   JOIN users u ON u.id = ai.assigned_to AND u.role = 'employee'
                  WHERE ai.checked_at IS NOT NULL
                    AND substr(ai.checked_at,1,10) NOT IN (SELECT work_date FROM sample_batches)
                  GROUP BY substr(ai.checked_at,1,10)
                  ORDER BY d"
            )
        );
    }
}
