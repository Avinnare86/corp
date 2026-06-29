<?php

namespace App\Services;

use App\Core\Database;

/**
 * Кампания по отпускам — многоэтапный сбор предложений на год, питающий документ
 * «График отпусков» ({@see VacationScheduleService}).
 *
 * Этапы: balances (кадры вводят и утверждают остатки) → blackouts (начальники/замы
 * задают запретные периоды) → booking (сотрудники сами вводят даты) → signing
 * (начальники подписывают служебки по отделам → зам → директор → кадры формируют
 * график) → closed.
 *
 * Движок непересечения: лимит одновременно отдыхающих по отделу ({@see vacation_dept_limits})
 * + группы «не более N из набора» ({@see vacation_overlap_groups}); несколько групп
 * действуют совместно (И). Плюс контроль запретных зон ({@see vacation_blackouts}) и
 * остатка отпуска.
 */
class VacationCampaignService
{
    public const STAGES = [
        'balances'  => 'Сбор и утверждение остатков',
        'blackouts' => 'Запретные периоды',
        'booking'   => 'Самозапись дат сотрудниками',
        'signing'   => 'Подписание и формирование графика',
        'closed'    => 'Завершена',
    ];
    private const ORDER = ['balances', 'blackouts', 'booking', 'signing', 'closed'];

    public static function current(int $year): ?array
    {
        return Database::one('SELECT * FROM vacation_campaigns WHERE year = ?', [$year]) ?: null;
    }

    public static function stageOf(int $year): string
    {
        $c = self::current($year);
        return $c ? (string) $c['stage'] : '';
    }

    /** Открыть кампанию года (этап «остатки»). */
    public static function open(int $year, int $by): array
    {
        if (self::current($year)) { return ['ok' => false, 'error' => 'Кампания на ' . $year . ' уже открыта.']; }
        Database::insert('INSERT INTO vacation_campaigns (year, stage, opened_by, opened_at) VALUES (?,?,?,?)',
            [$year, 'balances', $by, date('Y-m-d H:i:s')]);
        Audit::log('vacation_campaign.open', 'Открыта кампания по отпускам на ' . $year);
        return ['ok' => true];
    }

    /** Утвердить остатки и перейти к запретным периодам. */
    public static function approveBalances(int $year, int $by): array
    {
        $c = self::current($year);
        if (!$c) { return ['ok' => false, 'error' => 'Кампания не открыта.']; }
        if ($c['stage'] !== 'balances') { return ['ok' => false, 'error' => 'Этап остатков уже пройден.']; }
        Database::run('UPDATE vacation_campaigns SET stage=?, balances_approved_at=?, balances_approved_by=? WHERE year=?',
            ['blackouts', date('Y-m-d H:i:s'), $by, $year]);
        Audit::log('vacation_campaign.balances_approved', 'Остатки отпусков утверждены, кампания ' . $year . ' → запретные периоды');
        return ['ok' => true];
    }

    /** Перевести кампанию на следующий этап (строго по порядку). */
    public static function advance(int $year, string $to, int $by): array
    {
        $c = self::current($year);
        if (!$c) { return ['ok' => false, 'error' => 'Кампания не открыта.']; }
        $cur = array_search($c['stage'], self::ORDER, true);
        $next = array_search($to, self::ORDER, true);
        if ($cur === false || $next === false || $next !== $cur + 1) {
            return ['ok' => false, 'error' => 'Недопустимый переход этапа.'];
        }
        if ($c['stage'] === 'balances' && empty($c['balances_approved_at'])) {
            return ['ok' => false, 'error' => 'Сначала утвердите остатки.'];
        }
        Database::run('UPDATE vacation_campaigns SET stage=? WHERE year=?', [$to, $year]);
        Audit::log('vacation_campaign.advance', 'Кампания ' . $year . ': этап → ' . (self::STAGES[$to] ?? $to));
        return ['ok' => true];
    }

    // ===== Движок непересечения =====

    public static function deptLimit(int $deptId): int
    {
        $v = Database::scalar('SELECT max_simultaneous FROM vacation_dept_limits WHERE department_id = ?', [$deptId]);
        return $v === false ? 0 : (int) $v; // 0 = лимит не задан (без ограничения)
    }

    /** Дни периода включительно (массив 'Y-m-d'). */
    private static function eachDay(string $start, string $end): array
    {
        $a = strtotime($start); $b = strtotime($end);
        if ($a === false || $b === false || $b < $a) { return []; }
        $out = [];
        for ($t = $a; $t <= $b; $t += 86400) { $out[] = date('Y-m-d', $t); }
        return $out;
    }

    private static function countOnDay(array $peers, string $day): int
    {
        $n = 0;
        foreach ($peers as $p) {
            if ($p['start_date'] <= $day && $p['end_date'] >= $day) { $n++; }
        }
        return $n;
    }

