<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Xlsx;

/**
 * Сменный график колл-центра (2/2): план и факт по дням (часы, ночные; факт ещё праздничные/сверхурочные).
 * Заполняется на первую половину (1–15), вторую (16–конец) или весь месяц. ЗП по этим часам считает PayrollService.
 */
class ShiftController extends Controller
{
    /** Видит весь список 2/2 (админ/кадры/бухгалтерия/орг-табельщик). */
    private function seesAll(array $me): bool
    {
        return in_array($me['role'], ['admin', 'manager'], true)
            || (int) ($me['is_timekeeper_org'] ?? 0) === 1
            || (int) ($me['is_hr'] ?? 0) === 1
            || (int) ($me['is_accountant'] ?? 0) === 1
            || Auth::has('hr_manager', 'accountant', 'director', 'deputy_director');
    }

    /** Отделы, по которым пользователь ведёт график (если не видит всё). */
    private function scopeDepts(array $me): array
    {
        $depts = [];
        foreach (Database::all('SELECT id FROM departments WHERE head_id = ?', [$me['id']]) as $d) { $depts[] = (int) $d['id']; }
        if (!empty($me['timekeeper_dept_id'])) { $depts[] = (int) $me['timekeeper_dept_id']; }
        return array_values(array_unique($depts));
    }

    private function canEdit(array $me): bool
    {
        return in_array($me['role'], ['admin', 'manager'], true)
            || (int) ($me['is_timekeeper_org'] ?? 0) === 1
            || (int) ($me['is_hr'] ?? 0) === 1
            || Auth::has('hr_manager')
            || $this->scopeDepts($me) !== [];
    }

    private function canView(array $me): bool
    {
        return $this->canEdit($me) || (int) ($me['is_accountant'] ?? 0) === 1 || Auth::has('accountant', 'director', 'deputy_director');
    }

    private function inScope(array $me, array $emp): bool
    {
        if ($this->seesAll($me)) { return true; }
        return !empty($emp['department_id']) && in_array((int) $emp['department_id'], $this->scopeDepts($me), true);
    }

