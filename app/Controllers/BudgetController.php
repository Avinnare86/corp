<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Tariff;

/**
 * Бюджетирование ФОТ по отделам и источникам (госзадание/целевая субсидия/внебюджет).
 * База для премий = бюджет года − план окладной части (оклад+надбавка+фикс ×12)
 *                   − фактические сдельные начисления с начала года.
 */
class BudgetController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        $year = (int) ($this->input('year') ?: date('Y'));
        $sources = Database::all('SELECT * FROM pay_sources ORDER BY id');
        $departments = Database::all('SELECT * FROM departments ORDER BY name');

        $rows = [];
        $totals = ['budget' => 0.0, 'plan' => 0.0, 'fact' => 0.0, 'base' => 0.0];
        foreach ($departments as $d) {
            $deptId = (int) $d['id'];
            // бюджет по источникам
            $bySource = [];
            $budget = 0.0;
            foreach ($sources as $s) {
                $a = (float) (Database::scalar(
                    'SELECT amount FROM dept_budgets WHERE department_id=? AND year=? AND source_id=?',
                    [$deptId, $year, $s['id']]) ?: 0);
                $bySource[$s['id']] = $a;
                $budget += $a;
            }
            // план: (оклад должности|свой)×ставка + надбавка + фикс-доплаты, ×12
            $emps = Database::all(
                'SELECT u.*, p.oklad AS pos_oklad FROM users u LEFT JOIN positions p ON p.id=u.position_id
                  WHERE u.department_id = ? AND u.is_active = 1', [$deptId]);
            $planMonthly = 0.0;
            foreach ($emps as $e) {
                $oklad = $e['pos_oklad'] !== null ? (float) $e['pos_oklad'] : (float) $e['oklad'];
                $fix = (float) Database::scalar(
                    'SELECT COALESCE(SUM(monthly_amount),0) FROM employee_fixed_extras WHERE employee_id=? AND is_active=1',
                    [$e['id']]);
                $planMonthly += $oklad * (float) $e['rate_volume'] + (float) $e['allowance'] + $fix;
            }
            $plan = round($planMonthly * 12, 2);
            // факт сдельных начислений за год: анкеты по тарифам + операции
            $fact = 0.0;
            foreach ($emps as $e) {
                $byCountry = Database::all(
                    "SELECT country_code, COUNT(*) cnt FROM assignment_items
                      WHERE assigned_to=? AND checked_at IS NOT NULL AND substr(checked_at,1,4)=? GROUP BY country_code",
                    [$e['id'], (string) $year]);
                foreach ($byCountry as $c) { $fact += (int) $c['cnt'] * Tariff::priceForCountry($c['country_code']); }
                $fact += (float) Database::scalar(
                    "SELECT COALESCE(SUM(pw.quantity*o.unit_price),0) FROM piecework pw JOIN operations o ON o.id=pw.operation_id
                      WHERE pw.employee_id=? AND substr(pw.work_date,1,4)=?", [$e['id'], (string) $year]);
            }
            $fact = round($fact, 2);
            $base = round($budget - $plan - $fact, 2);

            $rows[] = [
                'dept' => $d, 'bySource' => $bySource, 'budget' => $budget,
                'plan' => $plan, 'fact' => $fact, 'base' => $base, 'people' => count($emps),
            ];
            $totals['budget'] += $budget; $totals['plan'] += $plan; $totals['fact'] += $fact; $totals['base'] += $base;
        }

        $this->view('budget/index', [
            'title' => 'Бюджет ФОТ',
            'year' => $year,
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
        foreach ($amounts as $deptId => $srcs) {
            foreach ((array) $srcs as $srcId => $val) {
                $val = (float) str_replace(',', '.', (string) $val);
                $ex = Database::scalar('SELECT id FROM dept_budgets WHERE department_id=? AND year=? AND source_id=?', [(int)$deptId, $year, (int)$srcId]);
                if ($ex) { Database::run('UPDATE dept_budgets SET amount=? WHERE id=?', [$val, $ex]); }
                else { Database::insert('INSERT INTO dept_budgets (department_id, year, source_id, amount) VALUES (?,?,?,?)', [(int)$deptId, $year, (int)$srcId, $val]); }
            }
        }
        flash('Бюджеты сохранены.');
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
