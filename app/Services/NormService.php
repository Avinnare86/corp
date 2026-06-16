<?php
namespace App\Services;

use App\Core\Database;

/**
 * Недельный норматив проверки анкет «за оклад».
 *
 * Модель (когда users.anketa_norm задан): оклад+надбавка покрывают месячный норматив анкет
 * (covered = недельный норматив × отработанные дни / рабочих дней в неделе, т.е. с учётом явки),
 * а доплата по тарифу начисляется ТОЛЬКО за анкеты сверх норматива. Норматив 0 = чистый сдельщик
 * (всё по тарифу). Единый источник истины для PayrollService и недельного отчёта о выработке.
 */
class NormService
{
    /** Рабочих дней в неделе по типу расписания (2/2 ≈ 3.5 смены/нед). */
    public static function workdaysPerWeek(string $schedule): float
    {
        return $schedule === '2_2' ? 3.5 : 5.0;
    }

    /**
     * Полная выработка норматива сотрудника за период YYYY-MM.
     * @return array{has_norm:bool,weekly_norm:?int,schedule:string,worked_days:int,norm_days:int,
     *   workdays_per_week:float,checked:int,covered:int,above_count:int,above_sum:float,
     *   above_sum_to_day25:float,above_breakdown:array,above_items:array,weeks:array}
     */
    public static function forEmployee(int $uid, string $period): array
    {
        $user = Database::one('SELECT schedule_type, anketa_norm FROM users WHERE id = ?', [$uid]);
        $schedule = $user['schedule_type'] ?? '5_2';
        $hasNorm  = $user && $user['anketa_norm'] !== null;
        $weekly   = $hasNorm ? (int) $user['anketa_norm'] : null;

        // worked_days / norm_days — как в PayrollService (автоучёт по work_days, фолбэк на табель).
        $ts = Database::one('SELECT * FROM timesheets WHERE employee_id = ? AND period = ?', [$uid, $period]);
        $normDays = $ts ? (int) $ts['norm_days'] : 0;
        $autoWorked = (int) Database::scalar(
            "SELECT COUNT(*) FROM work_days WHERE employee_id = ? AND substr(work_date,1,7) = ? AND opened_at IS NOT NULL",
            [$uid, $period]);
        $workedDays = $autoWorked > 0 ? $autoWorked : ($ts ? (int) $ts['worked_days'] : 0);

        $perWeek = self::workdaysPerWeek($schedule);
        $covered = $hasNorm ? (int) round($weekly * $workedDays / $perWeek) : 0;

        // Предзагрузка цен стран (как Tariff::priceForCountry, но без N запросов).
        $priceMap = [];
        foreach (Database::all("SELECT c.code, pg.price FROM countries c JOIN price_groups pg ON pg.group_no = c.group_no") as $r) {
            $priceMap[mb_strtoupper((string) $r['code'], 'UTF-8')] = (float) $r['price'];
        }
        $defaultPrice = (float) (Database::scalar('SELECT price FROM price_groups WHERE group_no = 2') ?: 70.0);

        // Все проверенные анкеты месяца по хронологии (первые covered — «за оклад»).
        $rows = Database::all(
            "SELECT country_code, CAST(substr(checked_at,9,2) AS INTEGER) AS day
               FROM assignment_items
              WHERE assigned_to = ? AND checked_at IS NOT NULL AND substr(checked_at,1,7) = ?
              ORDER BY checked_at, id",
            [$uid, $period]);
        $checked = count($rows);

        $aboveSum = 0.0; $aboveTo25 = 0.0; $aboveCount = 0; $aboveTier = []; $aboveItems = [];
        $byDay = array_fill(1, 31, 0);
        foreach ($rows as $i => $r) {
            $day = (int) $r['day']; if ($day < 1) { $day = 1; } if ($day > 31) { $day = 31; }
            $byDay[$day]++;
            if (!$hasNorm || $i < $covered) { continue; } // покрыто окладом
            $price = $priceMap[mb_strtoupper((string) $r['country_code'], 'UTF-8')] ?? $defaultPrice;
            $aboveSum += $price; $aboveCount++;
            if ($day <= 25) { $aboveTo25 += $price; }
            $aboveItems[] = ['day' => $day, 'price' => $price];
            $k = (string) $price;
            if (!isset($aboveTier[$k])) { $aboveTier[$k] = ['price' => $price, 'count' => 0, 'subtotal' => 0.0]; }
            $aboveTier[$k]['count']++; $aboveTier[$k]['subtotal'] = round($aboveTier[$k]['subtotal'] + $price, 2);
        }
        ksort($aboveTier, SORT_NUMERIC);
        $aboveBreakdown = [];
        foreach ($aboveTier as $t) {
            $aboveBreakdown[] = [
                'price' => $t['price'], 'count' => $t['count'], 'subtotal' => round($t['subtotal'], 2),
                'title' => 'тариф ' . rtrim(rtrim(number_format($t['price'], 2, '.', ''), '0'), '.') . ' ₽',
            ];
        }

        // Недельные бакеты (дни 1-7/8-14/15-21/22-28/29-конец) — для отчёта о выработке.
        $daysInMonth = (int) date('t', strtotime($period . '-01'));
        $weeks = [];
        foreach ([[1, 7], [8, 14], [15, 21], [22, 28], [29, 31]] as [$from, $to]) {
            $to = min($to, $daysInMonth);
            if ($from > $daysInMonth) { break; }
            $cnt = 0; for ($d = $from; $d <= $to; $d++) { $cnt += $byDay[$d] ?? 0; }
            $span = $to - $from + 1;
            $target = $hasNorm ? (int) round($weekly * min($span, 7) / 7) : null;
            $weeks[] = [
                'label'   => $from . '–' . $to,
                'checked' => $cnt,
                'target'  => $target,
                'pct'     => ($target && $target > 0) ? (int) round($cnt / $target * 100) : null,
            ];
        }

        return [
            'has_norm' => $hasNorm,
            'weekly_norm' => $weekly,
            'schedule' => $schedule,
            'worked_days' => $workedDays,
            'norm_days' => $normDays,
            'workdays_per_week' => $perWeek,
            'checked' => $checked,
            'covered' => $covered,
            'above_count' => $aboveCount,
            'above_sum' => round($aboveSum, 2),
            'above_sum_to_day25' => round($aboveTo25, 2),
            'above_breakdown' => $aboveBreakdown,
            'above_items' => $aboveItems,
            'weeks' => $weeks,
        ];
    }

    /** Сумма доплаты (тариф сверхнормативных анкет), у которых день в [dayFrom,dayTo] — для отсечки 25-го и pieceByKind. */
    public static function aboveSumDayRange(int $uid, string $period, int $dayFrom, int $dayTo): float
    {
        $n = self::forEmployee($uid, $period);
        $s = 0.0;
        foreach ($n['above_items'] as $it) {
            if ($it['day'] >= $dayFrom && $it['day'] <= $dayTo) { $s += $it['price']; }
        }
        return round($s, 2);
    }
}
