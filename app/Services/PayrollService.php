<?php
namespace App\Services;

use App\Core\Database;

class PayrollService
{
    /** Текущий период YYYY-MM. */
    public static function currentPeriod(): string
    {
        return date('Y-m');
    }

    /**
     * Единая модель ЗП:
     *   Гарантия (floor) = (оклад × ставка + надбавка) × (отработано/норма);
     *   Заработано (earned) = сделка (анкеты + операции) + фикс-подработки (пропорц. времени);
     *   К выплате = max(floor, earned) − штрафы.
     * Надбавка платится всегда (входит в гарантию); по ней — индикатор покрытия сделкой.
     * Режим 5/2 и 2/2 одинаково пропорционируют оклад по отработанным единицам (дни/смены).
     */
    public static function calculate(int $employeeId, string $period): array
    {
        $user = Database::one(
            'SELECT u.*, p.oklad AS position_oklad, p.title AS position_title
               FROM users u
               LEFT JOIN positions p ON p.id = u.position_id
              WHERE u.id = ?',
            [$employeeId]
        );
        $oklad     = $user['position_oklad'] !== null ? (float) $user['position_oklad'] : (float) ($user['oklad'] ?? 0);
        $rate      = (float) ($user['rate_volume'] ?? 1);
        $allowance = (float) ($user['allowance'] ?? 0);
        $schedule  = $user['schedule_type'] ?? '5_2';
        // Норматив проверки анкет: NULL = классическая модель max(оклад, сделка);
        // задан (>=0) = аддитивная (оклад покрывает норматив, доплата по тарифу только сверх).
        $anketaNorm = $user['anketa_norm'] ?? null;
        $normModel  = $anketaNorm !== null;

        // Табель: норма (админ). Отработано — из учёта явки (открытых дней), иначе из табеля.
        $ts = Database::one('SELECT * FROM timesheets WHERE employee_id = ? AND period = ?', [$employeeId, $period]);
        $normDays   = $ts ? (int) $ts['norm_days'] : 0;
        $autoWorked = (int) Database::scalar(
            "SELECT COUNT(*) FROM work_days WHERE employee_id = ? AND substr(work_date,1,7) = ? AND opened_at IS NOT NULL",
            [$employeeId, $period]
        );
        $workedDays = $autoWorked > 0 ? $autoWorked : ($ts ? (int) $ts['worked_days'] : 0);
        $prorate = $normDays > 0 ? min(1.0, $workedDays / $normDays) : 0.0;

        // Гарантированная база (пропорц. времени).
        $okladGuaranteed = round($oklad * $rate * $prorate, 2);
        $allowGuaranteed = round($allowance * $prorate, 2);
        $floor = round($okladGuaranteed + $allowGuaranteed, 2);

        // --- Сделка: анкеты (назначенные менеджером и отмеченные проверенными) ---
        $norm = null;
        $anketaBreakdown = [];
        if ($normModel) {
            // Аддитивная модель: в сделку идут ТОЛЬКО анкеты сверх норматива (NormService — единый источник).
            $norm = \App\Services\NormService::forEmployee($employeeId, $period);
            $anketaCount = (int) $norm['above_count'];
            $anketaSum   = (float) $norm['above_sum'];
            foreach ($norm['above_breakdown'] as $t) {
                $anketaBreakdown[] = ['group_no' => null, 'title' => $t['title'],
                    'price' => $t['price'], 'count' => $t['count'], 'subtotal' => $t['subtotal']];
            }
        } else {
            // Классика: все проверенные анкеты по тарифу страны (неизвестная = 70), свод по тарифу.
            $countryRows = Database::all(
                "SELECT country_code, COUNT(*) AS cnt
                   FROM assignment_items
                  WHERE assigned_to = ? AND checked_at IS NOT NULL AND substr(checked_at,1,7) = ?
                  GROUP BY country_code",
                [$employeeId, $period]
            );
            $byTier = []; $anketaCount = 0; $anketaSum = 0.0;
            foreach ($countryRows as $r) {
                $cnt = (int) $r['cnt'];
                $price = \App\Services\Tariff::priceForCountry($r['country_code']);
                $anketaCount += $cnt;
                $key = (string) $price;
                if (!isset($byTier[$key])) { $byTier[$key] = ['price' => $price, 'count' => 0, 'subtotal' => 0.0]; }
                $byTier[$key]['count'] += $cnt;
                $byTier[$key]['subtotal'] = round($byTier[$key]['subtotal'] + $cnt * $price, 2);
            }
            ksort($byTier, SORT_NUMERIC);
            foreach ($byTier as $t) {
                $anketaSum += $t['subtotal'];
                $anketaBreakdown[] = [
                    'group_no' => null, 'title' => 'тариф ' . rtrim(rtrim(number_format($t['price'],2,'.',''), '0'), '.') . ' ₽',
                    'price' => $t['price'], 'count' => $t['count'], 'subtotal' => $t['subtotal'],
                ];
            }
            $anketaSum = round($anketaSum, 2);
        }

        // --- Сделка: операции (визы и пр.) ---
        $opsRows = Database::all(
            "SELECT o.id, o.name, o.unit_price, COALESCE(SUM(pw.quantity),0) AS qty
               FROM piecework pw
               JOIN operations o ON o.id = pw.operation_id
              WHERE pw.employee_id = ? AND substr(pw.work_date,1,7) = ?
              GROUP BY o.id, o.name, o.unit_price
              ORDER BY o.name",
            [$employeeId, $period]
        );
        $opsSum = 0.0; $opsBreakdown = [];
        foreach ($opsRows as $o) {
            $qty = (int) $o['qty'];
            $price = (float) $o['unit_price'];
            $sub = round($qty * $price, 2);
            $opsSum += $sub;
            $opsBreakdown[] = [
                'name' => $o['name'], 'price' => $price, 'count' => $qty, 'subtotal' => $sub,
            ];
        }

        $piecework = round($anketaSum + $opsSum, 2);

        // --- Фиксированные подработки (пропорц. времени) ---
        $fixRows = Database::all(
            'SELECT name, monthly_amount FROM employee_fixed_extras WHERE employee_id = ? AND is_active = 1 ORDER BY name',
            [$employeeId]
        );
        $fixSum = 0.0; $fixBreakdown = [];
        foreach ($fixRows as $f) {
            $amt = round((float) $f['monthly_amount'] * $prorate, 2);
            $fixSum += $amt;
            $fixBreakdown[] = ['name' => $f['name'], 'monthly' => (float) $f['monthly_amount'], 'amount' => $amt];
        }
        $fixSum = round($fixSum, 2);

        $earned = round($piecework + $fixSum, 2);
        // Аддитивная модель (норматив): оклад+надбавка гарантированы, доплата сверх — сверху.
        // Классика: max(оклад, сделка). В обоих случаях gross ≥ floor (floor-защита сохраняется).
        $gross  = $normModel ? round($floor + $earned, 2) : round(max($floor, $earned), 2);

        // --- Сделка к 25-му: финализируется в служебку; после 25-го переходит в следующий месяц ---
        $cutoff = 25;
        if ($normModel) {
            // Анкеты — только сверхнормативные с днём ≤25; операции — как обычно.
            $pieceSettled = round(($norm['above_sum_to_day25'] ?? 0) + self::opsSum($employeeId, $period, 1, $cutoff), 2);
        } else {
            $pieceSettled = self::pieceworkSum($employeeId, $period, 1, $cutoff);
        }
        $pieceCarry   = round($piecework - $pieceSettled, 2);

        // --- Штрафы: зафиксированные после 25-го переносятся в следующий месяц ---
        // Включают как штрафы контролёра по анкетам (inspections), так и визовые вычеты за отказ МИД.
        $penThis = self::penaltySum($employeeId, $period, 1, $cutoff)
                 + self::visaDeductionSum($employeeId, $period, 1, $cutoff);            // этого месяца, до 25-го
        $prev = date('Y-m', strtotime($period . '-01 -1 month'));
        $penCarryIn = self::penaltySum($employeeId, $prev, 26, 31)
                    + self::visaDeductionSum($employeeId, $prev, 26, 31);               // перенос из прошлого месяца
        $penDeferred = self::penaltySum($employeeId, $period, 26, 31)
                     + self::visaDeductionSum($employeeId, $period, 26, 31);            // уйдёт в следующий месяц
        $penalties = round($penThis + $penCarryIn, 2);

        // --- Стимул по утверждённым служебкам за период ---
        $memoLines = Database::all(
            "SELECT l.amount, l.pay_kind, l.percent FROM stimulus_memo_lines l
               JOIN stimulus_memos m ON m.id = l.memo_id
              WHERE l.user_id = ? AND m.period = ? AND m.status = 'approved'",
            [$employeeId, $period]);
        $stimMonthly = 0.0; $stimOnetime = 0.0;
        foreach ($memoLines as $ml) {
            if ($ml['pay_kind'] === 'onetime') { $stimOnetime += (float) $ml['amount']; }
            else { $stimMonthly += round((float) $ml['amount'] * $prorate, 2); } // ежемесячный — пропорц. отработке
        }
        $stimMonthly = round($stimMonthly, 2); $stimOnetime = round($stimOnetime, 2);
        $stimTotal = round($stimMonthly + $stimOnetime, 2);

        // Штрафы не могут опустить итог ниже гарантированного минимума (floor); стимул добавляется сверху.
        $afterPenalty = round(max($floor, $gross - $penalties), 2);
        $penaltyEffective = round($gross - $afterPenalty, 2);
        $penaltyCapped = $penaltyEffective + 0.005 < $penalties;
        $total = round($afterPenalty + $stimTotal, 2);

        return [
            'period'           => $period,
            'schedule_type'    => $schedule,
            'oklad'            => $oklad,
            'rate_volume'      => $rate,
            'allowance'        => $allowance,
            'norm_days'        => $normDays,
            'worked_days'      => $workedDays,
            'prorate'          => $prorate,
            'oklad_guaranteed' => $okladGuaranteed,
            'allow_guaranteed' => $allowGuaranteed,
            'floor'            => $floor,
            'anketa_count'     => $anketaCount,
            'anketa_sum'       => round($anketaSum, 2),
            'anketa_breakdown' => $anketaBreakdown,
            'ops_sum'          => round($opsSum, 2),
            'ops_breakdown'    => $opsBreakdown,
            'piecework'        => $piecework,
            'fix_sum'          => $fixSum,
            'fix_breakdown'    => $fixBreakdown,
            'earned'           => $earned,
            // В norm-модели база гарантирована, доплата сверху — «достигнуто» по смыслу всегда.
            'reached_oklad'    => $normModel ? true : ($earned >= $okladGuaranteed),
            'reached_level'    => $normModel ? true : ($earned >= $floor),
            // Норматив анкет (аддитивная модель)
            'norm_model'         => $normModel,
            'anketa_norm_weekly' => $normModel ? (int) $anketaNorm : null,
            'anketa_checked'     => $normModel ? (int) $norm['checked'] : $anketaCount,
            'anketa_covered'     => $normModel ? (int) $norm['covered'] : 0,
            'anketa_above_count' => $normModel ? (int) $norm['above_count'] : $anketaCount,
            'anketa_above_sum'   => $normModel ? round((float) $norm['above_sum'], 2) : round($anketaSum, 2),
            'gross'            => $gross,
            'penalties'        => $penalties,
            'penalty_effective'=> $penaltyEffective,
            'penalty_capped'   => $penaltyCapped,
            // сделка по отсечке 25-го числа
            'piece_settled'    => $pieceSettled,   // до 25-го — в служебку этого месяца
            'piece_carry'      => $pieceCarry,     // после 25-го — перейдёт в следующий месяц
            // штрафы с переносом
            'penalty_carry_in' => round($penCarryIn, 2),  // перенос из прошлого месяца (зафиксированы после 25-го)
            'penalty_deferred' => round($penDeferred, 2), // уйдут в следующий месяц
            // стимул по утверждённым служебкам
            'stim_monthly'     => $stimMonthly,
            'stim_onetime'     => $stimOnetime,
            'stim_total'       => $stimTotal,
            'total'            => $total,
            // совместимость со старыми вызовами
            'dossier_count'    => $anketaCount,
        ];
    }

