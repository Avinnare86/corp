<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Tariff;
use App\Services\StimulusBudgetService;

/**
 * Бюджетирование ФОТ по отделам и источникам (госзадание/целевая субсидия/внебюджет).
 * База для премий (план окладной части) считается ЕДИНОЙ формулой StimulusBudgetService::forecast()
 * (с учётом приёма/увольнения и старт-месяца расчёта — см. дальше), а не отдельно на этой странице,
 * чтобы верхний свод и разбивка по источникам не расходились в цифрах.
 * «Факт сдельных» — фактические сдельные начисления с начала года (не зависит от старт-месяца:
 * данных в БД просто нет за месяцы до включения системы в эксплуатацию).
 */
class BudgetController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        $year = (int) ($this->input('year') ?: date('Y'));
        $curYear = (int) date('Y');
        // Для текущего года — живой месяц (действующий стимул этого месяца); для другого года — январь.
        $forecastPeriod = $year === $curYear ? date('Y-m') : $year . '-01';
        $startMonth = StimulusBudgetService::startMonth($year);
        $sources = Database::all('SELECT * FROM pay_sources ORDER BY id');
        $departments = Database::all('SELECT id, name FROM departments ORDER BY name');

        $rows = [];
        $totals = ['budget' => 0.0, 'plan' => 0.0, 'fact' => 0.0, 'base' => 0.0];
        foreach ($departments as $d) {
            $deptId = (int) $d['id'];
            // бюджет + факт «до старта» по источникам
            $bySource = []; $actualOkladBySource = []; $actualStimulBySource = [];
            $budget = 0.0;
            foreach ($sources as $s) {
                $b = Database::one('SELECT amount, actual_oklad_before, actual_stimul_before FROM dept_budgets WHERE department_id=? AND year=? AND source_id=?',
                    [$deptId, $year, $s['id']]);
                $bySource[$s['id']] = (float) ($b['amount'] ?? 0);
                $actualOkladBySource[$s['id']] = (float) ($b['actual_oklad_before'] ?? 0);
                $actualStimulBySource[$s['id']] = (float) ($b['actual_stimul_before'] ?? 0);
                $budget += $bySource[$s['id']];
            }

            // план окладной части (год) — единая формула с прогнозом бюджета (учитывает старт-месяц)
            $f = StimulusBudgetService::forecast($deptId, $forecastPeriod);
            $plan = (float) $f['oklad_year'];

            // факт сдельных начислений за год: анкеты по тарифам + операции (не зависит от старт-месяца —
            // до включения системы в эксплуатацию данных в этих таблицах попросту нет)
            $emps = Database::all('SELECT id FROM users WHERE department_id=? AND is_active=1', [$deptId]);
            $fact = 0.0;
            foreach ($emps as $e) {
                $byCountry = Database::all(
                    "SELECT country_code, substr(checked_at,1,10) AS d, COUNT(*) cnt FROM assignment_items
                      WHERE assigned_to=? AND checked_at IS NOT NULL AND substr(checked_at,1,4)=? GROUP BY country_code, d",
                    [$e['id'], (string) $year]);
                foreach ($byCountry as $c) { $fact += (int) $c['cnt'] * Tariff::priceForCountry($c['country_code']) * Tariff::dayCoeff((string) $c['d']); }
                $fact += (float) Database::scalar(
                    "SELECT COALESCE(SUM(pw.quantity*o.unit_price),0) FROM piecework pw JOIN operations o ON o.id=pw.operation_id
                      WHERE pw.employee_id=? AND substr(pw.work_date,1,4)=?", [$e['id'], (string) $year]);
            }
            $fact = round($fact, 2);
            $base = round($budget - $plan - $fact, 2);

            // Раздельный учёт по источникам: доступно/занято стимулом (тот же расчёт, что и в форме служебки).
            $srcInfo = [];
            $bd = StimulusBudgetService::sourceBreakdown($deptId, $forecastPeriod);
            foreach ($bd['rows'] as $br) { $srcInfo[(int) $br['id']] = $br; }

            $rows[] = [
                'dept' => $d, 'bySource' => $bySource, 'actualOklad' => $actualOkladBySource, 'actualStimul' => $actualStimulBySource,
                'budget' => $budget, 'plan' => $plan, 'fact' => $fact, 'base' => $base, 'people' => count($emps),
                'srcInfo' => $srcInfo,
            ];
            $totals['budget'] += $budget; $totals['plan'] += $plan; $totals['fact'] += $fact; $totals['base'] += $base;
        }

        $this->view('budget/index', [
            'title' => 'Бюджет ФОТ',
            'year' => $year, 'startMonth' => $startMonth,
            'sources' => $sources,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    public function save(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        $year = (int) $this->input('year');
        $amounts = $_POST['amount'] ?? []; // amount[dept][source]
        $actualOklad = $_POST['actual_oklad_before'] ?? [];
        $actualStimul = $_POST['actual_stimul_before'] ?? [];
        $depts = array_unique(array_merge(array_keys((array) $amounts), array_keys((array) $actualOklad), array_keys((array) $actualStimul)));
        foreach ($depts as $deptId) {
            $srcIds = array_unique(array_merge(
                array_keys((array) ($amounts[$deptId] ?? [])),
                array_keys((array) ($actualOklad[$deptId] ?? [])),
                array_keys((array) ($actualStimul[$deptId] ?? []))
            ));
            foreach ($srcIds as $srcId) {
                $val = (float) str_replace(',', '.', (string) ($amounts[$deptId][$srcId] ?? 0));
                $ao = (float) str_replace(',', '.', (string) ($actualOklad[$deptId][$srcId] ?? 0));
                $as = (float) str_replace(',', '.', (string) ($actualStimul[$deptId][$srcId] ?? 0));
                $ex = Database::scalar('SELECT id FROM dept_budgets WHERE department_id=? AND year=? AND source_id=?', [(int) $deptId, $year, (int) $srcId]);
                if ($ex) {
                    Database::run('UPDATE dept_budgets SET amount=?, actual_oklad_before=?, actual_stimul_before=? WHERE id=?', [$val, $ao, $as, $ex]);
                } else {
                    Database::insert('INSERT INTO dept_budgets (department_id, year, source_id, amount, actual_oklad_before, actual_stimul_before) VALUES (?,?,?,?,?,?)',
                        [(int) $deptId, $year, (int) $srcId, $val, $ao, $as]);
                }
            }
        }
        flash('Бюджеты сохранены.');
        $this->redirect('/budget?year=' . $year);
    }

    /** Год внедрения системы: с какого месяца ведётся полный расчёт бюджета (по умолчанию — с января). */
    public function saveStartMonth(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        $year = (int) $this->input('year');
        $month = (int) $this->input('start_month', 1);
        StimulusBudgetService::setStartMonth($year, $month);
        flash('Старт-месяц расчёта на ' . $year . ' год сохранён.');
        $this->redirect('/budget?year=' . $year);
    }

    public function storeSource(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        $id = $this->input('id');
        $name = trim((string) $this->input('name'));
        if ($name === '') { flash('Укажите название источника.', 'error'); $this->redirect('/budget'); }
        $kind = in_array($this->input('kind'), ['gz', 'subsidy', 'vneb'], true) ? $this->input('kind') : 'vneb';
        $detail = trim((string) $this->input('detail'));
        if ($id) { Database::run('UPDATE pay_sources SET name=?, kind=?, detail=? WHERE id=?', [$name, $kind, $detail, $id]); }
        else { Database::insert('INSERT INTO pay_sources (name, kind, detail) VALUES (?,?,?)', [$name, $kind, $detail]); }
        flash('Источник сохранён.');
        $this->redirect('/budget');
    }

    public function deleteSource(string $id): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        if (Database::scalar('SELECT 1 FROM dept_budgets WHERE source_id = ?', [$id])) {
            flash('Источник используется в бюджетах — удалить нельзя.', 'error');
        } else {
            Database::run('DELETE FROM pay_sources WHERE id = ?', [$id]);
            flash('Источник удалён.');
        }
        $this->redirect('/budget');
    }
}