    /**
     * Конфликты непересечения для предполагаемого периода сотрудника.
     * @return array<int,array{type:string,date:string,limit:int,label:string}>
     */
    public static function overlapConflicts(int $empId, int $year, string $start, string $end, ?int $excludePickId = null): array
    {
        $conflicts = [];
        $days = self::eachDay($start, $end);
        if (!$days) { return $conflicts; }

        // --- лимит отдела ---
        $deptId = (int) (Database::scalar('SELECT department_id FROM users WHERE id = ?', [$empId]) ?: 0);
        if ($deptId) {
            $max = self::deptLimit($deptId);
            if ($max > 0) {
                $peers = self::peerPicks($year, "u.department_id = ? AND vp.employee_id <> ?", [$deptId, $empId], $excludePickId);
                foreach ($days as $d) {
                    if (self::countOnDay($peers, $d) + 1 > $max) {
                        $conflicts[] = ['type' => 'dept', 'date' => $d, 'limit' => $max,
                            'label' => 'Отдел: одновременно в отпуске больше ' . $max . ' (на ' . date('d.m.Y', strtotime($d)) . ')'];
                        break;
                    }
                }
            }
        }

        // --- группы непересечения (несколько групп — совместно) ---
        $groups = Database::all(
            "SELECT g.id, g.name, g.max_simultaneous FROM vacation_overlap_groups g
               JOIN vacation_overlap_group_members m ON m.group_id = g.id
              WHERE g.is_active = 1 AND m.employee_id = ?", [$empId]);
        foreach ($groups as $g) {
            $gid = (int) $g['id']; $max = max(1, (int) $g['max_simultaneous']);
            $ids = array_map(fn($r) => (int) $r['employee_id'],
                Database::all('SELECT employee_id FROM vacation_overlap_group_members WHERE group_id = ? AND employee_id <> ?', [$gid, $empId]));
            if (!$ids) { continue; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $peers = self::peerPicks($year, "vp.employee_id IN ($in)", $ids, $excludePickId);
            foreach ($days as $d) {
                if (self::countOnDay($peers, $d) + 1 > $max) {
                    $conflicts[] = ['type' => 'group', 'date' => $d, 'limit' => $max,
                        'label' => 'Группа «' . $g['name'] . '»: одновременно больше ' . $max . ' (на ' . date('d.m.Y', strtotime($d)) . ')'];
                    break;
                }
            }
        }
        return $conflicts;
    }

    /** Периоды «соседей» за год по условию (для подсчёта одновременности). */
    private static function peerPicks(int $year, string $cond, array $params, ?int $excludePickId): array
    {
        $sql = "SELECT vp.start_date, vp.end_date FROM vacation_picks vp
                  JOIN users u ON u.id = vp.employee_id
                 WHERE vp.year = ? AND ($cond)";
        $p = array_merge([$year], $params);
        if ($excludePickId) { $sql .= ' AND vp.id <> ?'; $p[] = $excludePickId; }
        return Database::all($sql, $p);
    }

    /** Запретная зона, пересекающая период (или null). */
    public static function blackoutConflict(int $empId, int $year, string $start, string $end): ?array
    {
        $deptId = (int) (Database::scalar('SELECT department_id FROM users WHERE id = ?', [$empId]) ?: 0);
        return Database::one(
            "SELECT * FROM vacation_blackouts
              WHERE (employee_id = ? OR (employee_id IS NULL AND (department_id IS NULL OR department_id = ?)))
                AND start_date <= ? AND end_date >= ? LIMIT 1",
            [$empId, $deptId, $end, $start]) ?: null;
    }

    /**
     * Полная проверка периода к самозаписи: остаток, запретная зона, непересечение.
     * @return string[] проблемы (пусто = можно сохранять)
     */
    public static function validatePick(int $empId, int $year, string $start, string $end, ?int $excludePickId = null): array
    {
        $issues = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
            return ['некорректный период'];
        }
        $days = VacationScheduleService::calDays($start, $end);
        $balance = VacationScheduleService::balanceOf($empId, $year);
        $already = (int) Database::scalar(
            'SELECT COALESCE(SUM(days),0) FROM vacation_picks WHERE year=? AND employee_id=?' . ($excludePickId ? ' AND id<>?' : ''),
            $excludePickId ? [$year, $empId, $excludePickId] : [$year, $empId]);
        if ($balance > 0 && $already + $days > $balance) {
            $issues[] = 'превышен остаток отпуска: запланировано ' . $already . ' + ' . $days . ' > ' . $balance . ' дн.';
        }
        if (self::blackoutConflict($empId, $year, $start, $end)) {
            $issues[] = 'период попадает в запретную зону';
        }
        foreach (self::overlapConflicts($empId, $year, $start, $end, $excludePickId) as $c) {
            $issues[] = $c['label'];
        }
        return $issues;
    }

    public static function picksOf(int $empId, int $year): array
    {
        return Database::all('SELECT * FROM vacation_picks WHERE year=? AND employee_id=? ORDER BY start_date', [$year, $empId]);
    }

    public static function plannedDays(int $empId, int $year): int
    {
        return (int) Database::scalar('SELECT COALESCE(SUM(days),0) FROM vacation_picks WHERE year=? AND employee_id=?', [$year, $empId]);
    }
}