    /** Сумма сделки (анкеты по тарифам + операции) за период с фильтром дня DD от..до. */
    private static function pieceworkSum(int $employeeId, string $period, int $dayFrom, int $dayTo): float
    {
        $sum = 0.0;
        $rows = Database::all(
            "SELECT country_code, COUNT(*) cnt FROM assignment_items
              WHERE assigned_to=? AND checked_at IS NOT NULL AND substr(checked_at,1,7)=?
                AND CAST(substr(checked_at,9,2) AS INTEGER) BETWEEN ? AND ?
              GROUP BY country_code", [$employeeId, $period, $dayFrom, $dayTo]);
        foreach ($rows as $r) { $sum += (int)$r['cnt'] * \App\Services\Tariff::priceForCountry($r['country_code']); }
        $sum += self::opsSum($employeeId, $period, $dayFrom, $dayTo);
        return round($sum, 2);
    }

    /** Сумма операций (визы и пр.) за период с фильтром дня DD от..до. */
    private static function opsSum(int $employeeId, string $period, int $dayFrom, int $dayTo): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(pw.quantity*o.unit_price),0) FROM piecework pw JOIN operations o ON o.id=pw.operation_id
              WHERE pw.employee_id=? AND substr(pw.work_date,1,7)=?
                AND CAST(substr(pw.work_date,9,2) AS INTEGER) BETWEEN ? AND ?", [$employeeId, $period, $dayFrom, $dayTo]);
    }

    /**
     * Сделка работника за период, разнесённая по источникам (для переноса в служебку):
     *   anketa — квота (анкеты по тарифам стран), ops — визы и прочие операции.
     * Фильтр дня DD от..до позволяет брать только финализируемое к 25-му числу.
     */
    public static function pieceByKind(int $employeeId, string $period, int $dayFrom = 1, int $dayTo = 31): array
    {
        // В norm-модели в сделку идут только анкеты сверх норматива (за день в диапазоне).
        $hasNorm = Database::scalar('SELECT anketa_norm FROM users WHERE id = ?', [$employeeId]);
        if ($hasNorm !== false && $hasNorm !== null) {
            $anketa = \App\Services\NormService::aboveSumDayRange($employeeId, $period, $dayFrom, $dayTo);
        } else {
            $anketa = 0.0;
            $rows = Database::all(
                "SELECT country_code, COUNT(*) cnt FROM assignment_items
                  WHERE assigned_to=? AND checked_at IS NOT NULL AND substr(checked_at,1,7)=?
                    AND CAST(substr(checked_at,9,2) AS INTEGER) BETWEEN ? AND ?
                  GROUP BY country_code", [$employeeId, $period, $dayFrom, $dayTo]);
            foreach ($rows as $r) { $anketa += (int) $r['cnt'] * \App\Services\Tariff::priceForCountry($r['country_code']); }
        }
        $ops = self::opsSum($employeeId, $period, $dayFrom, $dayTo);
        return ['anketa' => round($anketa, 2), 'ops' => round($ops, 2), 'total' => round($anketa + $ops, 2)];
    }

    /** Сумма штрафов контролёра за период с фильтром дня проверки DD от..до. */
    private static function penaltySum(int $employeeId, string $period, int $dayFrom, int $dayTo): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(i.penalty_amount),0) FROM inspections i
               JOIN assignment_items ai ON ai.id = i.dossier_id
              WHERE i.employee_id=? AND i.is_correct=0 AND substr(ai.checked_at,1,7)=?
                AND CAST(substr(ai.checked_at,9,2) AS INTEGER) BETWEEN ? AND ?",
            [$employeeId, $period, $dayFrom, $dayTo]);
    }

    /** Сумма визовых вычетов (возврат строки на доработку после отказа МИД) с фильтром дня фиксации DD от..до. */
    private static function visaDeductionSum(int $employeeId, string $period, int $dayFrom, int $dayTo): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM visa_deductions
              WHERE employee_id=? AND period=?
                AND CAST(substr(created_at,9,2) AS INTEGER) BETWEEN ? AND ?",
            [$employeeId, $period, $dayFrom, $dayTo]);
    }
}
