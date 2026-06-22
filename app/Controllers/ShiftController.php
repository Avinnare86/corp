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

    /** Времена смен сотрудника: индивидуальные (если заданы) иначе стандартные из настроек. ['day'=>[s,e],'night'=>[s,e]]. */
    private function empTimes(array $emp): array
    {
        $S = \App\Services\Settings::class;
        $g = fn($k, $def) => (!empty($emp[$k]) && preg_match('/^\d{1,2}:\d{2}$/', (string) $emp[$k])) ? (string) $emp[$k] : $def;
        return [
            'day'   => [$g('shift_day_start', $S::shiftDayStart()), $g('shift_day_end', $S::shiftDayEnd())],
            'night' => [$g('shift_night_start', $S::shiftNightStart()), $g('shift_night_end', $S::shiftNightEnd())],
        ];
    }

    /** Вид смены ячейки по сохранённым данным (kind либо вывод из часов): '' | day | night | ind. */
    private static function cellKind(?array $sd, string $mode): string
    {
        if (!$sd) { return ''; }
        $k = (string) ($sd[$mode . '_kind'] ?? '');
        if (in_array($k, ['day', 'night', 'ind'], true)) { return $k; }
        $h = (float) ($sd[$mode . '_hours'] ?? 0); $n = (float) ($sd[$mode . '_night'] ?? 0);
        return $h <= 0 ? '' : ($n > 0 ? 'night' : 'day');
    }

    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { flash('Раздел сменного графика (колл-центр).', 'error'); $this->redirect('/'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $mode  = $this->input('mode') === 'fact' ? 'fact' : 'plan';
        $depts = $this->grafikDepts($me);
        $deptId = $this->pickDept($depts, (int) $this->input('dept'));   // только в пределах доступа (защита от чтения чужих отделов)
        $lastDay = (int) date('t', strtotime("$month-01"));
        $canEdit = $this->canEdit($me);

        // Сотрудники 2/2 выбранного отдела (в области видимости) + ячейки графика по дням.
        $emps = [];
        if ($deptId) {
            $emps = Database::all("SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id=u.department_id
                WHERE u.department_id=? AND u.schedule_type='2_2' AND u.is_active=1 ORDER BY u.full_name", [$deptId]);
        }
        $rows = [];
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $byDate = [];
            foreach (Database::all("SELECT * FROM shift_days WHERE employee_id=? AND substr(work_date,1,7)=?", [$eid, $month]) as $sd) { $byDate[$sd['work_date']] = $sd; }
            $vac = [];
            foreach (Database::all("SELECT start_date, end_date FROM vacation_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?", [$eid, "$month-$lastDay", "$month-01"]) as $v) {
                for ($ts = strtotime($v['start_date']); $ts <= strtotime($v['end_date']); $ts += 86400) { $vac[date('Y-m-d', $ts)] = true; }
            }
            $cells = []; $sumH = 0.0; $sumN = 0.0; $days = 0;
            for ($d = 1; $d <= $lastDay; $d++) {
                $dte = sprintf('%s-%02d', $month, $d);
                $sd = $byDate[$dte] ?? null;
                if (isset($vac[$dte]) && self::cellKind($sd, $mode) === '') {
                    $cells[$dte] = ['kind' => 'O', 'ro' => true, 'ind' => ''];
                    continue;
                }
                $kind = self::cellKind($sd, $mode);
                $ind = $kind === 'ind' && $sd ? (((string) $sd[$mode . '_start']) . '–' . ((string) $sd[$mode . '_end'])) : '';
                $cells[$dte] = ['kind' => $kind, 'ro' => false, 'ind' => $ind];
                if ($sd) { $h = (float) $sd[$mode . '_hours']; if ($h > 0) { $sumH += $h; $sumN += (float) $sd[$mode . '_night']; $days++; } }
            }
            $rows[] = ['emp' => $e, 'cells' => $cells, 'days' => $days, 'hours' => round($sumH, 2), 'night' => round($sumN, 2)];
        }

        $this->view('shifts/index', [
            'title'   => 'Сменный график (2/2)',
            'month'   => $month, 'mode' => $mode, 'deptId' => $deptId, 'depts' => $depts,
            'lastDay' => $lastDay, 'rows' => $rows,
            'canEdit' => $canEdit,
            'std'     => [
                'day'   => [\App\Services\Settings::shiftDayStart(), \App\Services\Settings::shiftDayEnd()],
                'night' => [\App\Services\Settings::shiftNightStart(), \App\Services\Settings::shiftNightEnd()],
            ],
            'nightStart' => \App\Services\Settings::nightStart(),
            'nightEnd'   => \App\Services\Settings::nightEnd(),
            'grafikDepts' => $depts,
            'csrf'    => Auth::csrf(),
        ]);
    }

    /** Сохранить стандартные времена смен (сверху на странице графика). */
    public function saveStandard(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав.', 'error'); $this->redirect('/shifts'); }
        $tm = fn($v, $def) => preg_match('/^\d{1,2}:\d{2}$/', trim((string) $v)) ? substr('0' . trim((string) $v), -5) : $def;
        \App\Services\Settings::set('shift_day_start',   $tm($this->input('day_start'), '08:00'));
        \App\Services\Settings::set('shift_day_end',     $tm($this->input('day_end'), '20:00'));
        \App\Services\Settings::set('shift_night_start', $tm($this->input('night_start'), '20:00'));
        \App\Services\Settings::set('shift_night_end',   $tm($this->input('night_end'), '08:00'));
        flash('Стандартные времена смен сохранены.');
        $this->redirect('/shifts?month=' . urlencode((string) $this->input('month', date('Y-m'))) . '&dept=' . (int) $this->input('dept'));
    }

    /** Массовое сохранение графика по таблице Д/Н: g[empId][YYYY-MM-DD] = ''|day|night|ind. */
    public function saveGrid(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав.', 'error'); $this->redirect('/shifts'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $mode  = $this->input('mode') === 'fact' ? 'fact' : 'plan';
        $nstart = \App\Services\Settings::nightStart();
        $nend   = \App\Services\Settings::nightEnd();
        $now = date('Y-m-d H:i:s');
        $g = $_POST['g'] ?? [];
        $saved = 0;
        foreach ($g as $empId => $days) {
            $emp = Database::one("SELECT * FROM users u WHERE id=? AND schedule_type='2_2'", [(int) $empId]);
            if (!$emp || !$this->inScope($me, $emp)) { continue; }
            $times = $this->empTimes($emp);
            foreach ((array) $days as $date => $kind) {
                if (substr((string) $date, 0, 7) !== $month) { continue; }
                if ($kind === 'ind') { continue; }   // индивидуальное время дня — задаётся на странице сотрудника, не трогаем
                $ex = Database::scalar('SELECT id FROM shift_days WHERE employee_id=? AND work_date=?', [(int) $empId, $date]);
                if ($kind === 'day' || $kind === 'night') {
                    [$s, $e] = $times[$kind];
                    $sp = \App\Services\ShiftClock::split($s, $e, $nstart, $nend);
                    if ($mode === 'plan') {
                        if ($ex) { Database::run('UPDATE shift_days SET plan_kind=?, plan_start=?, plan_end=?, plan_hours=?, plan_night=?, updated_at=? WHERE id=?', [$kind, $s, $e, $sp['hours'], $sp['night'], $now, $ex]); }
                        else     { Database::insert('INSERT INTO shift_days (employee_id, work_date, plan_kind, plan_start, plan_end, plan_hours, plan_night) VALUES (?,?,?,?,?,?,?)', [(int) $empId, $date, $kind, $s, $e, $sp['hours'], $sp['night']]); }
                    } else {
                        // стандартная смена из таблицы не несёт праздничных/сверхурочных — сбрасываем возможные остатки
                        if ($ex) { Database::run('UPDATE shift_days SET fact_kind=?, fact_start=?, fact_end=?, fact_hours=?, fact_night=?, holiday_hours=0, overtime_hours=0, updated_at=? WHERE id=?', [$kind, $s, $e, $sp['hours'], $sp['night'], $now, $ex]); }
                        else     { Database::insert('INSERT INTO shift_days (employee_id, work_date, fact_kind, fact_start, fact_end, fact_hours, fact_night) VALUES (?,?,?,?,?,?,?)', [(int) $empId, $date, $kind, $s, $e, $sp['hours'], $sp['night']]); }
                    }
                    $saved++;
                } elseif ($kind === '' && $ex) {   // выходной — очистить смену в этом режиме
                    if ($mode === 'plan') { Database::run("UPDATE shift_days SET plan_kind='', plan_start='', plan_end='', plan_hours=0, plan_night=0, updated_at=? WHERE id=?", [$now, $ex]); }
                    else                  { Database::run("UPDATE shift_days SET fact_kind='', fact_start='', fact_end='', fact_hours=0, fact_night=0, holiday_hours=0, overtime_hours=0, updated_at=? WHERE id=?", [$now, $ex]); }
                    $saved++;
                }
            }
        }
        flash(($mode === 'plan' ? 'План' : 'Факт') . " графика сохранён (ячеек: {$saved}).");
        $this->redirect('/shifts?month=' . urlencode($month) . '&dept=' . (int) $this->input('dept') . '&mode=' . $mode);
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

    /** Запрошенный отдел в пределах доступа пользователю: иначе первый доступный (или 0). Защита от чтения чужих отделов. */
    private function pickDept(array $depts, int $req): int
    {
        $allowed = array_map(fn($d) => (int) $d['id'], $depts);
        if ($req && in_array($req, $allowed, true)) { return $req; }
        return $allowed[0] ?? 0;
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
        $depts = $this->grafikDepts($me);
        $deptId = $this->pickDept($depts, (int) $this->input('dept'));   // только доступный отдел
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
        $depts = $this->grafikDepts($me);
        $deptId = $this->pickDept($depts, (int) $this->input('dept'));   // только доступный отдел
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

    /** Загрузка сотрудника 2/2 в области редактирования (или null + редирект). */
    private function loadEmp(array $me, int $eid): ?array
    {
        $emp = Database::one("SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND u.schedule_type='2_2'", [$eid]);
        if (!$emp) { flash('Сотрудник не найден или не на графике 2/2.', 'error'); $this->redirect('/shifts'); }
        if (!$this->inScope($me, $emp)) { flash('Нет прав на этого сотрудника.', 'error'); $this->redirect('/shifts'); }
        return $emp;
    }

    /** Индивидуальная настройка сотрудника: свои времена смен + переопределение отдельных дней / факт. */
    public function employee(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав на правку графика.', 'error'); $this->redirect('/shifts'); }
        $emp = $this->loadEmp($me, (int) $this->input('id'));
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $mode  = $this->input('mode') === 'fact' ? 'fact' : 'plan';
        [$start, $end] = $this->range($month, 'full');
        $dates = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) { $dates[] = date('Y-m-d', $ts); }
        $existing = [];
        foreach (Database::all("SELECT * FROM shift_days WHERE employee_id=? AND substr(work_date,1,7)=?", [(int) $emp['id'], $month]) as $row) { $existing[$row['work_date']] = $row; }
        $this->view('shifts/employee', [
            'title'    => 'Индивидуальный график: ' . $emp['full_name'],
            'emp'      => $emp, 'month' => $month, 'mode' => $mode,
            'dates'    => $dates, 'existing' => $existing,
            'std'      => $this->empTimes($emp),
            'nightStart' => \App\Services\Settings::nightStart(),
            'nightEnd'   => \App\Services\Settings::nightEnd(),
            'csrf'     => Auth::csrf(),
        ]);
    }

    /** Сохранить индивидуальные времена смен сотрудника (пусто = использовать стандартные). */
    public function saveEmployee(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав.', 'error'); $this->redirect('/shifts'); }
        $emp = $this->loadEmp($me, (int) $this->input('id'));
        $tm = fn($v) => preg_match('/^\d{1,2}:\d{2}$/', trim((string) $v)) ? substr('0' . trim((string) $v), -5) : null;
        Database::run('UPDATE users SET shift_day_start=?, shift_day_end=?, shift_night_start=?, shift_night_end=? WHERE id=?', [
            $tm($this->input('day_start')), $tm($this->input('day_end')), $tm($this->input('night_start')), $tm($this->input('night_end')), (int) $emp['id'],
        ]);
        flash('Индивидуальные времена смен сохранены.');
        $this->redirect('/shifts/employee?id=' . (int) $emp['id'] . '&month=' . urlencode((string) $this->input('month', date('Y-m'))));
    }

    /** Переопределение отдельных дней индивидуальным временем (план = ind) / факт (часы + праздн./сверхуроч.). */
    public function saveDays(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canEdit($me)) { flash('Нет прав.', 'error'); $this->redirect('/shifts'); }
        $emp = $this->loadEmp($me, (int) $this->input('id'));
        $eid = (int) $emp['id'];
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $mode  = $this->input('mode') === 'fact' ? 'fact' : 'plan';
        $now = date('Y-m-d H:i:s');
        $num = fn($v) => max(0.0, round((float) str_replace(',', '.', (string) $v), 2));
        $nstart = \App\Services\Settings::nightStart();
        $nend   = \App\Services\Settings::nightEnd();
        $tm = fn($v) => preg_match('/^\d{1,2}:\d{2}$/', trim((string) $v)) ? substr('0' . trim((string) $v), -5) : '';
        $d = $_POST['d'] ?? []; // d[YYYY-MM-DD][start|end|holiday|overtime]
        $saved = 0;
        foreach ($d as $date => $vals) {
            if (substr((string) $date, 0, 7) !== $month) { continue; }
            $start = $tm($vals['start'] ?? ''); $end = $tm($vals['end'] ?? '');
            $sp = \App\Services\ShiftClock::split($start, $end, $nstart, $nend);
            $ex = Database::scalar('SELECT id FROM shift_days WHERE employee_id=? AND work_date=?', [$eid, $date]);
            $has = ($start !== '' && $end !== '');
            if (!$has && !$ex) { continue; }
            if ($mode === 'plan') {
                // индивидуальное время дня → kind='ind' (в общей таблице сохраняется, не перетирается Д/Н); пусто → очистка плана
                if ($has) {
                    if ($ex) { Database::run("UPDATE shift_days SET plan_kind='ind', plan_start=?, plan_end=?, plan_hours=?, plan_night=?, updated_at=? WHERE id=?", [$start, $end, $sp['hours'], $sp['night'], $now, $ex]); }
                    else     { Database::insert("INSERT INTO shift_days (employee_id, work_date, plan_kind, plan_start, plan_end, plan_hours, plan_night) VALUES (?,?,'ind',?,?,?,?)", [$eid, $date, $start, $end, $sp['hours'], $sp['night']]); }
                } elseif ($ex && self::cellKind(Database::one('SELECT * FROM shift_days WHERE id=?', [$ex]), 'plan') === 'ind') {
                    Database::run("UPDATE shift_days SET plan_kind='', plan_start='', plan_end='', plan_hours=0, plan_night=0, updated_at=? WHERE id=?", [$now, $ex]);
                }
            } else {
                $hol = min($sp['hours'], $num($vals['holiday'] ?? 0));
                $ovt = min($sp['hours'], $num($vals['overtime'] ?? 0));
                if ($has) {
                    if ($ex) { Database::run("UPDATE shift_days SET fact_kind='ind', fact_start=?, fact_end=?, fact_hours=?, fact_night=?, holiday_hours=?, overtime_hours=?, updated_at=? WHERE id=?", [$start, $end, $sp['hours'], $sp['night'], $hol, $ovt, $now, $ex]); }
                    else     { Database::insert("INSERT INTO shift_days (employee_id, work_date, fact_kind, fact_start, fact_end, fact_hours, fact_night, holiday_hours, overtime_hours) VALUES (?,?,'ind',?,?,?,?,?,?)", [$eid, $date, $start, $end, $sp['hours'], $sp['night'], $hol, $ovt]); }
                } elseif ($ex) {
                    Database::run("UPDATE shift_days SET fact_kind='', fact_start='', fact_end='', fact_hours=0, fact_night=0, holiday_hours=0, overtime_hours=0, updated_at=? WHERE id=?", [$now, $ex]);
                }
            }
            $saved++;
        }
        flash(($mode === 'plan' ? 'Индивидуальный план' : 'Факт') . " сохранён (дней: {$saved}).");
        $this->redirect('/shifts/employee?id=' . $eid . '&month=' . urlencode($month) . '&mode=' . $mode);
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
