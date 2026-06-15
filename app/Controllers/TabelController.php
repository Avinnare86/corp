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

        $tabels = Database::all(
            "SELECT t.*, d.name AS dept_name, uc.full_name AS creator, us.full_name AS signer
               FROM tabels t LEFT JOIN departments d ON d.id = t.department_id
               LEFT JOIN users uc ON uc.id = t.created_by LEFT JOIN users us ON us.id = t.signer_id
              WHERE t.period = ? ORDER BY t.department_id, t.revision", [$period]);

        $this->view('timesheet2/index', [
            'title' => 'Электронный табель',
            'month' => $month, 'half' => $half, 'period' => $period,
            'tabels' => $tabels,
            'canCreate' => (bool) ($scope['org'] || $scope['depts']),
            'scope' => $scope,
            'departments' => Database::all('SELECT * FROM departments ORDER BY name'),
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
        $deptId = $this->input('department_id') !== '' && $this->input('department_id') !== null ? (int) $this->input('department_id') : null;

        if ($deptId === null && !$scope['org']) { flash('Табель по организации может вести только орг-табельщик.', 'error'); $this->redirect('/timesheet2'); }
        if ($deptId !== null && !$scope['org'] && !in_array($deptId, $scope['depts'], true)) { flash('Нет прав на этот отдел.', 'error'); $this->redirect('/timesheet2'); }

        $rev = (int) Database::scalar(
            'SELECT COALESCE(MAX(revision),-1)+1 FROM tabels WHERE period=? AND ' . ($deptId === null ? 'department_id IS NULL' : 'department_id = ?'),
            $deptId === null ? [$period] : [$period, $deptId]);

        $tid = Database::insert('INSERT INTO tabels (period, department_id, revision, created_by) VALUES (?,?,?,?)',
            [$period, $deptId, $rev, $me['id']]);

        // строки: сотрудники охвата; предзаполнение: явка='1', утверждённый отпуск='O'
        [$start, $end] = $this->range($period);
        $emps = $deptId === null
            ? Database::all("SELECT id FROM users WHERE role IN ('employee','controller') AND is_active=1")
            : Database::all("SELECT id FROM users WHERE department_id = ? AND is_active=1", [$deptId]);
        $nDays = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        foreach ($emps as $e) {
            $marks = '';
            $days = 0;
            for ($i = 0; $i < $nDays; $i++) {
                $dte = date('Y-m-d', strtotime($start) + $i * 86400);
                $c = '0';
                if (Database::scalar("SELECT 1 FROM vacation_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?", [$e['id'], $dte, $dte])) {
                    $c = 'O';
                } elseif (Database::scalar('SELECT 1 FROM work_days WHERE employee_id=? AND work_date=? AND opened_at IS NOT NULL', [$e['id'], $dte])) {
                    $c = '1'; $days++;
                }
                $marks .= $c;
            }
            Database::insert('INSERT INTO tabel_rows (tabel_id, employee_id, day_marks, days) VALUES (?,?,?,?)', [$tid, $e['id'], $marks, $days]);
        }
        flash($rev > 0 ? "Создан корректировочный табель №{$rev}." : 'Табель сформирован из явки и отпусков.');
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
            "SELECT r.*, u.full_name, d.name AS dept_name FROM tabel_rows r JOIN users u ON u.id=r.employee_id
               LEFT JOIN departments d ON d.id=u.department_id WHERE r.tabel_id = ? ORDER BY d.name, u.full_name", [$id]);
        // мои сертификаты для блока подписи
        $certs = Database::all('SELECT * FROM user_certificates WHERE user_id = ? AND valid_to >= ? ORDER BY sign_type', [$me['id'], date('Y-m-d')]);
        $this->view('timesheet2/edit', [
            'title' => 'Табель — редактирование',
            't' => $t, 'dates' => $dates, 'rows' => $rows,
            'codes' => self::CODES, 'signTypes' => self::SIGN_TYPES, 'certs' => $certs,
        ]);
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
        $marks = $_POST['mark'] ?? [];     // mark[empId][i] = code
        $keep = array_map('intval', $_POST['keep'] ?? []); // включённые сотрудники

        Database::run('DELETE FROM tabel_rows WHERE tabel_id = ? AND employee_id NOT IN (' . (implode(',', $keep) ?: '0') . ')', [$id]);
        foreach ($keep as $empId) {
            $line = ''; $days = 0;
            for ($i = 0; $i < $nDays; $i++) {
                $c = (string) ($marks[$empId][$i] ?? '0');
                if (!isset(self::CODES[$c])) { $c = '0'; }
                $line .= $c;
                if ($c === '1') { $days++; }
            }
            $ex = Database::scalar('SELECT id FROM tabel_rows WHERE tabel_id=? AND employee_id=?', [$id, $empId]);
            if ($ex) { Database::run('UPDATE tabel_rows SET day_marks=?, days=? WHERE id=?', [$line, $days, $ex]); }
            else { Database::insert('INSERT INTO tabel_rows (tabel_id, employee_id, day_marks, days) VALUES (?,?,?,?)', [$id, $empId, $line, $days]); }
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

        // криптографический отпечаток содержимого
        $rows = Database::all('SELECT employee_id, day_marks, days FROM tabel_rows WHERE tabel_id = ? ORDER BY employee_id', [$id]);
        $secret = Settings::get('sign_secret');
        if (!$secret) { $secret = bin2hex(random_bytes(16)); Settings::set('sign_secret', $secret); }
        $now = date('Y-m-d H:i:s');
        $payload = json_encode([$t['period'], $t['department_id'], $t['revision'], $rows, $me['id'], $cert['serial'], $now]);
        $signHash = hash_hmac('sha256', $payload, $secret);

        Database::run('UPDATE tabels SET status=?, signer_id=?, sign_type=?, signed_at=?, sign_hash=?, cert_serial=? WHERE id=?',
            ['signed', $me['id'], $type, $now, $signHash, $cert['serial'], $id]);

        // синхронизация месячного табеля: последняя ПОДПИСАННАЯ ревизия каждой половины
        $this->syncMonth(substr($t['period'], 0, 7));

        flash('Табель подписан (' . self::SIGN_TYPES[$type] . ').');
        $this->redirect('/timesheet2/' . $id . '/view');
    }

    private function syncMonth(string $month): void
    {
        $byEmp = [];
        foreach (['1', '2'] as $h) {
            $period = "$month-$h";
            // последняя подписанная ревизия по каждому охвату
            $tabs = Database::all(
                "SELECT t1.* FROM tabels t1
                  WHERE t1.period = ? AND t1.status='signed'
                    AND t1.revision = (SELECT MAX(t2.revision) FROM tabels t2 WHERE t2.period=t1.period
                                        AND ((t2.department_id IS NULL AND t1.department_id IS NULL) OR t2.department_id = t1.department_id)
                                        AND t2.status='signed')", [$period]);
            foreach ($tabs as $tb) {
                foreach (Database::all('SELECT employee_id, days FROM tabel_rows WHERE tabel_id = ?', [$tb['id']]) as $r) {
                    $byEmp[$r['employee_id']][$h] = (int) $r['days']; // орг-табель может перекрыть отдел — берём последнее
                }
            }
        }
        foreach ($byEmp as $empId => $halves) {
            $tot = array_sum($halves);
            $ex = Database::scalar('SELECT id FROM timesheets WHERE employee_id=? AND period=?', [$empId, $month]);
            if ($ex) { Database::run('UPDATE timesheets SET worked_days=? WHERE id=?', [$tot, $ex]); }
            else { Database::insert('INSERT INTO timesheets (employee_id, period, norm_days, worked_days) VALUES (?,?,21,?)', [$empId, $month, $tot]); }
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
        $this->view('timesheet2/view', [
            'title' => 'Табель (подписан)',
            't' => $t, 'dates' => $dates, 'rows' => $rows, 'cert' => $cert, 'codes' => self::CODES,
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

    public function destroy(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $t = $this->loadTabel($id);
        if ($t['status'] !== 'draft') { flash('Подписанный табель удалить нельзя — создайте корректировочный.', 'error'); $this->redirect('/timesheet2'); }
        Database::run('DELETE FROM tabel_rows WHERE tabel_id = ?', [$id]);
        Database::run('DELETE FROM tabels WHERE id = ?', [$id]);
        flash('Черновик табеля удалён.');
        $this->redirect('/timesheet2');
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
        $rows = [];
        foreach (Database::all(
            "SELECT r.*, u.full_name, u.position, d.name AS dept FROM tabel_rows r JOIN users u ON u.id=r.employee_id
               LEFT JOIN departments d ON d.id=u.department_id WHERE r.tabel_id=? ORDER BY d.name, u.full_name", [$id]) as $r) {
            $line = [$r['dept'] ?: '—', $r['full_name'], $r['position']];
            $m = str_split((string) $r['day_marks']);
            foreach ($dates as $i => $dd) { $line[] = self::CODES[$m[$i] ?? '0'] ?? ''; }
            $line[] = (int) $r['days'];
            $rows[] = $line;
        }
        Xlsx::download("tabel-{$t['period']}-rev{$t['revision']}.xlsx", [[
            'name' => 'Табель', 'headers' => array_merge(['Подразделение', 'ФИО', 'Должность'], $dates, ['Дней']), 'rows' => $rows,
        ]]);
    }
}
