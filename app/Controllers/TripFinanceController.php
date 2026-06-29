<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\TripBudgetService as TB;
use App\Services\TripService as TS;

/**
 * Финансовые настройки командировок (менеджер финансов): бюджет по отделам и источникам,
 * ставки суточных (источник × РФ/зарубеж), справочник дополнительных расходов.
 */
class TripFinanceController extends Controller
{
    private function gate(): void { Auth::requireRole('admin', 'finance_manager'); }

    public function index(): void
    {
        $this->gate();
        $year = (int) ($this->input('year') ?: date('Y'));
        $sources     = Database::all('SELECT * FROM pay_sources ORDER BY id');
        $departments = Database::all('SELECT * FROM departments ORDER BY name');

        $budget = [];
        foreach (Database::all('SELECT * FROM trip_budgets WHERE year = ?', [$year]) as $b) {
            $budget[(int) $b['department_id']][(int) $b['source_id']] = (float) $b['amount'];
        }
        // занято/остаток по каждой ячейке
        $committed = [];
        foreach ($departments as $d) {
            foreach ($sources as $s) {
                $committed[(int) $d['id']][(int) $s['id']] = TB::committed((int) $d['id'], (int) $s['id'], $year);
            }
        }
        $perDiem = [];
        foreach (Database::all('SELECT * FROM per_diem_rates') as $r) {
            $perDiem[(int) $r['source_id']][$r['location']] = (float) $r['amount'];
        }

        $this->view('trip/finance', [
            'title'       => 'Командировки — бюджет и суточные',
            'year'        => $year,
            'sources'     => $sources,
            'departments' => $departments,
            'budget'      => $budget,
            'committed'   => $committed,
            'perDiem'     => $perDiem,
            'locLabels'   => TS::LOC,
            'kinds'       => Database::all('SELECT * FROM trip_expense_kinds ORDER BY is_active DESC, name'),
            'csrf'        => Auth::csrf(),
        ]);
    }

    public function saveBudget(): void
    {
        $this->gate(); Auth::verifyCsrf();
        $year = (int) $this->input('year');
        foreach ((array) ($_POST['amount'] ?? []) as $deptId => $srcs) {
            foreach ((array) $srcs as $srcId => $val) {
                $v = (float) str_replace(',', '.', (string) $val);
                $ex = Database::scalar('SELECT id FROM trip_budgets WHERE department_id=? AND year=? AND source_id=?', [(int) $deptId, $year, (int) $srcId]);
                if ($ex) { Database::run('UPDATE trip_budgets SET amount=? WHERE id=?', [$v, $ex]); }
                else { Database::insert('INSERT INTO trip_budgets (department_id, year, source_id, amount) VALUES (?,?,?,?)', [(int) $deptId, $year, (int) $srcId, $v]); }
            }
        }
        flash('Бюджет командировок сохранён.');
        $this->redirect('/trip-finance?year=' . $year);
    }

    public function savePerDiem(): void
    {
        $this->gate(); Auth::verifyCsrf();
        foreach ((array) ($_POST['rate'] ?? []) as $srcId => $locs) {
            foreach ((array) $locs as $loc => $val) {
                if (!in_array($loc, ['rf', 'abroad'], true)) { continue; }
                $v = (float) str_replace(',', '.', (string) $val);
                $ex = Database::scalar('SELECT id FROM per_diem_rates WHERE source_id=? AND location=?', [(int) $srcId, $loc]);
                if ($ex) { Database::run('UPDATE per_diem_rates SET amount=? WHERE id=?', [$v, $ex]); }
                else { Database::insert('INSERT INTO per_diem_rates (source_id, location, amount) VALUES (?,?,?)', [(int) $srcId, $loc, $v]); }
            }
        }
        flash('Ставки суточных сохранены.');
        $this->redirect('/trip-finance');
    }

    public function storeKind(): void
    {
        $this->gate(); Auth::verifyCsrf();
        $id = (int) $this->input('id');
        $name = trim((string) $this->input('name'));
        if ($name === '') { flash('Укажите название расхода.', 'error'); $this->redirect('/trip-finance'); }
        if ($id) { Database::run('UPDATE trip_expense_kinds SET name=? WHERE id=?', [$name, $id]); }
        else { Database::insert('INSERT INTO trip_expense_kinds (name, is_active) VALUES (?,1)', [$name]); }
        flash('Справочник доп.расходов обновлён.');
        $this->redirect('/trip-finance');
    }

    public function deleteKind(string $id): void
    {
        $this->gate(); Auth::verifyCsrf();
        if (Database::scalar('SELECT 1 FROM trip_extra_expenses WHERE kind_id = ?', [(int) $id])) {
            Database::run('UPDATE trip_expense_kinds SET is_active=0 WHERE id=?', [(int) $id]);
            flash('Вид расхода используется в заявках — деактивирован (скрыт).');
        } else {
            Database::run('DELETE FROM trip_expense_kinds WHERE id=?', [(int) $id]);
            flash('Вид расхода удалён.');
        }
        $this->redirect('/trip-finance');
    }
}
