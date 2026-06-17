<?php
namespace App\Services;

use App\Core\Database;

/**
 * Надбавка через стимул: назначение надбавки на период порождает ежемесячные служебки-проекты
 * (stimulus_memos, pay_kind='monthly', status='draft') с основаниями из stimulus_grounds.
 * Оплата идёт через утверждённые служебки (PayrollService::stim_monthly), не через users.allowance.
 */
class AllowanceService
{
    /** Список YYYY-MM в диапазоне [from..to] (принимает даты или YYYY-MM). PG-safe (в PHP). */
    public static function monthsRange(string $from, string $to): array
    {
        $f = substr($from, 0, 7); $t = substr($to, 0, 7);
        if ($f === '' || $t === '') { return []; }
        if ($t < $f) { [$f, $t] = [$t, $f]; }
        $out = []; $cur = $f . '-01'; $guard = 0;
        while ($guard++ < 120) {
            $m = substr($cur, 0, 7);
            $out[] = $m;
            if ($m === $t) { break; }
            $cur = date('Y-m-01', strtotime($cur . ' +1 month'));
        }
        return $out;
    }

    /** oklad_load сотрудника = (оклад должности или личный) × ставка (как в StimulusController::store). */
    private static function okladLoad(array $u): float
    {
        $okl = $u['p_oklad'] !== null ? (float) $u['p_oklad'] : (float) ($u['oklad'] ?? 0);
        return round($okl * (float) ($u['rate_volume'] ?? 1), 2);
    }

    private static function pf($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }

    /**
     * Назначить надбавку на период → ежемесячные служебки-проекты (стимул).
     * $opts: user_id, amount, period_from, period_to, grounds_ids[], source_id, assigned_by.
     * @return array{ok:bool,message:string,grant_id?:int,created?:int}
     */
    public static function grant(array $opts): array
    {
        $uid = (int) ($opts['user_id'] ?? 0);
        $amount = round((float) str_replace([' ', ','], ['', '.'], (string) ($opts['amount'] ?? 0)), 2);
        $from = (string) ($opts['period_from'] ?? '');
        $to = (string) ($opts['period_to'] ?? '');
        $groundIds = array_values(array_filter(array_map('intval', (array) ($opts['grounds_ids'] ?? []))));
        $sourceId = (int) ($opts['source_id'] ?? 0) ?: null;
        $by = (int) ($opts['assigned_by'] ?? 0) ?: null;

        if (!$uid || $amount <= 0) { return ['ok' => false, 'message' => 'Укажите сотрудника и сумму надбавки.']; }
        $months = self::monthsRange($from, $to);
        if (!$months) { return ['ok' => false, 'message' => 'Укажите период (с/по).']; }
        if (!$groundIds) { return ['ok' => false, 'message' => 'Выберите хотя бы одно основание из списка стимула.']; }

        $u = Database::one('SELECT u.*, p.oklad AS p_oklad FROM users u LEFT JOIN positions p ON p.id=u.position_id WHERE u.id=?', [$uid]);
        if (!$u) { return ['ok' => false, 'message' => 'Сотрудник не найден.']; }
        $load = self::okladLoad($u);
        $pct = $load > 0 ? round($amount / $load * 100, 1) : 0;

        // groundsCap: суммарный максимум % выбранных оснований должен покрывать % надбавки.
        $cap = (float) Database::scalar(
            'SELECT COALESCE(SUM(percent),0) FROM stimulus_grounds WHERE id IN (' . implode(',', array_fill(0, count($groundIds), '?')) . ')',
            $groundIds);
        if ($pct > $cap + 0.01) {
            return ['ok' => false, 'message' => 'Выбранных оснований недостаточно: их суммарный максимум '
                . self::pf($cap) . '% не покрывает ' . self::pf($pct) . '% надбавки. Добавьте основания.'];
        }
        $groundTexts = array_map(fn($gid) => (string) Database::scalar('SELECT text FROM stimulus_grounds WHERE id=?', [$gid]), $groundIds);
        $deptId = $u['department_id'] ? (int) $u['department_id'] : null;
        $author = $deptId ? (int) Database::scalar('SELECT head_id FROM departments WHERE id=?', [$deptId]) : 0;
        if (!$author) { $author = $by ?: $uid; }   // нет начальника — автор = назначивший

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $grantId = Database::insert(
            'INSERT INTO allowance_grants (user_id, amount, period_from, period_to, grounds_ids, grounds, source_id, assigned_by, status) VALUES (?,?,?,?,?,?,?,?,?)',
            [$uid, $amount, $months[0] . '-01', end($months) . '-01', implode(',', $groundIds), implode('; ', $groundTexts), $sourceId, $by, 'active']);
        $created = 0;
        foreach ($months as $m) {
            $memoId = Database::insert(
                'INSERT INTO stimulus_memos (department_id, author_id, period, pay_kind, source_id, grounds, grounds_ids, kind, status, grant_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$deptId, $author, $m, 'monthly', $sourceId, implode('; ', $groundTexts), implode(',', $groundIds), 'staff', 'draft', $grantId]);
            $pfrom = $m . '-01'; $pto = date('Y-m-t', strtotime($pfrom));
            Database::insert(
                'INSERT INTO stimulus_memo_lines (memo_id, user_id, amount, pay_kind, period_from, period_to, oklad_load, percent) VALUES (?,?,?,?,?,?,?,?)',
                [$memoId, $uid, $amount, 'monthly', $pfrom, $pto, $load, $pct]);
            $created++;
        }
        $pdo->commit();
        return ['ok' => true, 'grant_id' => $grantId, 'created' => $created,
            'message' => "Надбавка назначена: создано {$created} служебок-проектов (ежемесячно). Подпишите их для утверждения."];
    }

    /** Отменить назначение: пометить canceled и удалить неутверждённые служебки-проекты. */
    public static function cancel(int $grantId): array
    {
        $g = Database::one('SELECT * FROM allowance_grants WHERE id=?', [$grantId]);
        if (!$g) { return ['ok' => false, 'message' => 'Назначение не найдено.']; }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $memos = Database::all("SELECT id FROM stimulus_memos WHERE grant_id=? AND status IN ('draft','revision')", [$grantId]);
        foreach ($memos as $mm) {
            Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [(int) $mm['id']]);
            Database::run('DELETE FROM stimulus_memos WHERE id=?', [(int) $mm['id']]);
        }
        Database::run("UPDATE allowance_grants SET status='canceled' WHERE id=?", [$grantId]);
        $pdo->commit();
        $kept = (int) Database::scalar('SELECT COUNT(*) FROM stimulus_memos WHERE grant_id=?', [$grantId]);
        return ['ok' => true, 'message' => 'Назначение отменено: удалено проектов ' . count($memos)
            . ($kept ? ", утверждённые оставлены ($kept) — снимите их отдельно через откат" : '') . '.'];
    }
}
