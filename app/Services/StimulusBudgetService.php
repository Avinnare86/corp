<?php
namespace App\Services;

use App\Core\Database;

/**
 * Прогноз остатка средств ФОТ до конца года для формирования служебки о стимуле.
 *
 * Остаток = Бюджет года − план окладной части (год) − действующий ежемесячный стимул (год)
 *           − плановый резерв на отпуск.
 *
 * Считается по отделу служебки (бюджет — годовой, из dept_budgets). Все компоненты
 * приводятся к годовой базе, чтобы сопоставляться с годовым бюджетом; дополнительно
 * показываем месячные ставки и число оставшихся месяцев — для наглядности «до конца года».
 *
 * СТАРТ-МЕСЯЦ (budget_year_settings.start_month, по умолчанию 1 — с начала года): если система
 * введена в эксплуатацию не с января (первый год внедрения), «оклад_год»/«стимул_год» ЗА МЕСЯЦЫ
 * ДО старта берутся из фактически введённых сумм (dept_budgets.actual_oklad_before/actual_stimul_before —
 * бухгалтерия вводит их точно, по источникам), а НЕ из проекции текущих ставок задним числом.
 * Проекция по текущим ставкам считается только на месяцы [start_month..12]. При start_month=1
 * (по умолчанию, все прошлые годы без явной настройки) actual_*_before=0 и формулы совпадают
 * с прежними — регресс отсутствует.
 */
class StimulusBudgetService
{
    /** С какого месяца ведётся полный расчёт бюджета за год (1 = с начала года, обычная ситуация). */
    public static function startMonth(int $year): int
    {
        $m = (int) (Database::scalar('SELECT start_month FROM budget_year_settings WHERE year=?', [$year]) ?: 1);
        return $m >= 1 && $m <= 12 ? $m : 1;
    }

    public static function setStartMonth(int $year, int $month): void
    {
        $month = max(1, min(12, $month));
        $exists = Database::scalar('SELECT year FROM budget_year_settings WHERE year=?', [$year]);
        if ($exists !== false) {
            Database::run('UPDATE budget_year_settings SET start_month=? WHERE year=?', [$month, $year]);
        } else {
            Database::insert('INSERT INTO budget_year_settings (year, start_month) VALUES (?,?)', [$year, $month]);
        }
    }