    /** Активные сотрудники на графике 2/2 в области видимости. */
    private function employees(array $me): array
    {
        if ($this->seesAll($me)) {
            return Database::all("SELECT u.id, u.full_name, u.oklad, u.position, u.department_id, d.name AS dept_name
                FROM users u LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.schedule_type='2_2' AND u.is_active=1 ORDER BY d.name, u.full_name");
        }
        $depts = $this->scopeDepts($me);
        if (!$depts) { return []; }
        $ph = implode(',', array_fill(0, count($depts), '?'));
        return Database::all("SELECT u.id, u.full_name, u.oklad, u.position, u.department_id, d.name AS dept_name
            FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.schedule_type='2_2' AND u.is_active=1 AND u.department_id IN ($ph) ORDER BY d.name, u.full_name", $depts);
    }

    /** [start,end] по месяцу YYYY-MM и диапазону h1|h2|full. */
    private function range(string $month, string $r): array
    {
        $start = $r === 'h2' ? "$month-16" : "$month-01";
        $end   = $r === 'h1' ? "$month-15" : date('Y-m-t', strtotime("$month-01"));
        return [$start, $end];
    }

    private function reqRange(): string
    {
        $r = (string) $this->input('range');
        return in_array($r, ['h1', 'h2', 'full'], true) ? $r : 'full';
    }

    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { flash('Раздел сменного графика (колл-центр).', 'error'); $this->redirect('/'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $emps = $this->employees($me);
        foreach ($emps as &$e) {
            $e['plan'] = (float) Database::scalar("SELECT COALESCE(SUM(plan_hours),0) FROM shift_days WHERE employee_id=? AND substr(work_date,1,7)=?", [$e['id'], $month]);
            $e['fact'] = (float) Database::scalar("SELECT COALESCE(SUM(fact_hours),0) FROM shift_days WHERE employee_id=? AND substr(work_date,1,7)=?", [$e['id'], $month]);
        }
        unset($e);
        $this->view('shifts/index', [
            'title'   => 'Сменный график (2/2)',
            'month'   => $month,
            'range'   => $this->reqRange(),
            'emps'    => $emps,
            'canEdit' => $this->canEdit($me),
        ]);
    }

    public function edit(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав на правку графика.', 'error'); $this->redirect('/shifts'); }
        $eid = (int) $this->input('employee');
        $emp = Database::one("SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND u.schedule_type='2_2'", [$eid]);
        if (!$emp) { flash('Сотрудник не найден или не на графике 2/2.', 'error'); $this->redirect('/shifts'); }
        if (!$this->inScope($me, $emp)) { flash('Нет прав на этого сотрудника.', 'error'); $this->redirect('/shifts'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $r = $this->reqRange();
        $mode = $this->input('mode') === 'fact' ? 'fact' : 'plan';
        [$start, $end] = $this->range($month, $r);
        $dates = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) { $dates[] = date('Y-m-d', $ts); }
        $existing = [];
        foreach (Database::all("SELECT * FROM shift_days WHERE employee_id=? AND substr(work_date,1,7)=?", [$eid, $month]) as $row) {
            $existing[$row['work_date']] = $row;
        }
        $this->view('shifts/edit', [
            'title'    => 'График: ' . $emp['full_name'],
            'emp'      => $emp,
            'month'    => $month, 'range' => $r, 'mode' => $mode,
            'dates'    => $dates, 'existing' => $existing,
            'rate'     => (float) $emp['oklad'],
            'csrf'     => Auth::csrf(),
        ]);
    }

    public function save(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав.', 'error'); $this->redirect('/shifts'); }
        $eid = (int) $this->input('employee');
        $emp = Database::one("SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND u.schedule_type='2_2'", [$eid]);
        if (!$emp || !$this->inScope($me, $emp)) { flash('Сотрудник вне доступа или не на 2/2.', 'error'); $this->redirect('/shifts'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $r = $this->reqRange();
        $mode = $this->input('mode') === 'fact' ? 'fact' : 'plan';
        $now = date('Y-m-d H:i:s');
        $num = fn($v) => max(0.0, round((float) str_replace(',', '.', (string) $v), 2));
        $d = $_POST['d'] ?? []; // d[YYYY-MM-DD][hours|night|holiday|overtime]
        $saved = 0;
        foreach ($d as $date => $vals) {
            if (substr((string) $date, 0, 7) !== $month) { continue; }   // только текущий месяц
            $hours = $num($vals['hours'] ?? 0);
            $night = min($hours, $num($vals['night'] ?? 0));            // ночных не больше часов за день
            $ex = Database::scalar('SELECT id FROM shift_days WHERE employee_id=? AND work_date=?', [$eid, $date]);
            if ($mode === 'plan') {
                if ($ex) { Database::run('UPDATE shift_days SET plan_hours=?, plan_night=?, updated_at=? WHERE id=?', [$hours, $night, $now, $ex]); }
                else     { Database::insert('INSERT INTO shift_days (employee_id, work_date, plan_hours, plan_night) VALUES (?,?,?,?)', [$eid, $date, $hours, $night]); }
            } else {
                $hol = min($hours, $num($vals['holiday'] ?? 0));
                $ovt = min($hours, $num($vals['overtime'] ?? 0));
                if ($ex) { Database::run('UPDATE shift_days SET fact_hours=?, fact_night=?, holiday_hours=?, overtime_hours=?, updated_at=? WHERE id=?', [$hours, $night, $hol, $ovt, $now, $ex]); }
                else     { Database::insert('INSERT INTO shift_days (employee_id, work_date, fact_hours, fact_night, holiday_hours, overtime_hours) VALUES (?,?,?,?,?,?)', [$eid, $date, $hours, $night, $hol, $ovt]); }
            }
            $saved++;
        }
        flash(($mode === 'plan' ? 'План' : 'Факт') . " графика сохранён (дней: {$saved}).");
        $this->redirect('/shifts/edit?employee=' . $eid . '&month=' . urlencode($month) . '&range=' . $r . '&mode=' . $mode);
    }

    public function export(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { $this->redirect('/'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $r = $this->reqRange();
        [$start, $end] = $this->range($month, $r);
        $dnums = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) { $dnums[] = date('d', $ts); }
        $rows = [];
        foreach ($this->employees($me) as $e) {
            $byDate = [];
            foreach (Database::all("SELECT work_date, plan_hours, fact_hours FROM shift_days WHERE employee_id=? AND substr(work_date,1,7)=?", [$e['id'], $month]) as $sd) {
                $byDate[$sd['work_date']] = $sd;
            }
            $line = [$e['dept_name'] ?: '—', $e['full_name']];
            $sumP = 0.0; $sumF = 0.0;
            for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) {
                $dt = date('Y-m-d', $ts); $sd = $byDate[$dt] ?? null;
                $ph = (float) ($sd['plan_hours'] ?? 0); $fh = (float) ($sd['fact_hours'] ?? 0);
                $line[] = $fh > 0 ? $fh : ($ph > 0 ? $ph : '');
                $sumP += $ph; $sumF += $fh;
            }
            $line[] = $sumP; $line[] = $sumF;
            $rows[] = $line;
        }
        Xlsx::download("shifts-$month-$r.xlsx", [[
            'name'    => 'Сменный график',
            'headers' => array_merge(['Отдел', 'ФИО'], $dnums, ['План ч', 'Факт ч']),
            'rows'    => $rows,
        ]]);
    }
}
