<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Settings;
use App\Services\Xlsx;

/**
 * Электронный табель (полумесячный) с ревизиями и электронной подписью.
 * Коды дней (хранение латиницей, 1 символ/день): 1=Я(8ч) V=В O=ОТ B=Б K=К N=НН 0=пусто.
 * Права: admin/manager/орг-табельщик — вся организация; руководитель отдела и табельщик отдела — свой отдел.
 * Подписанный табель отображается листом А4 со штампом ЭП (ПЭП/УНЭП/УКЭП) и печатается в PDF.
 * Корректировочные табели — новые ревизии того же периода/охвата.
 */
class TabelController extends Controller
{
    public const CODES = ['1' => '8', 'V' => 'В', 'O' => 'ОТ', 'B' => 'Б', 'K' => 'К', 'N' => 'НН', '0' => ''];
    public const SIGN_TYPES = ['PEP' => 'Простая ЭП', 'UNEP' => 'УНЭП', 'UKEP' => 'УКЭП'];
    /** Коды сменного табеля (0504421, колл-центр 2/2): Я=явка днём, Н=ночная работа, О=отпуск, В=выходной. */
    public const SHIFT_CODES = ['Я' => 'явка днём', 'Н' => 'ночная работа', 'Я/Н' => 'день и ночь', 'О' => 'отпуск', 'В' => 'выходной', 'Б' => 'больничный', 'К' => 'командировка'];

    /**
     * Полный перечень условных обозначений формы по ОКУД 0504421 (Приказ Минфина России № 52н от 30.03.2015,
     * Методические указания — прил. № 5). Формат: код => [расшифровка, считается_отработанным_временем].
     * Учреждение вправе дополнять перечень своими кодами, закрепив их в учётной политике (52н).
     */
    public const OKUD_CODES = [
        'Я'  => ['Продолжительность работы в дневное время (явка)', true],
        'Н'  => ['Продолжительность работы в ночное время', true],
        'РВ' => ['Работа в выходные и нерабочие праздничные дни', true],
        'С'  => ['Продолжительность сверхурочной работы', true],
        'ВМ' => ['Работа вахтовым методом', true],
        'К'  => ['Служебная командировка', false],
        'ПК' => ['Повышение квалификации с отрывом от работы', false],
        'ПМ' => ['Повышение квалификации с отрывом в другой местности', false],
        'О'  => ['Ежегодный основной оплачиваемый отпуск', false],
        'ОД' => ['Ежегодный дополнительный оплачиваемый отпуск', false],
        'У'  => ['Учебный отпуск (с сохранением зарплаты)', false],
        'УД' => ['Учебный отпуск без сохранения зарплаты', false],
        'Р'  => ['Отпуск по беременности и родам', false],
        'ОЖ' => ['Отпуск по уходу за ребёнком', false],
        'ДО' => ['Отпуск без сохранения зарплаты (с разрешения работодателя)', false],
        'ОЗ' => ['Отпуск без сохранения зарплаты (предусмотренный законом)', false],
        'Б'  => ['Временная нетрудоспособность (с пособием)', false],
        'Т'  => ['Нетрудоспособность без назначения пособия', false],
        'Г'  => ['Исполнение государственных или общественных обязанностей', false],
        'ПР' => ['Прогулы (отсутствие без уважительной причины)', false],
        'НС' => ['Неполное рабочее время по инициативе работодателя', false],
        'В'  => ['Выходные дни (еженедельный отдых) и нерабочие праздничные дни', false],
        'ОВ' => ['Дополнительные выходные дни (оплачиваемые)', false],
        'НВ' => ['Дополнительные выходные дни (без оплаты)', false],
        'ЗБ' => ['Забастовка', false],
        'НН' => ['Неявка по невыясненным причинам (до выяснения)', false],
        'РП' => ['Время простоя по вине работодателя', false],
        'НП' => ['Время простоя по не зависящим причинам', false],
        'ВП' => ['Время простоя по вине работника', false],
        'НО' => ['Отстранение от работы (с оплатой)', false],
        'НБ' => ['Отстранение от работы (без оплаты)', false],
        'А'  => ['Неявка с разрешения администрации', false],
    ];

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

    /** Отделы, где есть сотрудники на графике 2/2 (для выбора при формировании сменного табеля). */
    private static function shiftDeptIds(): array
    {
        return array_map(fn($r) => (int) $r['department_id'], Database::all(
            "SELECT DISTINCT department_id FROM users WHERE schedule_type='2_2' AND is_active=1 AND department_id IS NOT NULL"));
    }

