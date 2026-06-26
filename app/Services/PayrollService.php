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

        // Колл-центр (график 2/2): «оклад» = ставка за час — отдельная почасовая модель.
        // 5/2-логика ниже не затрагивается (ранний возврат).
        if ($schedule === '2_2') {
            return self::calculateHourly($employeeId, $period, $user, $oklad);
        }
        // Норматив проверки анкет: NULL = классическая модель max(оклад, сделка);
        // задан (>=0) = аддитивная (оклад покрывает норматив, доплата по тарифу только сверх).
        $anketaNorm = $user['anketa_norm'] ?? null;
        $normModel  = $anketaNorm !== null;

        // Табель: норма (админ). Отработано — из учёта явки (открытых дней), иначе из табеля.
        $ts = Database::one('SELECT * FROM timesheets WHERE employee_id = ? AND period = ?', [$employeeId, $period]);
        // «Количество рабочих дней»: норма из табеля, иначе календарные рабочие дни месяца (никогда 0).
        $normDays = ($ts && (int) $ts['norm_days'] > 0) ? (int) $ts['norm_days'] : self::calendarWorkingDays($period);
        // «Дней отработано»: фактическая явка (открытые дни), иначе из табеля.
        $autoWorked = (int) Database::scalar(
            "SELECT COUNT(*) FROM work_days WHERE employee_id = ? AND substr(work_date,1,7) = ? AND opened_at IS NOT NULL",
            [$employeeId, $period]
        );
        $workedDays = $autoWorked > 0 ? $autoWorked : ($ts ? (int) $ts['worked_days'] : 0);
        // Гарантия исходит из ПОЛНОГО месяца (prorate=1), пока табель ЯВНО не зафиксирует неполный
        // месяц (worked_days < norm_days, напр. приём/увольнение/отпуск). Это устраняет «40 ₽» при
        // отсутствии явки/табеля: ЗП не опускается ниже минимума за полный месяц.
        $tsNorm   = $ts ? (int) $ts['norm_days'] : 0;
        $tsWorked = $ts ? (int) $ts['worked_days'] : 0;
        // Доля занятости по дате приёма/увольнения: дни до приёма и после увольнения — нерабочие.
        // Если табель явно фиксирует неполный месяц — берём его; иначе пропорция по периоду занятости.
        $empPro   = self::employmentProrate($period, $user['hire_date'] ?? null, $user['fire_date'] ?? null);
        $prorate  = ($ts && $tsNorm > 0 && $tsWorked < $tsNorm) ? max(0.0, min(1.0, $tsWorked / $tsNorm)) : $empPro;

        // Гарантированная база по окладу (пропорц. фактору). Надбавка переведена на стимул.
        $okladGuaranteed = round($oklad * $rate * $prorate, 2);
        $allowGuaranteed = 0.0; // legacy: users.allowance больше не оплачивается напрямую
        $floor = round($okladGuaranteed, 2);

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
        // Этапы 1 и 3 идут в расчёт только после акцепта менеджером виз; этап 2 (авто) и прочее — всегда.
        $opsRows = Database::all(
            "SELECT o.id, o.name, o.unit_price, COALESCE(SUM(pw.quantity),0) AS qty
               FROM piecework pw
               JOIN operations o ON o.id = pw.operation_id
              WHERE pw.employee_id = ? AND substr(pw.work_date,1,7) = ?
                AND (COALESCE(o.stage,0) NOT IN (1,3) OR pw.accepted_at IS NOT NULL)
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

        // --- Сделка к 25-му: учитывается в этом месяце; после 25-го переходит в следующий ---
        $cutoff = 25;
        $cutThis = self::pieceByKind($employeeId, $period, 1, $cutoff); // сделка этого месяца до 25-го, по источникам
        $pieceSettledAnk = round($cutThis['anketa'], 2);               // анкеты до 25-го
        $pieceSettledViz = round($cutThis['ops'], 2);                  // визы/операции до 25-го
        $pieceSettled    = round($cutThis['total'], 2);
        $pieceCarry      = round($piecework - $pieceSettled, 2);       // сделка этого месяца после 25-го → след. месяц

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

        // --- Стимул по утверждённым служебкам за период (по цели: за анкеты / за визы / за другое) ---
        $memoLines = Database::all(
            "SELECT l.amount, l.pay_kind, l.purpose,
                    (SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS override_amount
               FROM stimulus_memo_lines l
               JOIN stimulus_memos m ON m.id = l.memo_id
              WHERE l.user_id = ? AND m.period = ? AND m.status = 'approved'",
            [$employeeId, $period]);
        $gAnk = 0.0; $gViz = 0.0; $gOth = 0.0; $stimOnetime = 0.0;
        foreach ($memoLines as $ml) {
            // эффективная сумма = последняя корректировка вышестоящего (снижение/отмена), иначе назначенная
            $amt = $ml['override_amount'] !== null ? (float) $ml['override_amount'] : (float) $ml['amount'];
            if ($ml['pay_kind'] === 'onetime') { $stimOnetime += $amt; continue; } // разовый — полной суммой
            $amt = $amt * $prorate;                                                 // ежемесячный — тем же фактором, что оклад
            $purpose = $ml['purpose'] ?? 'other';
            if ($purpose === 'anketas')   { $gAnk += $amt; }
            elseif ($purpose === 'visas') { $gViz += $amt; }
            else                          { $gOth += $amt; }
        }
        $gAnk = round($gAnk, 2); $gViz = round($gViz, 2); $gOth = round($gOth, 2);
        $stimGuaranteed = round($gAnk + $gViz + $gOth, 2);       // гарантированные ежемесячные
        $stimOnetime    = round($stimOnetime, 2);
        $stimMonthly    = $stimGuaranteed;                       // legacy-ключ
        $stimTotal      = round($stimMonthly + $stimOnetime, 2); // legacy-ключ

        // ===== Модель «минимум + сверх минимума» (3 вида начислений) =====
        // Сделка В РАСЧЁТ — по отсечке 25-го числа (как штрафы): сделка этого месяца до 25-го
        // ПЛЮС перенос с прошлого месяца (после 25-го). Сделка этого месяца после 25-го уйдёт
        // в следующий месяц. Детализация сделки выше (anketaSum/opsSum/piecework) — за ПОЛНЫЙ
        // месяц, справочно; на начисление влияет именно эта эффективная сделка.
        $cutPrev = self::pieceByKind($employeeId, $prev, 26, 31);  // перенос с прошлого месяца
        $pieceCarryIn = round($cutPrev['total'], 2);
        $sAnk   = round($pieceSettledAnk + $cutPrev['anketa'], 2); // эфф. сделка-анкеты (к выплате сейчас)
        $sViz   = round($pieceSettledViz + $cutPrev['ops'], 2);    // эфф. сделка-визы
        $sTotal = round($sAnk + $sViz, 2);
        $okladCap = $okladGuaranteed;    // блок 1 = оклад × ставка × prorate

        // Блок 1 — Начислено оклад. Классика: сделка заполняет оклад. Norm: оклад за выполнение норматива,
        // вся сверхнормативная сделка + операции — это overflow «сверх минимума».
        if ($normModel) {
            $b1Sdelka = 0.0;
            $b1Dopl   = $okladCap;
            $overflowTotal = $sTotal;
        } else {
            $b1Sdelka = round(min($sTotal, $okladCap), 2);
            $b1Dopl   = round($okladCap - $b1Sdelka, 2);
            $overflowTotal = round(max(0.0, $sTotal - $okladCap), 2);
        }
        // Распределение overflow по источникам (для сопоставления с целью стимула); сосед — вычитанием.
        $ovfAnk = ($sTotal > 0) ? round($sAnk * $overflowTotal / $sTotal, 2) : 0.0;
        $ovfViz = round($overflowTotal - $ovfAnk, 2);

        // Блок 2 — Ежемесячные стимулирующие (гарантированы): сделка-overflow закрывает цель,
        // нехватка = доплата до минимума; «за другое» — всегда доплата (сделкой не зарабатывается).
        $b2AnkSdelka = round(min($ovfAnk, $gAnk), 2);
        $b2AnkDopl   = round($gAnk - $b2AnkSdelka, 2);
        $b2VizSdelka = round(min($ovfViz, $gViz), 2);
        $b2VizDopl   = round($gViz - $b2VizSdelka, 2);
        $b2OthDopl   = $gOth;
        $absorbed    = round($b2AnkSdelka + $b2VizSdelka, 2);
        $leftover    = round($overflowTotal - $absorbed, 2); // неабсорбированная сделка сверх оклада и целей

        // Блок 3 — Единовременные (аддитивно): разовый стимул + неабсорбированная сделка + фикс-подработки.
        $b3Onetime  = $stimOnetime;
        $b3Leftover = $leftover;
        $b3Fix      = $fixSum;
        $b3Total    = round($b3Onetime + $b3Leftover + $b3Fix, 2);

        // Минимум (гарантия) и сверх минимума.
        $minTotal  = round($okladCap + $stimGuaranteed, 2);
        $overTotal = $b3Total;

        // Снижение за ошибки (как раньше), но защищаем минимум целиком: штраф ест только «сверх».
        $penaltyEffective = round(min($penalties, $overTotal), 2);
        $penaltyCapped    = $penaltyEffective + 0.005 < $penalties;
        $total = round($minTotal + ($overTotal - $penaltyEffective), 2);

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
            'piece_settled'    => $pieceSettled,   // этого месяца до 25-го — учтено сейчас
            'piece_carry_in'   => $pieceCarryIn,   // перенос с прошлого месяца (после 25-го) — учтён сейчас
            'piece_carry'      => $pieceCarry,     // этого месяца после 25-го — перейдёт в следующий месяц
            // штрафы с переносом
            'penalty_carry_in' => round($penCarryIn, 2),  // перенос из прошлого месяца (зафиксированы после 25-го)
            'penalty_deferred' => round($penDeferred, 2), // уйдут в следующий месяц
            // стимул по утверждённым служебкам
            'stim_monthly'     => $stimMonthly,
            'stim_onetime'     => $stimOnetime,
            'stim_total'       => $stimTotal,
            // ===== новая модель: минимум + сверх минимума, по 3 видам начислений =====
            'min_total'        => $minTotal,
            'over_total'       => $overTotal,
            'oklad_cap'        => $okladCap,
            's_ank'            => $sAnk,
            's_viz'            => $sViz,
            's_total'          => $sTotal,
            'b1_sdelka'        => $b1Sdelka,
            'b1_dopl'          => $b1Dopl,
            'g_ank'            => $gAnk,
            'g_viz'            => $gViz,
            'g_oth'            => $gOth,
            'stim_guaranteed'  => $stimGuaranteed,
            'ovf_total'        => $overflowTotal,
            'ovf_ank'          => $ovfAnk,
            'ovf_viz'          => $ovfViz,
            'absorbed'         => $absorbed,
            'leftover_overflow'=> $leftover,
            'b2_ank_sdelka'    => $b2AnkSdelka,
            'b2_ank_dopl'      => $b2AnkDopl,
            'b2_viz_sdelka'    => $b2VizSdelka,
            'b2_viz_dopl'      => $b2VizDopl,
            'b2_oth_dopl'      => $b2OthDopl,
            'b3_onetime'       => $b3Onetime,
            'b3_leftover'      => $b3Leftover,
            'b3_fix'           => $b3Fix,
            'b3_total'         => $b3Total,
            'penalty_details_flag' => $penalties > 0.0049,
            'total'            => $total,
            // совместимость со старыми вызовами
            'dossier_count'    => $anketaCount,
        ];
    }

    /**
     * Почасовой расчёт (колл-центр, график 2/2): «оклад» = ставка за час.
     * Базис: прошлый месяц — по факту (если введён), текущий/будущий — по плану (прогноз).
     * Доплаты (ночные/праздничные/сверхурочные) — независимые надбавки поверх базы; персональная надбавка по ТК.
     */
    private static function calculateHourly(int $employeeId, string $period, array $user, float $rate): array
    {
        $nightPct = Settings::nightPct();
        $holidayMult = Settings::holidayMult();
        $overtimeMult = Settings::overtimeMult();

        $planHours = self::shiftHours($employeeId, $period, 'plan_hours');
        $factHours = self::shiftHours($employeeId, $period, 'fact_hours');
        $useFact = ($period < self::currentPeriod()) && $factHours > 0;

        if ($useFact) {
            $basis = 'fact';
            $H  = $factHours;
            $Hn = self::shiftHours($employeeId, $period, 'fact_night');
            $Hh = self::shiftHours($employeeId, $period, 'holiday_hours');
            $Ho = self::shiftHours($employeeId, $period, 'overtime_hours');
        } else {
            $H  = $planHours;
            $Hn = self::shiftHours($employeeId, $period, 'plan_night');
            $Hh = 0.0; $Ho = 0.0;   // праздничные/сверхурочные — только в факт-табеле
            $basis = $planHours > 0 ? 'plan' : 'none';
        }

        $base        = round($H * $rate, 2);
        $nightPay    = round($Hn * $rate * $nightPct / 100, 2);
        $holidayPay  = round($Hh * $rate * max(0.0, $holidayMult - 1), 2);
        $overtimePay = round($Ho * $rate * max(0.0, $overtimeMult - 1), 2);
        $accrued     = round($base + $nightPay + $holidayPay + $overtimePay, 2);

        $bpct = (float) ($user['hourly_bonus_pct'] ?? 0);
        $brub = (float) ($user['hourly_bonus_rub'] ?? 0);
        $personalBonus = $bpct > 0 ? round($accrued * $bpct / 100, 2) : round($brub, 2);

        $gross = round($accrued + $personalBonus, 2);

        // Штрафы (для колл-центра обычно 0): та же логика, но итог не ниже 0.
        $cutoff = 25;
        $penThis = self::penaltySum($employeeId, $period, 1, $cutoff) + self::visaDeductionSum($employeeId, $period, 1, $cutoff);
        $prev = date('Y-m', strtotime($period . '-01 -1 month'));
        $penCarryIn = self::penaltySum($employeeId, $prev, 26, 31) + self::visaDeductionSum($employeeId, $prev, 26, 31);
        $penalties = round($penThis + $penCarryIn, 2);
        $penaltyEffective = round(min($penalties, $gross), 2);
        $penaltyCapped = $penaltyEffective + 0.005 < $penalties;
        $total = round($gross - $penaltyEffective, 2);

        $planShifts = self::shiftCount($employeeId, $period, 'plan_hours');
        $factShifts = self::shiftCount($employeeId, $period, 'fact_hours');

        return [
            'period'           => $period,
            'schedule_type'    => '2_2',
            'oklad'            => $rate,
            'rate_volume'      => 1.0,
            'allowance'        => 0.0,
            'norm_days'        => $planShifts,
            'worked_days'      => $useFact ? $factShifts : $planShifts,
            'prorate'          => 1.0,
            'oklad_guaranteed' => $accrued,
            'allow_guaranteed' => 0.0,
            'floor'            => $accrued,
            'anketa_count' => 0, 'anketa_sum' => 0.0, 'anketa_breakdown' => [],
            'ops_sum' => 0.0, 'ops_breakdown' => [], 'piecework' => 0.0,
            'fix_sum' => 0.0, 'fix_breakdown' => [], 'earned' => 0.0,
            'reached_oklad' => true, 'reached_level' => true,
            'norm_model' => false, 'anketa_norm_weekly' => null, 'anketa_checked' => 0,
            'anketa_covered' => 0, 'anketa_above_count' => 0, 'anketa_above_sum' => 0.0,
            'gross'            => $gross,
            'penalties'        => $penalties,
            'penalty_effective'=> $penaltyEffective,
            'penalty_capped'   => $penaltyCapped,
            'piece_settled' => 0.0, 'piece_carry' => 0.0,
            'penalty_carry_in' => round($penCarryIn, 2), 'penalty_deferred' => 0.0,
            'stim_monthly' => 0.0, 'stim_onetime' => 0.0, 'stim_total' => 0.0,
            'min_total' => $gross, 'over_total' => 0.0, 'oklad_cap' => $accrued,
            's_ank' => 0.0, 's_viz' => 0.0, 's_total' => 0.0, 'b1_sdelka' => 0.0, 'b1_dopl' => 0.0,
            'g_ank' => 0.0, 'g_viz' => 0.0, 'g_oth' => 0.0, 'stim_guaranteed' => 0.0,
            'ovf_total' => 0.0, 'ovf_ank' => 0.0, 'ovf_viz' => 0.0, 'absorbed' => 0.0, 'leftover_overflow' => 0.0,
            'b2_ank_sdelka' => 0.0, 'b2_ank_dopl' => 0.0, 'b2_viz_sdelka' => 0.0, 'b2_viz_dopl' => 0.0, 'b2_oth_dopl' => 0.0,
            'b3_onetime' => 0.0, 'b3_leftover' => 0.0, 'b3_fix' => 0.0, 'b3_total' => 0.0,
            'penalty_details_flag' => $penalties > 0.0049,
            'total'            => $total,
            'dossier_count'    => 0,
            // ===== почасовые ключи =====
            'is_hourly'      => true,
            'hourly_rate'    => round($rate, 2),
            'used_basis'     => $basis,            // fact | plan | none
            'plan_hours'     => round($planHours, 2),
            'fact_hours'     => round($factHours, 2),
            'hours_paid'     => round($H, 2),
            'night_hours'    => round($Hn, 2),
            'holiday_hours'  => round($Hh, 2),
            'overtime_hours' => round($Ho, 2),
            'base_pay'       => $base,
            'night_pay'      => $nightPay,
            'night_pct'      => $nightPct,
            'holiday_pay'    => $holidayPay,
            'holiday_mult'   => $holidayMult,
            'overtime_pay'   => $overtimePay,
            'overtime_mult'  => $overtimeMult,
            'personal_bonus' => $personalBonus,
            'bonus_pct'      => $bpct,
            'accrued'        => $accrued,
        ];
    }

    /** Сумма колонки часов из shift_days за период YYYY-MM (PG-safe substr). */
    private static function shiftHours(int $employeeId, string $period, string $col): float
    {
        if (!in_array($col, ['plan_hours','plan_night','fact_hours','fact_night','holiday_hours','overtime_hours'], true)) {
            return 0.0;
        }
        return (float) Database::scalar(
            "SELECT COALESCE(SUM($col),0) FROM shift_days WHERE employee_id = ? AND substr(work_date,1,7) = ?",
            [$employeeId, $period]);
    }

    /** Число смен (дней с >0 часами) в графике/факте за период — для инфо. */
    private static function shiftCount(int $employeeId, string $period, string $col): int
    {
        if (!in_array($col, ['plan_hours','fact_hours'], true)) { return 0; }
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM shift_days WHERE employee_id = ? AND substr(work_date,1,7) = ? AND $col > 0",
            [$employeeId, $period]);
    }

    /**
     * Рабочие дни месяца — норма по умолчанию (если нет табеля). Никогда 0.
     * Источник: производственный календарь РФ (isdayoff.ru, учитывает праздники и переносы),
     * если он загружен; иначе откат на простой счёт Пн–Пт.
     */
    public static function calendarWorkingDays(string $period): int
    {
        $parts = explode('-', $period);
        $y = (int) ($parts[0] ?? 0); $m = (int) ($parts[1] ?? 0);
        if ($y < 1 || $m < 1 || $m > 12) { return 21; }
        // Производственный календарь РФ (с праздниками/переносами) — приоритетно.
        $cal = \App\Services\ProductionCalendar::workingDaysInMonth($y, $m);
        if ($cal !== null && $cal > 0) { return $cal; }
        // Откат: Пн–Пт (если календарь на год не загружен / нет сети).
        $days = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $cnt = 0;
        for ($d = 1; $d <= $days; $d++) {
            if ((int) date('N', mktime(0, 0, 0, $m, $d, $y)) <= 5) { $cnt++; } // 1=Пн..5=Пт
        }
        return $cnt > 0 ? $cnt : 21;
    }

    /**
     * Рабочие дни (Пн–Пт) периода, ПОПАДАЮЩИЕ в период занятости [дата приёма; дата увольнения].
     * Дни до приёма и после увольнения считаются нерабочими (не учитываются). День увольнения — рабочий.
     */
    public static function employmentWorkingDays(string $period, ?string $hire, ?string $fire): int
    {
        $parts = explode('-', $period);
        $y = (int) ($parts[0] ?? 0); $m = (int) ($parts[1] ?? 0);
        if ($y < 1 || $m < 1 || $m > 12) { return 0; }
        $days = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $hireTs = ($hire !== null && $hire !== '') ? strtotime(substr((string) $hire, 0, 10)) : null;
        $fireTs = ($fire !== null && $fire !== '') ? strtotime(substr((string) $fire, 0, 10)) : null;
        $cnt = 0;
        for ($d = 1; $d <= $days; $d++) {
            $t = mktime(0, 0, 0, $m, $d, $y);
            if ((int) date('N', $t) > 5) { continue; }            // выходной
            if ($hireTs !== null && $t < $hireTs) { continue; }   // до приёма
            if ($fireTs !== null && $t > $fireTs) { continue; }   // после увольнения
            $cnt++;
        }
        return $cnt;
    }

    /** Доля занятости месяца (рабочие дни занятости ÷ рабочие дни месяца), 0..1. Без дат приёма/увольнения = 1. */
    public static function employmentProrate(string $period, ?string $hire, ?string $fire): float
    {
        if (($hire === null || $hire === '') && ($fire === null || $fire === '')) { return 1.0; }
        $cal = self::calendarWorkingDays($period);
        if ($cal <= 0) { return 1.0; }
        return max(0.0, min(1.0, self::employmentWorkingDays($period, $hire, $fire) / $cal));
    }

    /** Годовой фактор занятости (сумма помесячных долей за год, 0..12). Полный год без дат = 12. */
    public static function employmentYearFactor(int $year, ?string $hire, ?string $fire): float
    {
        if (($hire === null || $hire === '') && ($fire === null || $fire === '')) { return 12.0; }
        $f = 0.0;
        for ($m = 1; $m <= 12; $m++) { $f += self::employmentProrate(sprintf('%04d-%02d', $year, $m), $hire, $fire); }
        return round($f, 4);
    }

    /**
     * Детализация снижений за ошибки за период (для раскрытия в расчётном листке):
     * штрафы контролёра по анкетам (inspections) + визовые вычеты (visa_deductions).
     */
    public static function penaltyDetails(int $employeeId, string $period): array
    {
        $rows = [];
        $ins = Database::all(
            "SELECT ai.reg_number, ai.country_code, " . Database::txt('ai.checked_at') . " AS checked_at,
                    i.penalty_amount, i.occurrence, et.name AS error_name
               FROM inspections i
               JOIN assignment_items ai ON ai.id = i.dossier_id
               LEFT JOIN error_types et ON et.id = i.error_type_id
              WHERE i.employee_id = ? AND i.is_correct = 0 AND substr(ai.checked_at,1,7) = ?
              ORDER BY ai.checked_at",
            [$employeeId, $period]
        );
        foreach ($ins as $r) {
            $rows[] = [
                'date'   => substr((string) $r['checked_at'], 0, 10),
                'kind'   => 'anketa',
                'title'  => trim((string) ($r['reg_number'] ?? '') . ' ' . (string) ($r['country_code'] ?? '')),
                'reason' => ($r['error_name'] ?? 'ошибка') . ((int) $r['occurrence'] > 1 ? " (повтор №{$r['occurrence']})" : ''),
                'amount' => (float) $r['penalty_amount'],
            ];
        }
        $vis = Database::all(
            "SELECT amount, reason, " . Database::txt('created_at') . " AS created_at
               FROM visa_deductions WHERE employee_id = ? AND period = ? ORDER BY created_at",
            [$employeeId, $period]
        );
        foreach ($vis as $r) {
            if ((float) $r['amount'] <= 0) { continue; } // 0 = не его вина, в снижение не идёт
            $rows[] = [
                'date'   => substr((string) $r['created_at'], 0, 10),
                'kind'   => 'visa',
                'title'  => 'Виза — возврат на доработку (отказ МИД)',
                'reason' => (string) ($r['reason'] ?? ''),
                'amount' => (float) $r['amount'],
            ];
        }
        return $rows;
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
                AND CAST(substr(pw.work_date,9,2) AS INTEGER) BETWEEN ? AND ?
                AND (COALESCE(o.stage,0) NOT IN (1,3) OR pw.accepted_at IS NOT NULL)", [$employeeId, $period, $dayFrom, $dayTo]);
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
