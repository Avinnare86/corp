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
 */
class StimulusBudgetService
{
    public static function forecast(int $deptId, string $period): array
    {
        [$yy, $mm] = array_map('intval', explode('-', $period . '-01'));
        if ($yy < 2000) { $yy = (int) date('Y'); }
        if ($mm < 1 || $mm > 12) { $mm = (int) date('m'); }
        $monthsLeft = 12 - $mm + 1; // включая текущий месяц

        // Годовой бюджет ФОТ отдела (сумма по источникам).
        $budget = (float) (Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM dept_budgets WHERE department_id=? AND year=?',
            [$deptId, $yy]) ?: 0);

        // Сотрудники отдела (оклад берём по должности, иначе личный).
        $emps = Database::all(
            'SELECT u.id, u.rate_volume, u.allowance, u.oklad, p.oklad AS pos_oklad
               FROM users u LEFT JOIN positions p ON p.id=u.position_id
              WHERE u.department_id=? AND u.is_active=1', [$deptId]);

        $okladPart = 0.0;   // оклад×ставка (база для отпускных)
        $monthly   = 0.0;   // оклад×ставка + надбавка + фикс-доплаты
        foreach ($emps as $e) {
            $okl = $e['pos_oklad'] !== null ? (float) $e['pos_oklad'] : (float) ($e['oklad'] ?? 0);
            $base = $okl * (float) ($e['rate_volume'] ?? 1);
            $fix = (float) Database::scalar(
                'SELECT COALESCE(SUM(monthly_amount),0) FROM employee_fixed_extras WHERE employee_id=? AND is_active=1',
                [$e['id']]);
            $okladPart += $base;
            $monthly   += $base + (float) ($e['allowance'] ?? 0) + $fix;
        }
        $okladPart = round($okladPart, 2);
        $okladMonthly = round($monthly, 2);
        $okladYear = round($okladMonthly * 12, 2);

        // Действующий ежемесячный стимул: уровень текущего периода (утв. служебки), ×12.
        $stimMonthly = (float) (Database::scalar(
            "SELECT COALESCE(SUM(l.amount),0)
               FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id
              WHERE m.department_id=? AND m.period=? AND m.pay_kind='monthly' AND m.status='approved'",
            [$deptId, $period]) ?: 0);
        $stimMonthly = round($stimMonthly, 2);
        $stimYear = round($stimMonthly * 12, 2);

        // Плановый резерв на отпуск: средний отпуск 28 кал. дней ≈ один месячный оклад на сотрудника в год.
        $vacCoeff = (float) Settings::get('vacation_reserve_coeff', 0.955); // 28/29.3
        $vacation = round($okladPart * $vacCoeff, 2);

        $remainder = round($budget - $okladYear - $stimYear - $vacation, 2);

        return [
            'year'          => $yy,
            'period'        => $period,
            'months_left'   => $monthsLeft,
            'people'        => count($emps),
            'budget'        => $budget,
            'oklad_monthly' => $okladMonthly,
            'oklad_year'    => $okladYear,
            'oklad_part'    => $okladPart,
            'stim_monthly'  => $stimMonthly,
            'stim_year'     => $stimYear,
            'vacation'      => $vacation,
            'vac_coeff'     => $vacCoeff,
            'remainder'     => $remainder,
            'has_budget'    => $budget > 0,
        ];
    }

    /**
     * Фактический годовой расход стимула отдела по источникам: [source_id => Σ эффективных сумм].
     * Учитываются все НЕотклонённые служебки (утв. + в работе + черновики) за год; эффективная
     * сумма строки = последняя корректировка (override) либо исходная amount.
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
        return $out;
    }

    /**
     * Бюджет отдела в разрезе источников на год периода:
     * по каждому источнику — бюджет, «база для стимула» (бюджет − доля оклада/отпуска),
     * занято (committed) и доступно. Оклад+отпуск распределяются пропорционально доле бюджета источника.
     */
    public static function sourceBreakdown(int $deptId, string $period, ?int $excludeMemoId = null): array
    {
        $f = self::forecast($deptId, $period);
        $year = (int) $f['year'];
        $overhead = (float) $f['oklad_year'] + (float) $f['vacation'];   // распределяем по доле бюджета
        $committed = self::committedBySource($deptId, $year, $excludeMemoId);

        $budgets = [];
        foreach (Database::all('SELECT source_id, amount FROM dept_budgets WHERE department_id=? AND year=?', [$deptId, $year]) as $b) {
            $budgets[(int) $b['source_id']] = (float) $b['amount'];
        }
        $budgetTotal = array_sum($budgets);

        $rows = [];
        foreach (Database::all('SELECT id, name, detail FROM pay_sources ORDER BY id') as $s) {
            $sid = (int) $s['id'];
            $bud = $budgets[$sid] ?? 0.0;
            $share = $budgetTotal > 0 ? $bud / $budgetTotal : 0.0;
            $base = round($bud - $overhead * $share, 2);
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
