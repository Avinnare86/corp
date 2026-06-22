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
    /** Отделы, где есть активные сотрудники на графике 2/2. */
    private static function shiftDeptIds(): array
    {
        return array_map(fn($r) => (int) $r['department_id'], Database::all(
            "SELECT DISTINCT department_id FROM users WHERE schedule_type='2_2' AND is_active=1 AND department_id IS NOT NULL"));
    }

    /**
     * Доступ к разделу (просмотр). Глобально — кадры, бухгалтерия, директор, орг-табельщик, админ.
     * Структурно — начальник, табельщик, курирующий зам или любой вышестоящий по структуре отдела,
     * где есть сотрудники 2/2 (Org::isSuperiorOfDept покрывает начальника отдела, куратора и всех выше).
     */
    public static function canSee(int $uid): bool
    {
        $me = Database::one('SELECT role, is_timekeeper_org, is_hr, is_accountant, timekeeper_dept_id FROM users WHERE id=?', [$uid]);
        if (!$me) { return false; }
        $r = Auth::roles($uid);
        if (in_array($me['role'], ['admin', 'manager'], true)
            || (int) ($me['is_timekeeper_org'] ?? 0) === 1 || (int) ($me['is_hr'] ?? 0) === 1 || (int) ($me['is_accountant'] ?? 0) === 1
            || !empty($r['hr_manager']) || !empty($r['hr']) || !empty($r['accountant']) || !empty($r['director'])) {
            return true;
        }
        $depts = self::shiftDeptIds();
        if (!$depts) { return false; }
        if (!empty($me['timekeeper_dept_id']) && in_array((int) $me['timekeeper_dept_id'], $depts, true)) { return true; }
        foreach ($depts as $d) { if (\App\Services\Org::isSuperiorOfDept($uid, $d)) { return true; } }
        return false;
    }

    /** Полный просмотр всего списка 2/2 (без ограничения по отделам). */
    private function seesAll(array $me): bool
    {
        $r = Auth::roles((int) $me['id']);
        return in_array($me['role'], ['admin', 'manager'], true)
            || (int) ($me['is_timekeeper_org'] ?? 0) === 1 || (int) ($me['is_hr'] ?? 0) === 1 || (int) ($me['is_accountant'] ?? 0) === 1
            || !empty($r['hr_manager']) || !empty($r['accountant']) || !empty($r['director']);
    }

    /** Полное РЕДАКТИРОВАНИЕ всех графиков (кадры/орг-табельщик/админ). */
    private function seesAllEdit(array $me): bool
    {
        $r = Auth::roles((int) $me['id']);
        return in_array($me['role'], ['admin', 'manager'], true)
            || (int) ($me['is_timekeeper_org'] ?? 0) === 1 || (int) ($me['is_hr'] ?? 0) === 1 || !empty($r['hr_manager']);
    }

    /** Отделы, которыми пользователь РЕДАКТИРУЕТ график (начальник/табельщик своего отдела). */
    private function scopeDepts(array $me): array
    {
        $depts = [];
        foreach (Database::all('SELECT id FROM departments WHERE head_id = ?', [$me['id']]) as $d) { $depts[] = (int) $d['id']; }
        if (!empty($me['timekeeper_dept_id'])) { $depts[] = (int) $me['timekeeper_dept_id']; }
        return array_values(array_unique($depts));
    }

    /** Отделы 2/2, доступные для ПРОСМОТРА (свои + курируемые/вышестоящие по структуре). */
    private function viewDepts(array $me): array
    {
        $depts = self::shiftDeptIds();
        if (!$depts) { return []; }
        $out = [];
        foreach ($this->scopeDepts($me) as $d) { if (in_array($d, $depts, true)) { $out[$d] = true; } }
        foreach ($depts as $d) { if (\App\Services\Org::isSuperiorOfDept((int) $me['id'], $d)) { $out[$d] = true; } }
        return array_keys($out);
    }

    private function canEdit(array $me): bool
    {
        return $this->seesAllEdit($me) || $this->scopeDepts($me) !== [];
    }

    private function canView(array $me): bool
    {
        return self::canSee((int) $me['id']);
    }

    private function inScope(array $me, array $emp): bool
    {
        if ($this->seesAllEdit($me)) { return true; }
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
        $depts = $this->viewDepts($me);
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
            'grafikDepts' => $this->grafikDepts($me),
        ]);
    }

    /** Часы без хвостовых нулей: 12, 6.5, 4/8. */
    private static function fmtH(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Публичный форматтер часов для вьюх. */
    public static function fmtHours(float $v): string
    {
        return self::fmtH($v);
    }

    /** Отделы с графиком 2/2, доступные пользователю (для выбора при печати графика сменности). */
    private function grafikDepts(array $me): array
    {
        $ids = $this->seesAll($me) ? self::shiftDeptIds() : array_values(array_intersect($this->viewDepts($me), self::shiftDeptIds()));
        if (!$ids) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::all("SELECT id, name FROM departments WHERE id IN ($ph) ORDER BY name", $ids);
    }

    /**
     * Строки графика сменности (ПЛАН) за полный месяц: по сотруднику — ячейки по дням {c,h} + итог.
     * Р — рабочий день; Р/Н + «дн/ночь» — рабочий с ночными часами; О — отпуск; пусто — выходной по графику.
     */
    private function buildGrafik(int $deptId, string $month): array
    {
        $lastDay = (int) date('t', strtotime("$month-01"));
        $emps = Database::all("SELECT id, full_name, position FROM users WHERE department_id=? AND schedule_type='2_2' AND is_active=1 ORDER BY full_name", [$deptId]);
        $out = [];
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $cells = []; $days = 0; $hours = 0.0;
            for ($d = 1; $d <= $lastDay; $d++) {
                $dte = sprintf('%s-%02d', $month, $d);
                $cell = ['c' => '', 'h' => ''];
                $sd = Database::one('SELECT plan_hours, plan_night FROM shift_days WHERE employee_id=? AND work_date=?', [$eid, $dte]);
                $ph = $sd ? (float) $sd['plan_hours'] : 0.0;
                $pn = $sd ? (float) $sd['plan_night'] : 0.0;
                if ($ph > 0) {
                    if ($pn > 0) { $cell = ['c' => 'Р/Н', 'h' => self::fmtH(max(0.0, $ph - $pn)) . '/' . self::fmtH($pn)]; }
                    else         { $cell = ['c' => 'Р', 'h' => '']; }
                    $days++; $hours += $ph;
                } elseif (Database::scalar("SELECT 1 FROM vacation_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?", [$eid, $dte, $dte])) {
                    $cell = ['c' => 'О', 'h' => ''];
                }
                $cells[] = $cell;
            }
            $out[] = ['emp' => $e, 'cells' => $cells, 'days' => $days, 'hours' => round($hours, 2)];
        }
        return $out;
    }

    /** Печатный график сменности (А4) за месяц по отделу — план из shift_days. */
    public function grafik(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { $this->redirect('/'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $deptId = (int) $this->input('dept');
        $depts = $this->grafikDepts($me);
        if (!$deptId && $depts) { $deptId = (int) $depts[0]['id']; }
        if (!$deptId) { flash('Нет отдела с сотрудниками на графике 2/2.', 'error'); $this->redirect('/shifts'); }
        $dept = Database::one('SELECT * FROM departments WHERE id=?', [$deptId]);
        $this->view('shifts/grafik', [
            'title'   => 'График сменности',
            'month'   => $month, 'deptId' => $deptId, 'dept' => $dept,
            'lastDay' => (int) date('t', strtotime("$month-01")),
            'rows'    => $this->buildGrafik($deptId, $month),
            'orgName' => \App\Services\Settings::get('org_name', 'ФГБУ «Интеробразование»'),
            'signApprove' => \App\Services\Settings::get('tabel_sign_1', 'Заместитель генерального директора'),
        ], false);
    }

    /** Выгрузка графика сменности в Excel (одна строка на сотрудника: код/день + итог). */
    public function grafikExport(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { $this->redirect('/'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $deptId = (int) $this->input('dept');
        $depts = $this->grafikDepts($me);
        if (!$deptId && $depts) { $deptId = (int) $depts[0]['id']; }
        if (!$deptId) { $this->redirect('/shifts'); }
        $lastDay = (int) date('t', strtotime("$month-01"));
        $days = []; for ($d = 1; $d <= $lastDay; $d++) { $days[] = (string) $d; }
        $rows = [];
        foreach ($this->buildGrafik($deptId, $month) as $r) {
            $line = [$r['emp']['full_name'], $r['emp']['position']];
            foreach ($r['cells'] as $c) { $line[] = $c['c'] . ($c['h'] !== '' ? ' ' . $c['h'] : ''); }
            $line[] = $r['days'] . ' (' . self::fmtH((float) $r['hours']) . ')';
            $rows[] = $line;
        }
        \App\Services\Xlsx::download("grafik-{$month}.xlsx", [[
            'name' => 'График сменности',
            'headers' => array_merge(['ФИО', 'Должность'], $days, ['Итого дн (ч)']),
            'rows' => $rows,
        ]]);
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
            'nightStart' => \App\Services\Settings::nightStart(),
            'nightEnd'   => \App\Services\Settings::nightEnd(),
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
        $nstart = \App\Services\Settings::nightStart();
        $nend   = \App\Services\Settings::nightEnd();
        $tm = fn($v) => preg_match('/^\d{1,2}:\d{2}$/', trim((string) $v)) ? substr('0' . trim((string) $v), -5) : '';
        $d = $_POST['d'] ?? []; // d[YYYY-MM-DD][start|end|holiday|overtime]
        $saved = 0;
        foreach ($d as $date => $vals) {
            if (substr((string) $date, 0, 7) !== $month) { continue; }   // только текущий месяц
            $start = $tm($vals['start'] ?? '');
            $end   = $tm($vals['end'] ?? '');
            // Часы и ночные считаются автоматически по ночному окну (ТК). Без времени — день без смены.
            $sp = \App\Services\ShiftClock::split($start, $end, $nstart, $nend);
            $hours = $sp['hours']; $night = $sp['night'];
            $ex = Database::scalar('SELECT id FROM shift_days WHERE employee_id=? AND work_date=?', [$eid, $date]);
            if ($start === '' && $end === '' && !$ex) { continue; }       // пусто и не было записи — пропускаем
            if ($mode === 'plan') {
                if ($ex) { Database::run('UPDATE shift_days SET plan_start=?, plan_end=?, plan_hours=?, plan_night=?, updated_at=? WHERE id=?', [$start, $end, $hours, $night, $now, $ex]); }
                else     { Database::insert('INSERT INTO shift_days (employee_id, work_date, plan_start, plan_end, plan_hours, plan_night) VALUES (?,?,?,?,?,?)', [$eid, $date, $start, $end, $hours, $night]); }
            } else {
                $hol = min($hours, $num($vals['holiday'] ?? 0));
                $ovt = min($hours, $num($vals['overtime'] ?? 0));
                if ($ex) { Database::run('UPDATE shift_days SET fact_start=?, fact_end=?, fact_hours=?, fact_night=?, holiday_hours=?, overtime_hours=?, updated_at=? WHERE id=?', [$start, $end, $hours, $night, $hol, $ovt, $now, $ex]); }
                else     { Database::insert('INSERT INTO shift_days (employee_id, work_date, fact_start, fact_end, fact_hours, fact_night, holiday_hours, overtime_hours) VALUES (?,?,?,?,?,?,?,?)', [$eid, $date, $start, $end, $hours, $night, $hol, $ovt]); }
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
