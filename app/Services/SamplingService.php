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
        $existing = Database::one('SELECT * FROM sample_batches WHERE work_date = ? AND COALESCE(is_manual,0) = 0', [$workDate]);
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
                // work_date приводим к строке (в PG это date, substr(checked_at) — text; иначе «text = date»).
                "SELECT substr(ai.checked_at,1,10) AS d, COUNT(*) AS cnt
                   FROM assignment_items ai
                   JOIN users u ON u.id = ai.assigned_to AND u.role = 'employee'
                  WHERE ai.checked_at IS NOT NULL
                    AND substr(ai.checked_at,1,10) NOT IN (SELECT CAST(work_date AS VARCHAR) FROM sample_batches)
                  GROUP BY substr(ai.checked_at,1,10)
                  ORDER BY d"
            )
        );
    }

    /**
     * Кандидаты для РУЧНОЙ выборки контроля: проверенные анкеты по фильтрам (период/специалист/страна),
     * с пометкой, контролировалась ли анкета ранее. Для экрана выбора контролёром.
     */
    public static function manualCandidates(string $from, string $to, ?int $empId, string $country, int $limit = 500): array
    {
        $where = "ai.checked_at IS NOT NULL AND ai.assigned_to IS NOT NULL AND u.role = 'employee'";
        $p = [];
        if ($from !== '')    { $where .= ' AND ai.checked_at >= ?'; $p[] = $from . ' 00:00:00'; }
        if ($to !== '')      { $where .= ' AND ai.checked_at <= ?'; $p[] = $to . ' 23:59:59'; }
        if ($empId)          { $where .= ' AND ai.assigned_to = ?'; $p[] = $empId; }
        if ($country !== '') { $where .= ' AND ai.country_code = ?'; $p[] = $country; }
        return Database::all(
            "SELECT ai.id, ai.reg_number, ai.country_code, substr(ai.checked_at,1,10) AS checked_day,
                    u.full_name AS employee_name,
                    (SELECT COUNT(*) FROM inspections i WHERE i.dossier_id = ai.id) AS inspected
               FROM assignment_items ai
               JOIN users u ON u.id = ai.assigned_to
              WHERE $where
              ORDER BY ai.checked_at DESC, u.full_name
              LIMIT " . (int) $limit,
            $p
        );
    }

    /**
     * РУЧНАЯ выборка контроля (вне привязки к дате): контролёр сам выбирает анкеты.
     * Создаёт пакет is_manual=1 и добавляет инспекции по выбранным досье (пропуская те, что уже
     * в НЕзавершённой выборке). Отработка (вердикт/штраф/повторная проверка) — по общей логике.
     * @param int[] $dossierIds
     * @return array{0:int,1:int} [batchId, добавлено]
     */
    public static function createManualBatch(int $controllerId, string $title, array $dossierIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $dossierIds))));
        if (!$ids) { return [0, 0]; }
        $title = trim($title) !== '' ? trim($title) : ('Ручная выборка от ' . date('d.m.Y H:i'));
        $batchId = Database::insert(
            'INSERT INTO sample_batches (work_date, controller_id, is_manual, title) VALUES (?,?,1,?)',
            [date('Y-m-d'), $controllerId, $title]
        );
        $added = 0;
        foreach ($ids as $did) {
            $ai = Database::one('SELECT id, assigned_to FROM assignment_items WHERE id=? AND checked_at IS NOT NULL AND assigned_to IS NOT NULL', [$did]);
            if (!$ai) { continue; }
            // dossier_id в inspections — UNIQUE: анкета инспектируется лишь раз. Уже инспектированную
            // (в любой выборке, завершённой или нет) пропускаем — иначе INSERT упадёт на UNIQUE.
            // Повторный контроль брака идёт через recheck-копию с новым dossier_id, а не по той же анкете.
            if (Database::scalar('SELECT 1 FROM inspections WHERE dossier_id=?', [$did])) { continue; }
            Database::insert('INSERT INTO inspections (batch_id, dossier_id, employee_id) VALUES (?,?,?)', [$batchId, $did, (int) $ai['assigned_to']]);
            $added++;
        }
        if ($added === 0) { Database::run('DELETE FROM sample_batches WHERE id=?', [$batchId]); return [0, 0]; }
        return [$batchId, $added];
    }
}
