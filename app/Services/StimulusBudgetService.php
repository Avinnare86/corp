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
}