    public static function forecast(int $deptId, string $period): array
    {
        [$yy, $mm] = array_map('intval', explode('-', $period . '-01'));
        if ($yy < 2000) { $yy = (int) date('Y'); }
        if ($mm < 1 || $mm > 12) { $mm = (int) date('m'); }
        $monthsLeft = 12 - $mm + 1; // включая текущий месяц
        $startMonth = self::startMonth($yy);

        // Годовой бюджет ФОТ отдела (сумма по источникам) + факт «до старта» (по окладу/стимулу).
        $budgetRow = Database::one(
            'SELECT COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(actual_oklad_before),0) AS ao, COALESCE(SUM(actual_stimul_before),0) AS as_
               FROM dept_budgets WHERE department_id=? AND year=?', [$deptId, $yy]);
        $budget = (float) ($budgetRow['amount'] ?? 0);
        $okladActualBefore = round((float) ($budgetRow['ao'] ?? 0), 2);
        $stimActualBefore = round((float) ($budgetRow['as_'] ?? 0), 2);

        // Сотрудники отдела (оклад берём по должности, иначе личный).
        $emps = Database::all(
            'SELECT u.id, u.rate_volume, u.allowance, u.oklad, u.hire_date, u.fire_date, p.oklad AS pos_oklad
               FROM users u LEFT JOIN positions p ON p.id=u.position_id
              WHERE u.department_id=? AND u.is_active=1', [$deptId]);

        // Оклад в бюджет отдела — пропорционально занятости: дни до приёма и после увольнения не списываются.
        // okladMonthly — по доле текущего месяца; okladProjected — сумма помесячных долей ЗА МЕСЯЦЫ
        // [start_month..12] (проекция текущих ставок только на период ответственности системы);
        // okladPart (отпускной резерв) — по ПОЛНОЙ годовой доле (резерв — не зависит от старта учёта).
        $okladPart = 0.0;
        $okladMonthly = 0.0;
        $okladProjected = 0.0;
        foreach ($emps as $e) {
            $okl = $e['pos_oklad'] !== null ? (float) $e['pos_oklad'] : (float) ($e['oklad'] ?? 0);
            $base = $okl * (float) ($e['rate_volume'] ?? 1);
            $fix = (float) Database::scalar(
                'SELECT COALESCE(SUM(monthly_amount),0) FROM employee_fixed_extras WHERE employee_id=? AND is_active=1',
                [$e['id']]);
            $monthlyFull = $base + (float) ($e['allowance'] ?? 0) + $fix;
            $hire = $e['hire_date'] ?? null; $fire = $e['fire_date'] ?? null;
            $mp = \App\Services\PayrollService::employmentProrate($period, $hire, $fire);            // доля текущего месяца
            $yfFull = \App\Services\PayrollService::employmentYearFactor($yy, $hire, $fire);          // фактор ПОЛНОГО года (0..12) — для резерва отпуска
            $yfFromStart = \App\Services\PayrollService::employmentYearFactor($yy, $hire, $fire, $startMonth); // фактор [start_month..12] — для проекции оклада
            $okladMonthly   += $monthlyFull * $mp;
            $okladProjected += $monthlyFull * $yfFromStart;
            $okladPart      += $base * ($yfFull / 12.0);
        }
        $okladPart = round($okladPart, 2);
        $okladMonthly = round($okladMonthly, 2);
        $okladProjected = round($okladProjected, 2);
        $okladYear = round($okladActualBefore + $okladProjected, 2);

        // Действующий ежемесячный стимул: уровень текущего периода (утв. служебки) — проекция
        // только на месяцы [start_month..12]; месяцы до старта — из введённого факта.
        $stimMonthly = (float) (Database::scalar(
            "SELECT COALESCE(SUM(l.amount),0)
               FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id
              WHERE m.department_id=? AND m.period=? AND m.pay_kind='monthly' AND m.status='approved'",
            [$deptId, $period]) ?: 0);
        $stimMonthly = round($stimMonthly, 2);
        $stimProjected = round($stimMonthly * (12 - $startMonth + 1), 2);
        $stimYear = round($stimActualBefore + $stimProjected, 2);

        // Плановый резерв на отпуск: средний отпуск 28 кал. дней ≈ один месячный оклад на сотрудника в год.
        $vacCoeff = (float) Settings::get('vacation_reserve_coeff', 0.955); // 28/29.3
        $vacation = round($okladPart * $vacCoeff, 2);

        $remainder = round($budget - $okladYear - $stimYear - $vacation, 2);

        return [
            'year'          => $yy,
            'period'        => $period,
            'months_left'   => $monthsLeft,
            'start_month'   => $startMonth,
            'people'        => count($emps),
            'budget'        => $budget,
            'oklad_monthly' => $okladMonthly,
            'oklad_year'    => $okladYear,
            'oklad_projected'      => $okladProjected,
            'oklad_actual_before'  => $okladActualBefore,
            'oklad_part'    => $okladPart,
            'stim_monthly'  => $stimMonthly,
            'stim_year'     => $stimYear,
            'stim_projected'     => $stimProjected,
            'stim_actual_before' => $stimActualBefore,
            'vacation'      => $vacation,
            'vac_coeff'     => $vacCoeff,
            'remainder'     => $remainder,
            'has_budget'    => $budget > 0,
        ];
    }

    /**
     * Фактический годовой расход стимула отдела по источникам: [source_id => Σ эффективных сумм].
     * Учитываются все НЕотклонённые служебки (утв. + в работе + черновики) за год; эффективная
     * сумма строки = последняя корректировка (override) либо исходная amount. К этому добавляется
     * точно введённый факт использования стимула ДО старта расчёта (dept_budgets.actual_stimul_before,
     * по источнику) — это тоже уже потраченные из бюджета года деньги.
     */
    public static function committedBySource(int $deptId, int $year, ?int $excludeMemoId = null): array
    {
        $where = "m.department_id = ? AND substr(m.period,1,4) = ? AND m.status <> 'rejected'";
        $params = [$deptId, (string) $year];
        if ($excludeMemoId) { $where .= ' AND m.id <> ?'; $params[] = $excludeMemoId; }
        $rows = Database::all(
            "SELECT m.source_id,
                    (SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS ov,
                    l.amount
               FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id
              WHERE $where", $params);
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) ($r['source_id'] ?? 0);
            $eff = $r['ov'] !== null ? (float) $r['ov'] : (float) $r['amount'];
            $out[$sid] = ($out[$sid] ?? 0.0) + $eff;
        }
        foreach (Database::all('SELECT source_id, actual_stimul_before FROM dept_budgets WHERE department_id=? AND year=?', [$deptId, $year]) as $b) {
            $sid = (int) $b['source_id'];
            $out[$sid] = ($out[$sid] ?? 0.0) + (float) $b['actual_stimul_before'];
        }
        return $out;
    }

    /**
     * Бюджет отдела в разрезе источников на год периода:
     * по каждому источнику — бюджет, «база для стимула» (бюджет − доля оклада/отпуска),
     * занято (committed) и доступно. ПРОЕКЦИЯ оклада (за месяцы после старта) и резерв отпуска
     * распределяются по источникам пропорционально доле бюджета (как раньше — для них нет точных
     * данных по источнику); а введённый бухгалтерией факт «до старта» (actual_oklad_before) вычитается
     * ТОЧНО по своему источнику, не долей — эти данные точнее приближения.
     */
    public static function sourceBreakdown(int $deptId, string $period, ?int $excludeMemoId = null): array
    {
        $f = self::forecast($deptId, $period);
        $year = (int) $f['year'];
        $overheadProjected = (float) $f['oklad_projected'] + (float) $f['vacation']; // распределяем по доле бюджета
        $committed = self::committedBySource($deptId, $year, $excludeMemoId); // уже включает actual_stimul_before по источнику

        $budgets = []; $okladActualBySource = [];
        foreach (Database::all('SELECT source_id, amount, actual_oklad_before FROM dept_budgets WHERE department_id=? AND year=?', [$deptId, $year]) as $b) {
            $budgets[(int) $b['source_id']] = (float) $b['amount'];
            $okladActualBySource[(int) $b['source_id']] = (float) $b['actual_oklad_before'];
        }
        $budgetTotal = array_sum($budgets);

        $rows = [];
        foreach (Database::all('SELECT id, name, detail FROM pay_sources ORDER BY id') as $s) {
            $sid = (int) $s['id'];
            $bud = $budgets[$sid] ?? 0.0;
            $share = $budgetTotal > 0 ? $bud / $budgetTotal : 0.0;
            $okladActual = $okladActualBySource[$sid] ?? 0.0;
            $base = round($bud - $okladActual - $overheadProjected * $share, 2);
            $com = round($committed[$sid] ?? 0.0, 2);
            $rows[] = [
                'id' => $sid, 'name' => $s['name'], 'detail' => $s['detail'],
                'budget' => round($bud, 2), 'base' => $base, 'committed' => $com,
                'available' => round($base - $com, 2),
            ];
        }
        return ['rows' => $rows, 'has_budget' => $budgetTotal > 0, 'year' => $year, 'budget_total' => round($budgetTotal, 2)];
    }

    /** Доступно по конкретному источнику: [base, committed, available, has_budget]. */
    public static function availableForSource(int $deptId, int $sourceId, string $period, ?int $excludeMemoId = null): array
    {
        $bd = self::sourceBreakdown($deptId, $period, $excludeMemoId);
        foreach ($bd['rows'] as $r) {
            if ((int) $r['id'] === $sourceId) {
                return ['base' => $r['base'], 'committed' => $r['committed'], 'available' => $r['available'], 'has_budget' => $bd['has_budget']];
            }
        }
        return ['base' => 0.0, 'committed' => 0.0, 'available' => 0.0, 'has_budget' => $bd['has_budget']];
    }
}