    /**
     * Сформировать строки сменного табеля (0504421) из сменного графика shift_days за период.
     * По каждому дню: факт (если введён) иначе план; разбивка день/ночь уже посчитана в shift_days.
     * Ячейка: код Я/Н/Я-Н + часы «12» или «дн/ночь»; утверждённый отпуск → О; иначе пусто (выходной).
     */
    private function buildShift(int $tid, int $deptId, string $period): void
    {
        [$start, $end] = $this->range($period);
        $nDays = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        $emps = Database::all("SELECT id FROM users WHERE department_id=? AND schedule_type='2_2' AND is_active=1 ORDER BY full_name", [$deptId]);
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $cells = []; $days = 0; $hoursSum = 0.0;
            for ($i = 0; $i < $nDays; $i++) {
                $dte = date('Y-m-d', strtotime($start) + $i * 86400);
                $cell = ['c' => '', 'h' => ''];
                $sd = Database::one('SELECT * FROM shift_days WHERE employee_id=? AND work_date=?', [$eid, $dte]);
                $onVac = Database::scalar("SELECT 1 FROM vacation_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?", [$eid, $dte, $dte]);
                $hours = 0.0; $night = 0.0;
                if ($sd) {
                    $useFact = (float) $sd['fact_hours'] > 0;
                    $hours = (float) ($useFact ? $sd['fact_hours'] : $sd['plan_hours']);
                    $night = (float) ($useFact ? $sd['fact_night'] : $sd['plan_night']);
                }
                if ($hours > 0) {
                    $dayH = max(0.0, $hours - $night);
                    if ($dayH > 0 && $night > 0) { $cell = ['c' => 'Я/Н', 'h' => self::fmtH($dayH) . '/' . self::fmtH($night)]; }
                    elseif ($night > 0)          { $cell = ['c' => 'Н',   'h' => self::fmtH($night)]; }
                    else                          { $cell = ['c' => 'Я',   'h' => self::fmtH($hours)]; }
                    $days++; $hoursSum += $hours;
                } elseif ($onVac) {
                    $cell = ['c' => 'О', 'h' => ''];
                }
                $cells[] = $cell;
            }
            Database::insert('INSERT INTO tabel_rows (tabel_id, employee_id, day_marks, days, cells, hours) VALUES (?,?,?,?,?,?)',
                [$tid, $eid, '', $days, json_encode($cells, JSON_UNESCAPED_UNICODE), round($hoursSum, 2)]);
        }
    }

    /**
     * Обычный табель (5/2) по ПРЕЗУМПЦИИ ЯВКИ: каждый рабочий день (Пн–Пт) = Я; сб/вс = В;
     * утверждённый отпуск = О. Без часов (просто код). Табельщик далее меняет дни на отсутствия.
     * Сотрудники 2/2 в обычный табель не попадают — для них сменный табель из графика.
     */
    private function buildStd(int $tid, ?int $deptId, string $period): void
    {
        [$start, $end] = $this->range($period);
        $nDays = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        $emps = $deptId === null
            ? Database::all("SELECT id, hire_date, fire_date FROM users WHERE role IN ('employee','controller') AND is_active=1 AND COALESCE(schedule_type,'5_2')<>'2_2' ORDER BY full_name")
            : Database::all("SELECT id, hire_date, fire_date FROM users WHERE department_id=? AND is_active=1 AND COALESCE(schedule_type,'5_2')<>'2_2' ORDER BY full_name", [$deptId]);
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $hire = ($e['hire_date'] ?? '') !== '' ? substr((string) $e['hire_date'], 0, 10) : null;
            $fire = ($e['fire_date'] ?? '') !== '' ? substr((string) $e['fire_date'], 0, 10) : null;
            $cells = []; $days = 0;
            for ($i = 0; $i < $nDays; $i++) {
                $dte = date('Y-m-d', strtotime($start) + $i * 86400);
                $dow = (int) date('w', strtotime($dte));   // 0=вс, 6=сб
                if (($hire !== null && $dte < $hire) || ($fire !== null && $dte > $fire)) {
                    $c = '';   // до приёма / после увольнения — нерабочий день, не в счёт отработанных
                } elseif (Database::scalar("SELECT 1 FROM vacation_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?", [$eid, $dte, $dte])) {
                    $c = 'О';
                } elseif ($dow === 0 || $dow === 6) {
                    $c = 'В';
                } else {
                    $c = 'Я'; $days++;   // презумпция явки на рабочий день
                }
                $cells[] = ['c' => $c, 'h' => ''];
            }
            Database::insert('INSERT INTO tabel_rows (tabel_id, employee_id, day_marks, days, cells, hours) VALUES (?,?,?,?,?,0)',
                [$tid, $eid, '', $days, json_encode($cells, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** Ячейки строки: cells JSON либо конвертация устаревшего day_marks (старые подписанные табели). */
    private static function cellsOf(array $row): array
    {
        $cells = json_decode((string) ($row['cells'] ?? ''), true);
        if (is_array($cells) && $cells) { return $cells; }
        $map = ['1' => 'Я', 'V' => 'В', 'O' => 'О', 'B' => 'Б', 'K' => 'К', 'N' => 'НН', '0' => ''];
        $out = [];
        foreach (str_split((string) ($row['day_marks'] ?? '')) as $ch) { $out[] = ['c' => $map[$ch] ?? '', 'h' => '']; }
        return $out;
    }

    /** Итоги по ячейкам: [дни явок (worked-коды, дробные Я/Н = 1 день), сумма часов]. */
    private static function daysHoursFromCells(array $cells): array
    {
        $days = 0; $hours = 0.0;
        foreach ($cells as $cell) {
            $c = (string) ($cell['c'] ?? ''); $h = (string) ($cell['h'] ?? '');
            if ($c === '') { continue; }
            $worked = false;
            foreach (explode('/', $c) as $cc) { if (!empty(self::OKUD_CODES[$cc][1])) { $worked = true; } }
            if ($worked) { $days++; }
            if ($h !== '') { foreach (explode('/', $h) as $hp) { $hours += (float) $hp; } }
        }
        return [$days, round($hours, 2)];
    }

    // ---------- права ----------
    /** Отделы, по которым пользователь может вести табель; 'org' = вся организация. */
    private function scopeFor(array $me): array
    {
        if (in_array($me['role'], ['admin', 'manager'], true) || (int) $me['is_timekeeper_org'] === 1) {
            return ['org' => true, 'depts' => array_map(fn($d) => (int) $d['id'], Database::all('SELECT id FROM departments'))];
        }
        $depts = [];
        foreach (Database::all('SELECT id FROM departments WHERE head_id = ?', [$me['id']]) as $d) { $depts[] = (int) $d['id']; }
        if ($me['timekeeper_dept_id']) { $depts[] = (int) $me['timekeeper_dept_id']; }
        return ['org' => false, 'depts' => array_values(array_unique($depts))];
    }

    private function canView(array $me): bool
    {
        $s = $this->scopeFor($me);
        return $s['org'] || $s['depts'] || (int) $me['is_hr'] === 1 || (int) $me['is_accountant'] === 1;
    }

    private function range(string $period): array
    {
        $month = substr($period, 0, 7);
        $half = (int) substr($period, 8);
        $start = $half === 1 ? "$month-01" : "$month-16";
        $end = $half === 1 ? "$month-15" : date('Y-m-t', strtotime("$month-01"));
        return [$start, $end];
    }

    // ---------- список ----------
    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { flash('Нет доступа к табелю.', 'error'); $this->redirect('/'); }
        $scope = $this->scopeFor($me);
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $half = (int) ($this->input('half') ?: (date('j') <= 15 ? 1 : 2));
        $period = "$month-$half";
        $archive = (int) $this->input('archive') === 1;   // вкладка «Архив» (archived_at IS NOT NULL)

        // Единый блок: оба вида табеля (обычный 5/2 и сменный 2/2). Раздел Актуальные/Архив по archived_at.
        $tabels = Database::all(
            "SELECT t.*, d.name AS dept_name, uc.full_name AS creator, us.full_name AS signer, ua.full_name AS archiver
               FROM tabels t LEFT JOIN departments d ON d.id = t.department_id
               LEFT JOIN users uc ON uc.id = t.created_by LEFT JOIN users us ON us.id = t.signer_id
               LEFT JOIN users ua ON ua.id = t.archived_by
              WHERE t.period = ? AND t.archived_at IS " . ($archive ? 'NOT NULL' : 'NULL') . "
              ORDER BY COALESCE(t.kind,'std'), t.department_id, t.revision", [$period]);

        // для сменного табеля (2/2) — только отделы, где есть сотрудники на графике 2/2
        $shiftIds = self::shiftDeptIds();
        $shiftDepts = $shiftIds
            ? Database::all('SELECT * FROM departments WHERE id IN (' . implode(',', $shiftIds) . ') ORDER BY name')
            : [];

        $this->view('timesheet2/index', [
            'title' => 'Электронный табель',
            'month' => $month, 'half' => $half, 'period' => $period, 'archive' => $archive,
            'tabels' => $tabels,
            'canCreate' => (bool) ($scope['org'] || $scope['depts']),
            'isAdmin' => $me['role'] === 'admin',
            'scope' => $scope,
            'departments' => Database::all('SELECT * FROM departments ORDER BY name'),
            'shiftDepts' => $shiftDepts,
        ]);
    }

    /** Создать табель (первичный или корректировочный) с предзаполнением из явки и отпусков. */
    public function create(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $scope = $this->scopeFor($me);
        $period = (string) $this->input('period');
        $kind = $this->input('kind') === 'shift' ? 'shift' : 'std';
        $deptId = $this->input('department_id') !== '' && $this->input('department_id') !== null ? (int) $this->input('department_id') : null;

        if ($kind === 'shift' && $deptId === null) { flash('Сменный табель (2/2) формируется по отделу — выберите отдел.', 'error'); $this->redirect('/timesheet2'); }
        if ($deptId === null && !$scope['org']) { flash('Табель по организации может вести только орг-табельщик.', 'error'); $this->redirect('/timesheet2'); }
        if ($deptId !== null && !$scope['org'] && !in_array($deptId, $scope['depts'], true)) { flash('Нет прав на этот отдел.', 'error'); $this->redirect('/timesheet2'); }

        $rev = (int) Database::scalar(
            'SELECT COALESCE(MAX(revision),-1)+1 FROM tabels WHERE period=? AND COALESCE(kind,\'std\')=? AND ' . ($deptId === null ? 'department_id IS NULL' : 'department_id = ?'),
            $deptId === null ? [$period, $kind] : [$period, $kind, $deptId]);

        $tid = Database::insert('INSERT INTO tabels (period, department_id, revision, created_by, kind) VALUES (?,?,?,?,?)',
            [$period, $deptId, $rev, $me['id'], $kind]);

        if ($kind === 'shift') {
            $this->buildShift($tid, (int) $deptId, $period);
            flash($rev > 0 ? "Создан корректировочный сменный табель №{$rev} (из графика 2/2)." : 'Сменный табель 0504421 сформирован из графика 2/2.');
        } else {
            $this->buildStd($tid, $deptId, $period);   // 5/2 — презумпция явки (Я по умолчанию, отмечаем отсутствия)
            flash($rev > 0 ? "Создан корректировочный табель №{$rev}." : 'Табель сформирован (презумпция явки — отметьте отсутствия).');
        }
        $this->redirect('/timesheet2/' . $tid . '/edit');
    }

    private function loadTabel(string $id): array
    {
        $t = Database::one(
            "SELECT t.*, d.name AS dept_name FROM tabels t LEFT JOIN departments d ON d.id=t.department_id WHERE t.id = ?", [$id]);
        if (!$t) { flash('Табель не найден.', 'error'); $this->redirect('/timesheet2'); }
        return $t;
    }

    public function edit(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $t = $this->loadTabel($id);
        if ($t['status'] !== 'draft') { $this->redirect('/timesheet2/' . $id . '/view'); }
        $scope = $this->scopeFor($me);
        if (!$scope['org'] && !($t['department_id'] && in_array((int) $t['department_id'], $scope['depts'], true))) {
            flash('Нет прав на редактирование.', 'error'); $this->redirect('/timesheet2');
        }
        [$start, $end] = $this->range($t['period']);
        $dates = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) { $dates[] = date('Y-m-d', $ts); }
        $rows = Database::all(
            "SELECT r.*, u.full_name, u.position, d.name AS dept_name FROM tabel_rows r JOIN users u ON u.id=r.employee_id
               LEFT JOIN departments d ON d.id=u.department_id WHERE r.tabel_id = ? ORDER BY d.name, u.full_name", [$id]);
        // мои сертификаты для блока подписи
        $certs = Database::all('SELECT * FROM user_certificates WHERE user_id = ? AND valid_to >= ? ORDER BY sign_type', [$me['id'], date('Y-m-d')]);
        foreach ($rows as &$r) { $r['cells_arr'] = self::cellsOf($r); }
        unset($r);
        if (($t['kind'] ?? 'std') === 'shift') {
            $this->view('timesheet2/shift_edit', [
                'title' => 'Сменный табель 0504421 — предпросмотр',
                't' => $t, 'dates' => $dates, 'rows' => $rows,
                'signTypes' => self::SIGN_TYPES, 'certs' => $certs,
            ]);
            return;
        }
        // Обычный (5/2): редактируемый бланк 0504421 — по ячейке код (без часов), презумпция явки.
        $this->view('timesheet2/edit', [
            'title' => 'Табель — редактирование',
            't' => $t, 'dates' => $dates, 'rows' => $rows,
            'okud' => self::OKUD_CODES, 'signTypes' => self::SIGN_TYPES, 'certs' => $certs,
        ]);
    }

    /** Пересформировать черновик: 2/2 — из графика shift_days; обычный — по презумпции явки. */
    public function regenerate(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->loadTabel($id);
        if ($t['status'] !== 'draft') { flash('Подписанный табель пересформировать нельзя — создайте корректировочный.', 'error'); $this->redirect('/timesheet2/' . $id . '/view'); }
        $scope = $this->scopeFor($me);
        if (!$scope['org'] && !($t['department_id'] && in_array((int) $t['department_id'], $scope['depts'], true))) {
            flash('Нет прав на редактирование.', 'error'); $this->redirect('/timesheet2');
        }
        Database::run('DELETE FROM tabel_rows WHERE tabel_id=?', [$id]);
        if (($t['kind'] ?? 'std') === 'shift') {
            $this->buildShift((int) $id, (int) $t['department_id'], (string) $t['period']);
            flash('Сменный табель пересформирован из графика 2/2.');
        } else {
            $this->buildStd((int) $id, $t['department_id'] !== null ? (int) $t['department_id'] : null, (string) $t['period']);
            flash('Табель заполнен заново (презумпция явки).');
        }
        $this->redirect('/timesheet2/' . $id . '/edit');
    }

    /** Сохранение отметок (и удаление снятых сотрудников / «на часть сотрудников»). */
    public function save(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $t = $this->loadTabel($id);
        if ($t['status'] !== 'draft') { $this->redirect('/timesheet2/' . $id . '/view'); }
        [$start, $end] = $this->range($t['period']);
        $nDays = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        $marks = $_POST['mark'] ?? [];     // mark[empId][i] = код ОКУД (Я/В/О/Б/К/…), пусто = не задан
        $keep = array_map('intval', $_POST['keep'] ?? []); // включённые сотрудники

        Database::run('DELETE FROM tabel_rows WHERE tabel_id = ? AND employee_id NOT IN (' . (implode(',', $keep) ?: '0') . ')', [$id]);
        foreach ($keep as $empId) {
            $cells = [];
            for ($i = 0; $i < $nDays; $i++) {
                $c = (string) ($marks[$empId][$i] ?? '');
                if ($c !== '' && !isset(self::OKUD_CODES[$c])) { $c = ''; }
                $cells[] = ['c' => $c, 'h' => ''];   // обычный табель — без часов
            }
            [$days, $hours] = self::daysHoursFromCells($cells);
            $json = json_encode($cells, JSON_UNESCAPED_UNICODE);
            $ex = Database::scalar('SELECT id FROM tabel_rows WHERE tabel_id=? AND employee_id=?', [$id, $empId]);
            if ($ex) { Database::run("UPDATE tabel_rows SET day_marks='', cells=?, days=?, hours=? WHERE id=?", [$json, $days, $hours, $ex]); }
            else { Database::insert("INSERT INTO tabel_rows (tabel_id, employee_id, day_marks, cells, days, hours) VALUES (?,?,'',?,?,?)", [$id, $empId, $json, $days, $hours]); }
        }
        flash('Табель сохранён.');
        $this->redirect('/timesheet2/' . $id . '/edit');
    }

    /** Подписание ЭП: ПЭП (подтверждение паролем, сертификат выпускается системой) или УНЭП/УКЭП (нужен сертификат). */
    public function sign(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->loadTabel($id);
        if ($t['status'] !== 'draft') { $this->redirect('/timesheet2/' . $id . '/view'); }
        $type = strtoupper((string) $this->input('sign_type'));
        if (!isset(self::SIGN_TYPES[$type])) { flash('Выберите вид подписи.', 'error'); $this->redirect('/timesheet2/' . $id . '/edit'); }

        // подтверждение личности паролем учётной записи
        $pwd = (string) $this->input('password');
        $hash = Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
        if (!$pwd || !password_verify($pwd, (string) $hash)) {
            flash('Неверный пароль — подпись не выполнена.', 'error');
            $this->redirect('/timesheet2/' . $id . '/edit');
        }

        // сертификат
        if ($type === 'PEP') {
            $cert = Database::one("SELECT * FROM user_certificates WHERE user_id=? AND sign_type='PEP' AND valid_to>=? LIMIT 1", [$me['id'], date('Y-m-d')]);
            if (!$cert) {
                $serial = 'PEP-' . strtoupper(bin2hex(random_bytes(6)));
                Database::insert('INSERT INTO user_certificates (user_id, sign_type, serial, owner_name, issued_at, valid_to) VALUES (?,?,?,?,?,?)',
                    [$me['id'], 'PEP', $serial, $me['full_name'], date('Y-m-d'), date('Y-m-d', strtotime('+5 years'))]);
                $cert = Database::one('SELECT * FROM user_certificates WHERE serial = ?', [$serial]);
            }
        } else {
            $cert = Database::one('SELECT * FROM user_certificates WHERE user_id=? AND sign_type=? AND valid_to>=? LIMIT 1', [$me['id'], $type, date('Y-m-d')]);
            if (!$cert) {
                flash(self::SIGN_TYPES[$type] . ': сертификат не зарегистрирован. Обратитесь к администратору (Оргструктура → Сертификаты ЭП).', 'error');
                $this->redirect('/timesheet2/' . $id . '/edit');
            }
        }

        // криптографический отпечаток содержимого (вкл. ячейки сменного табеля)
        $rows = Database::all('SELECT employee_id, day_marks, cells, days, hours FROM tabel_rows WHERE tabel_id = ? ORDER BY employee_id', [$id]);
        $secret = Settings::get('sign_secret');
        if (!$secret) { $secret = bin2hex(random_bytes(16)); Settings::set('sign_secret', $secret); }
        $now = date('Y-m-d H:i:s');
        $payload = json_encode([$t['period'], $t['department_id'], $t['revision'], $rows, $me['id'], $cert['serial'], $now]);
        $signHash = hash_hmac('sha256', $payload, $secret);

        Database::run('UPDATE tabels SET status=?, signer_id=?, sign_type=?, signed_at=?, sign_hash=?, cert_serial=? WHERE id=?',
            ['signed', $me['id'], $type, $now, $signHash, $cert['serial'], $id]);

        // синхронизация месячного табеля: последняя ПОДПИСАННАЯ ревизия каждой половины
        self::syncMonth(substr($t['period'], 0, 7));

        flash('Табель подписан (' . self::SIGN_TYPES[$type] . ').');
        $this->redirect('/timesheet2/' . $id . '/view');
    }

    public static function syncMonth(string $month): void
    {
        $byEmp = [];
        foreach (['1', '2'] as $h) {
            $period = "$month-$h";
            // последняя подписанная ревизия по каждому охвату
            $tabs = Database::all(
                "SELECT t1.* FROM tabels t1
                  WHERE t1.period = ? AND t1.status='signed' AND t1.archived_at IS NULL
                    AND t1.revision = (SELECT MAX(t2.revision) FROM tabels t2 WHERE t2.period=t1.period
                                        AND ((t2.department_id IS NULL AND t1.department_id IS NULL) OR t2.department_id = t1.department_id)
                                        AND t2.status='signed' AND t2.archived_at IS NULL)", [$period]);
            foreach ($tabs as $tb) {
                foreach (Database::all('SELECT employee_id, days FROM tabel_rows WHERE tabel_id = ?', [$tb['id']]) as $r) {
                    $byEmp[$r['employee_id']][$h] = (int) $r['days']; // орг-табель может перекрыть отдел — берём последнее
                }
            }
        }
        // Норма = рабочие дни месяца (электронный табель — единственный источник; /admin/timesheet удалён).
        $norm = \App\Services\PayrollService::calendarWorkingDays($month);
        foreach ($byEmp as $empId => $halves) {
            $tot = array_sum($halves);
            $ex = Database::scalar('SELECT id FROM timesheets WHERE employee_id=? AND period=?', [$empId, $month]);
            if ($ex) { Database::run('UPDATE timesheets SET worked_days=?, norm_days=? WHERE id=?', [$tot, $norm, $ex]); }
            else { Database::insert('INSERT INTO timesheets (employee_id, period, norm_days, worked_days) VALUES (?,?,?,?)', [$empId, $month, $norm, $tot]); }
        }
    }

    /** Просмотр подписанного табеля: лист А4 со штампом ЭП (печать → PDF). */
    public function viewSigned(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { flash('Нет доступа.', 'error'); $this->redirect('/'); }
        $t = Database::one(
            "SELECT t.*, d.name AS dept_name, us.full_name AS signer_name, us.position AS signer_position
               FROM tabels t LEFT JOIN departments d ON d.id=t.department_id LEFT JOIN users us ON us.id=t.signer_id
              WHERE t.id = ?", [$id]);
        if (!$t) { $this->redirect('/timesheet2'); }
        if ($t['status'] === 'draft') { $this->redirect('/timesheet2/' . $id . '/edit'); }
        [$start, $end] = $this->range($t['period']);
        $dates = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) { $dates[] = date('Y-m-d', $ts); }
        $rows = Database::all(
            "SELECT r.*, u.full_name, u.position, d.name AS dept_name FROM tabel_rows r JOIN users u ON u.id=r.employee_id
               LEFT JOIN departments d ON d.id=u.department_id WHERE r.tabel_id = ? ORDER BY d.name, u.full_name", [$id]);
        $cert = Database::one('SELECT * FROM user_certificates WHERE serial = ?', [$t['cert_serial']]);
        // Единый бланк 0504421 для обоих видов: строка часов выводится только если в ячейках есть часы (2/2).
        foreach ($rows as &$r) { $r['cells_arr'] = self::cellsOf($r); }
        unset($r);
        $this->view('timesheet2/view', [
            'title' => 'Табель 0504421 (подписан)',
            't' => $t, 'dates' => $dates, 'rows' => $rows, 'cert' => $cert,
            'orgName' => (string) (Settings::get('org_name', 'ФГБУ «Интеробразование»')),
            'signers' => [
                Settings::get('tabel_sign_1', 'Заместитель генерального директора'),
                Settings::get('tabel_sign_2', 'Главный бухгалтер'),
            ],
        ], false);
    }

    /** Покрытие табелями по структуре — для кадров и бухгалтерии. */
    public function coverage(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $allowed = in_array($me['role'], ['admin', 'manager'], true) || (int) $me['is_hr'] === 1 || (int) $me['is_accountant'] === 1;
        if (!$allowed) { flash('Раздел доступен кадрам и бухгалтерии.', 'error'); $this->redirect('/'); }
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $half = (int) ($this->input('half') ?: (date('j') <= 15 ? 1 : 2));
        $period = "$month-$half";

        // покрытые сотрудники: строки последних ПОДПИСАННЫХ ревизий
        $covered = [];
        $tabs = Database::all(
            "SELECT t1.* FROM tabels t1 WHERE t1.period=? AND t1.status='signed'
              AND t1.revision=(SELECT MAX(t2.revision) FROM tabels t2 WHERE t2.period=t1.period
                  AND ((t2.department_id IS NULL AND t1.department_id IS NULL) OR t2.department_id=t1.department_id) AND t2.status='signed')",
            [$period]);
        foreach ($tabs as $tb) {
            foreach (Database::all('SELECT employee_id FROM tabel_rows WHERE tabel_id=?', [$tb['id']]) as $r) {
                $covered[(int) $r['employee_id']] = $tb;
            }
        }
        $deptRows = [];
        $depts = Database::all('SELECT * FROM departments ORDER BY name');
        $depts[] = ['id' => null, 'name' => '— вне подразделений —'];
        foreach ($depts as $d) {
            $emps = $d['id'] === null
                ? Database::all("SELECT id, full_name FROM users WHERE department_id IS NULL AND is_active=1 AND role IN ('employee','controller') ORDER BY full_name")
                : Database::all("SELECT id, full_name FROM users WHERE department_id=? AND is_active=1 ORDER BY full_name", [$d['id']]);
            if (!$emps) { continue; }
            $cov = 0; $missing = [];
            foreach ($emps as $e) {
                if (isset($covered[(int) $e['id']])) { $cov++; } else { $missing[] = $e['full_name']; }
            }
            $deptRows[] = ['dept' => $d, 'total' => count($emps), 'covered' => $cov, 'missing' => $missing];
        }
        $allTabels = Database::all(
            "SELECT t.*, d.name AS dept_name, us.full_name AS signer FROM tabels t
               LEFT JOIN departments d ON d.id=t.department_id LEFT JOIN users us ON us.id=t.signer_id
              WHERE t.period=? ORDER BY t.department_id, t.revision", [$period]);

        $this->view('timesheet2/coverage', [
            'title' => 'Покрытие табелями',
            'month' => $month, 'half' => $half, 'period' => $period,
            'deptRows' => $deptRows, 'allTabels' => $allTabels,
        ]);
    }

    /** Доступ к покрытию для бухгалтерии (бухгалтер по флагу/роли, директор, фин-менеджер, админ). */
    private function accGate(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $ok = in_array($me['role'], ['admin', 'manager'], true) || (int) ($me['is_accountant'] ?? 0) === 1
            || Auth::effectiveHas('accountant', 'director', 'finance_manager');
        if (!$ok) { flash('Раздел покрытия доступен бухгалтерии и руководству.', 'error'); $this->redirect('/'); }
    }

    /** Подписанные документы учёта времени за месяц по отделам: последние ревизии табелей (обе половины) + графики 2/2. */
    private function accCoverageData(string $month): array
    {
        $tabels = Database::all(
            "SELECT t.*, d.name AS dept_name, us.full_name AS signer FROM tabels t
               LEFT JOIN departments d ON d.id=t.department_id LEFT JOIN users us ON us.id=t.signer_id
              WHERE t.status='signed' AND substr(t.period,1,7)=?
                AND t.revision=(SELECT MAX(t2.revision) FROM tabels t2 WHERE t2.period=t.period
                    AND ((t2.department_id IS NULL AND t.department_id IS NULL) OR t2.department_id=t.department_id) AND t2.status='signed')
              ORDER BY (t.department_id IS NULL), d.name, t.period", [$month]);
        $grafiks = Database::all(
            "SELECT g.*, d.name AS dept_name FROM shift_grafiks g LEFT JOIN departments d ON d.id=g.department_id
              WHERE g.period=? AND g.revision=(SELECT MAX(g2.revision) FROM shift_grafiks g2 WHERE g2.department_id=g.department_id AND g2.period=g.period)
              ORDER BY d.name", [$month]);
        $byDept = [];
        foreach ($tabels as $t) { $byDept[$t['department_id'] ? (string) $t['dept_name'] : 'Вся организация / без отдела']['tabels'][] = $t; }
        foreach ($grafiks as $g) { $byDept[$g['department_id'] ? (string) $g['dept_name'] : 'Вся организация / без отдела']['grafiks'][] = $g; }
        ksort($byDept);
        return ['byDept' => $byDept, 'nTab' => count($tabels), 'nGraf' => count($grafiks)];
    }

    /** Покрытие для бухгалтерии: подписанные табели и графики 2/2 по отделам (карточки-документы). */
    public function accCoverage(): void
    {
        $this->accGate();
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $data = $this->accCoverageData($month);
        $periods = array_values(array_unique(array_merge(
            array_column(Database::all("SELECT DISTINCT substr(period,1,7) AS m FROM tabels WHERE status='signed'"), 'm'),
            array_column(Database::all('SELECT DISTINCT period AS m FROM shift_grafiks'), 'm')
        )));
        rsort($periods);
        $this->view('timesheet2/coverage_acc', [
            'title' => 'Покрытие (бухгалтерия)',
            'month' => $month, 'periods' => $periods,
            'byDept' => $data['byDept'], 'nTab' => $data['nTab'], 'nGraf' => $data['nGraf'],
        ]);
    }

    /** Выгрузка покрытия (бухгалтерия) в Excel: отдел / документ / половина / ревизия / подпись / подписал / дата. */
    public function accCoverageExport(): void
    {
        $this->accGate();
        $month = (string) ($this->input('month') ?: date('Y-m'));
        $data = $this->accCoverageData($month);
        $rows = [];
        foreach ($data['byDept'] as $dept => $g) {
            foreach ($g['tabels'] ?? [] as $t) {
                $rows[] = [$dept, ($t['kind'] ?? 'std') === 'shift' ? 'Табель 2/2 (0504421)' : 'Табель 5/2',
                    (int) substr($t['period'], 8) === 1 ? '1–15' : '16–конец',
                    (int) $t['revision'] === 0 ? 'первичный' : 'корр. №' . (int) $t['revision'],
                    self::SIGN_TYPES[$t['sign_type']] ?? (string) $t['sign_type'], (string) $t['signer'], substr((string) $t['signed_at'], 0, 16)];
            }
            foreach ($g['grafiks'] ?? [] as $gr) {
                $rows[] = [$dept, 'График сменности 2/2', '—',
                    (int) $gr['revision'] === 0 ? 'первичный' : 'корр. №' . (int) $gr['revision'],
                    self::SIGN_TYPES[$gr['sign_type']] ?? (string) $gr['sign_type'], (string) $gr['signer_name'], substr((string) $gr['signed_at'], 0, 16)];
            }
        }
        Xlsx::download('coverage-timekeeping-' . $month . '.xlsx', [[
            'name' => 'Покрытие табели+графики',
            'headers' => ['Отдел', 'Документ', 'Половина', 'Ревизия', 'Подпись', 'Подписал', 'Дата подписи'],
            'rows' => $rows,
        ]]);
    }

    /** Ответственный за табель: орг-табельщик (для орг-табеля) или ведущий этот отдел. */
    private function canManage(array $me, array $t): bool
    {
        $s = $this->scopeFor($me);
        return $s['org'] || ($t['department_id'] && in_array((int) $t['department_id'], $s['depts'], true));
    }

    /** Перенос подписанного табеля в архив (вручную; только после ЭП). */
    public function archive(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->loadTabel($id);
        if ($t['status'] !== 'signed') { flash('В архив можно перенести только подписанный табель.', 'error'); $this->redirect('/timesheet2'); }
        if ($me['role'] !== 'admin' && !$this->canManage($me, $t)) { flash('Нет прав на архивирование этого табеля.', 'error'); $this->redirect('/timesheet2'); }
        if ($t['archived_at'] === null) {
            Database::run('UPDATE tabels SET archived_at=?, archived_by=? WHERE id=?', [date('Y-m-d H:i:s'), $me['id'], $id]);
            self::syncMonth(substr((string) $t['period'], 0, 7));   // пересчёт ЗП: архивный табель больше не учитывается
        }
        flash('Табель перенесён в архив.');
        $this->redirect('/timesheet2');
    }

    /** Вернуть табель из архива в актуальные. */
    public function unarchive(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->loadTabel($id);
        if ($me['role'] !== 'admin' && !$this->canManage($me, $t)) { flash('Нет прав.', 'error'); $this->redirect('/timesheet2?archive=1'); }
        Database::run('UPDATE tabels SET archived_at=NULL, archived_by=NULL WHERE id=?', [$id]);
        self::syncMonth(substr((string) $t['period'], 0, 7));   // пересчёт ЗП: табель снова учитывается
        flash('Табель возвращён в актуальные.');
        $this->redirect('/timesheet2?archive=1');
    }

    /** Удаление: не-админ — только свой черновик; админ — безвозвратно любой (вкл. подписанные/архив). */
    public function destroy(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->loadTabel($id);
        $isAdmin = $me['role'] === 'admin';
        $wasArchived = $t['archived_at'] !== null;
        if (!$isAdmin) {
            if ($t['status'] !== 'draft' || $wasArchived) {
                flash('Удалить безвозвратно может только администратор. Подписанный табель переносите в архив.', 'error');
                $this->redirect('/timesheet2');
            }
            if ((int) $t['created_by'] !== (int) $me['id'] && !$this->canManage($me, $t)) {
                flash('Удалить черновик может его автор/ответственный или администратор.', 'error');
                $this->redirect('/timesheet2');
            }
        }
        $wasSigned = $t['status'] === 'signed';
        Database::run('DELETE FROM tabel_rows WHERE tabel_id = ?', [$id]);
        Database::run('DELETE FROM tabels WHERE id = ?', [$id]);
        if ($wasSigned) { self::syncMonth(substr((string) $t['period'], 0, 7)); }   // пересчёт ЗП после удаления подписанного
        flash($isAdmin ? 'Табель удалён безвозвратно.' : 'Черновик табеля удалён.');
        $this->redirect($wasArchived ? '/timesheet2?archive=1' : '/timesheet2');
    }

    public function export(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canView($me)) { $this->redirect('/'); }
        $t = $this->loadTabel($id);
        [$start, $end] = $this->range($t['period']);
        $dates = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) { $dates[] = date('d', $ts); }
        $src = Database::all(
            "SELECT r.*, u.full_name, u.position, d.name AS dept FROM tabel_rows r JOIN users u ON u.id=r.employee_id
               LEFT JOIN departments d ON d.id=u.department_id WHERE r.tabel_id=? ORDER BY d.name, u.full_name", [$id]);

        if (($t['kind'] ?? 'std') === 'shift') {
            // Две строки на сотрудника: коды (Я/Н/О) и часы (12 / 4/8) — как в форме 0504421.
            $rows = [];
            foreach ($src as $r) {
                $cells = json_decode((string) $r['cells'], true) ?: [];
                $codeLine = [$r['full_name'], $r['position'], 'Код'];
                $hourLine = ['', '', 'Часы'];
                foreach ($dates as $i => $dd) { $codeLine[] = $cells[$i]['c'] ?? ''; $hourLine[] = $cells[$i]['h'] ?? ''; }
                $codeLine[] = (int) $r['days'];
                $hourLine[] = self::fmtH((float) $r['hours']);
                $rows[] = $codeLine; $rows[] = $hourLine;
            }
            Xlsx::download("tabel-0504421-{$t['period']}-rev{$t['revision']}.xlsx", [[
                'name' => '0504421',
                'headers' => array_merge(['ФИО', 'Должность', ''], $dates, ['Итого дни/часы']),
                'rows' => $rows,
            ]]);
            return;
        }

        // Обычный (5/2): одна строка кодов на сотрудника (без часов).
        $rows = [];
        foreach ($src as $r) {
            $cells = self::cellsOf($r);
            $line = [$r['dept'] ?: '—', $r['full_name'], $r['position']];
            foreach ($dates as $i => $dd) { $line[] = $cells[$i]['c'] ?? ''; }
            $line[] = (int) $r['days'];
            $rows[] = $line;
        }
        Xlsx::download("tabel-{$t['period']}-rev{$t['revision']}.xlsx", [[
            'name' => 'Табель', 'headers' => array_merge(['Подразделение', 'ФИО', 'Должность'], $dates, ['Дней']), 'rows' => $rows,
        ]]);
    }
}
