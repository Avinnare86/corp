<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\VacationScheduleService as VS;
use App\Services\SignService;
use App\Services\Audit;

/**
 * График отпусков — документ-сущность (отдел / организация), ревизии (основной →
 * корректировочные), неизменяем после подписи. Подпись — через единый SignService
 * (ПЭП/УНЭП/УКЭП). Гейт согласования/подписи: весь остаток сотрудника распределён
 * и есть часть ≥ 10 рабочих дней. Сотрудник видит свой период «В графике».
 */
class VacationScheduleController extends Controller
{
    public const SIGN_TYPES = ['PEP' => 'Простая ЭП', 'UNEP' => 'УНЭП', 'UKEP' => 'УКЭП'];

    /** Кадровые роли (формирование/согласование/подпись графика). */
    private function isHr(array $me): bool
    {
        return Auth::effectiveHas('admin', 'hr_manager', 'hr', 'director', 'manager');
    }

    private function requireHr(): array
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isHr($me)) {
            flash('Раздел доступен кадрам и руководству.', 'error');
            $this->redirect('/vacation-schedule/my');
        }
        return $me;
    }

    private function load(string $id): array
    {
        $s = Database::one('SELECT * FROM vacation_schedules WHERE id = ?', [(int) $id]);
        if (!$s) { flash('График не найден.', 'error'); $this->redirect('/vacation-schedule'); }
        return $s;
    }

    /** Список графиков: актуальные + архив; форма создания. */
    public function index(): void
    {
        $me = $this->requireHr();
        $actual = Database::all(
            "SELECT s.*, d.name AS dept_name FROM vacation_schedules s
               LEFT JOIN departments d ON d.id = s.department_id
              WHERE s.archived_at IS NULL ORDER BY s.year DESC, dept_name, s.revision DESC");
        $archive = Database::all(
            "SELECT s.*, d.name AS dept_name FROM vacation_schedules s
               LEFT JOIN departments d ON d.id = s.department_id
              WHERE s.archived_at IS NOT NULL ORDER BY s.archived_at DESC LIMIT 100");
        $this->view('vacation_schedule/index', [
            'title'       => 'График отпусков',
            'actual'      => $actual,
            'archive'     => $archive,
            'departments' => Database::all('SELECT id, name FROM departments ORDER BY name'),
            'nextYear'    => (int) date('Y') + 1,
            'curYear'     => (int) date('Y'),
            'isAdmin'     => Auth::effectiveHas('admin'),
            'csrf'        => Auth::csrf(),
        ]);
    }

    /** Создать график (основной или корректировочный) для года и охвата. */
    public function create(): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $year   = (int) $this->input('year', (int) date('Y') + 1);
        $scope  = (string) $this->input('scope', 'org');         // org | dept
        $deptId = $scope === 'dept' && $this->input('department_id') ? (int) $this->input('department_id') : null;
        if ($scope === 'dept' && $deptId === null) {
            flash('Выберите отдел.', 'error'); $this->redirect('/vacation-schedule');
        }
        if ($open = VS::openDraft($year, $deptId)) {
            flash('Для этого охвата уже есть черновик графика — продолжите его.', 'error');
            $this->redirect('/vacation-schedule/' . $open['id'] . '/edit');
        }
        $rev = VS::nextRevision($year, $deptId);
        $newId = Database::insert(
            'INSERT INTO vacation_schedules (year, department_id, revision, status, created_by, created_at) VALUES (?,?,?,?,?,?)',
            [$year, $deptId, $rev, VS::ST_DRAFT, (int) $me['id'], date('Y-m-d H:i:s')]);

        // Предзаполнение из утверждённых заявок на отпуск этого года (по сотрудникам охвата).
        $emps = VS::scopeEmployees($deptId);
        $ids  = array_map(fn($e) => (int) $e['id'], $emps);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $reqs = Database::all(
                "SELECT employee_id, start_date, end_date, days FROM vacation_requests
                  WHERE year = ? AND status = 'approved' AND employee_id IN ($in) ORDER BY employee_id, start_date",
                array_merge([$year], $ids));
            foreach ($reqs as $r) {
                Database::insert(
                    'INSERT INTO vacation_schedule_rows (schedule_id, employee_id, start_date, end_date, days, status) VALUES (?,?,?,?,?,?)',
                    [$newId, (int) $r['employee_id'], $r['start_date'], $r['end_date'], (int) $r['days'] ?: VS::calDays($r['start_date'], $r['end_date']), VS::ROW_PROPOSAL]);
            }
        }
        Audit::log('vacation_schedule.create', 'График отпусков ' . $year . ' / ' . VS::scopeLabel($deptId) . ' (рев. ' . $rev . ')');
        flash('Создан ' . ($rev === 0 ? 'основной' : 'корректировочный (рев. ' . $rev . ')') . ' график отпусков на ' . $year . ' — ' . VS::scopeLabel($deptId) . '.');
        $this->redirect('/vacation-schedule/' . $newId . '/edit');
    }

    /** Рабочий экран графика: сотрудники охвата, остатки, периоды, проверка, подпись. */
    public function edit(string $id): void
    {
        $me = $this->requireHr();
        $s = $this->load($id);
        if ($s['status'] === VS::ST_SIGNED) { $this->redirect('/vacation-schedule/' . $id . '/view'); }
        $deptId = $s['department_id'] !== null ? (int) $s['department_id'] : null;
        $emps   = VS::scopeEmployees($deptId);
        $bal    = VS::balances((int) $s['year'], array_map(fn($e) => (int) $e['id'], $emps));
        $rows   = VS::rows((int) $s['id']);
        $byEmp  = [];
        foreach ($rows as $r) { $byEmp[(int) $r['employee_id']][] = $r; }
        $check  = VS::validate($s);
        $this->view('vacation_schedule/edit', [
            'title'    => 'График отпусков ' . $s['year'] . ' — ' . VS::scopeLabel($deptId),
            's'        => $s,
            'scope'    => VS::scopeLabel($deptId),
            'employees'=> $emps,
            'balances' => $bal,
            'byEmp'    => $byEmp,
            'check'    => $check,
            'minWd'    => VS::MIN_LONG_PART_WD,
            'signTypes'=> self::SIGN_TYPES,
            'csrf'     => Auth::csrf(),
        ]);
    }

    /** Добавить период отпуска сотруднику. */
    public function addRow(string $id): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $s = $this->load($id);
        if ($s['status'] === VS::ST_SIGNED) { flash('График подписан — изменение невозможно.', 'error'); $this->redirect('/vacation-schedule/' . $id . '/view'); }
        $emp   = (int) $this->input('employee_id');
        $start = (string) $this->input('start_date');
        $end   = (string) $this->input('end_date');
        if (!$emp || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
            flash('Укажите сотрудника и корректный период (дата окончания не раньше начала).', 'error');
            $this->redirect('/vacation-schedule/' . $id . '/edit');
        }
        $days = VS::calDays($start, $end);
        Database::insert(
            'INSERT INTO vacation_schedule_rows (schedule_id, employee_id, start_date, end_date, days, status, note) VALUES (?,?,?,?,?,?,?)',
            [(int) $id, $emp, $start, $end, $days, VS::ROW_PROPOSAL, trim((string) $this->input('note'))]);
        Audit::log('vacation_schedule.row_add', 'Период отпуска добавлен в график #' . $id, ['emp' => $emp, 'from' => $start, 'to' => $end]);
        flash('Период добавлен: ' . $start . ' — ' . $end . ' (' . $days . ' дн.).');
        $this->redirect('/vacation-schedule/' . $id . '/edit');
    }

    /** Удалить период. */
    public function deleteRow(string $id, string $rowId): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $s = $this->load($id);
        if ($s['status'] === VS::ST_SIGNED) { flash('График подписан — изменение невозможно.', 'error'); $this->redirect('/vacation-schedule/' . $id . '/view'); }
        Database::run('DELETE FROM vacation_schedule_rows WHERE id = ? AND schedule_id = ?', [(int) $rowId, (int) $id]);
        flash('Период удалён.');
        $this->redirect('/vacation-schedule/' . $id . '/edit');
    }

    /** Согласовать / вернуть в предложение период (или все периоды сотрудника). */
    public function setRowStatus(string $id): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $s = $this->load($id);
        if ($s['status'] === VS::ST_SIGNED) { flash('График подписан — изменение невозможно.', 'error'); $this->redirect('/vacation-schedule/' . $id . '/view'); }
        $to  = $this->input('to') === VS::ROW_APPROVED ? VS::ROW_APPROVED : VS::ROW_PROPOSAL;
        $emp = (int) $this->input('employee_id');

        // Согласование сотрудника требует выполнения правил (полнота плана + часть ≥ 10 раб. дней).
        if ($to === VS::ROW_APPROVED && $emp) {
            $check = VS::validate($s);
            $issues = $check['byEmp'][$emp]['issues'] ?? [];
            if ($issues) {
                flash('Нельзя согласовать: ' . implode('; ', $issues) . '.', 'error');
                $this->redirect('/vacation-schedule/' . $id . '/edit');
            }
        }
        if ($emp) {
            Database::run('UPDATE vacation_schedule_rows SET status = ? WHERE schedule_id = ? AND employee_id = ?', [$to, (int) $id, $emp]);
        } else {
            Database::run('UPDATE vacation_schedule_rows SET status = ? WHERE schedule_id = ?', [$to, (int) $id]);
        }
        flash($to === VS::ROW_APPROVED ? 'Согласовано.' : 'Возвращено в «Предложение».');
        $this->redirect('/vacation-schedule/' . $id . '/edit');
    }

    /** Ввод/обновление остатка отпуска сотрудника (кадровик). */
    public function saveBalance(string $id): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $s = $this->load($id);
        $emp  = (int) $this->input('employee_id');
        $days = max(0, (int) $this->input('days'));
        if ($emp) {
            VS::setBalance($emp, (int) $s['year'], $days, trim((string) $this->input('note')), (int) $me['id']);
            flash('Остаток обновлён: ' . $days . ' дн.');
        }
        $this->redirect('/vacation-schedule/' . $id . '/edit');
    }

    /** Подписать график (ЭП). Гейт: все сотрудники охвата прошли проверку и все строки согласованы. */
    public function sign(string $id): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $s = $this->load($id);
        if ($s['status'] === VS::ST_SIGNED) { $this->redirect('/vacation-schedule/' . $id . '/view'); }
        $type = strtoupper((string) $this->input('sign_type'));
        if (!isset(self::SIGN_TYPES[$type])) { flash('Выберите вид подписи.', 'error'); $this->redirect('/vacation-schedule/' . $id . '/edit'); }

        $check = VS::validate($s);
        if (!$check['ok']) {
            flash('Подпись невозможна: не у всех сотрудников распределён весь отпуск или нет части ≥ ' . VS::MIN_LONG_PART_WD . ' рабочих дней. Исправьте отмеченные строки.', 'error');
            $this->redirect('/vacation-schedule/' . $id . '/edit');
        }
        $notApproved = (int) Database::scalar('SELECT COUNT(*) FROM vacation_schedule_rows WHERE schedule_id = ? AND status <> ?', [(int) $id, VS::ROW_APPROVED]);
        $total = (int) Database::scalar('SELECT COUNT(*) FROM vacation_schedule_rows WHERE schedule_id = ?', [(int) $id]);
        if ($total === 0) { flash('В графике нет периодов отпуска.', 'error'); $this->redirect('/vacation-schedule/' . $id . '/edit'); }
        if ($notApproved > 0) { flash('Сначала согласуйте все периоды (есть несогласованные).', 'error'); $this->redirect('/vacation-schedule/' . $id . '/edit'); }

        // Канонический снимок содержимого — то, что подписывается.
        $rows = VS::rows((int) $id);
        $snapshot = [
            'year' => (int) $s['year'], 'department_id' => $s['department_id'], 'revision' => (int) $s['revision'],
            'rows' => array_map(fn($r) => [$r['employee_id'], $r['full_name'], $r['start_date'], $r['end_date'], (int) $r['days']], $rows),
        ];
        $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        $res = SignService::signDocument('vacation_schedule', (int) $id, (int) $me['id'], $type, (string) $this->input('password'), $payload);
        if (!$res['ok']) { flash($res['error'], 'error'); $this->redirect('/vacation-schedule/' . $id . '/edit'); }

        Database::run(
            'UPDATE vacation_schedules SET status=?, signer_id=?, signer_name=?, signer_position=?, sign_type=?, signed_at=?, sign_hash=?, cert_serial=?, snapshot=? WHERE id=?',
            [VS::ST_SIGNED, (int) $me['id'], (string) ($me['full_name'] ?? ''), (string) ($me['position'] ?? ''),
             $res['sign_type'], $res['signed_at'], $res['sign_hash'], $res['serial'], $payload, (int) $id]);

        Audit::log('vacation_schedule.sign', 'Подписан график отпусков #' . $id . ' (' . self::SIGN_TYPES[$type] . ')');
        flash('График отпусков подписан (' . self::SIGN_TYPES[$type] . '). Сотрудникам показан их период.');
        $this->redirect('/vacation-schedule/' . $id . '/view');
    }

    /** Просмотр подписанного (или черновика) графика. */
    public function show(string $id): void
    {
        $me = $this->requireHr();
        $s = $this->load($id);
        $deptId = $s['department_id'] !== null ? (int) $s['department_id'] : null;
        $this->view('vacation_schedule/view', [
            'title' => 'График отпусков ' . $s['year'] . ' — ' . VS::scopeLabel($deptId),
            's'     => $s,
            'scope' => VS::scopeLabel($deptId),
            'rows'  => VS::rows((int) $id),
            'sig'   => SignService::lastSignature('vacation_schedule', (int) $id),
            'isAdmin' => Auth::effectiveHas('admin'),
            'csrf'  => Auth::csrf(),
        ]);
    }

    /** Архивировать график. */
    public function archive(string $id): void
    {
        $me = $this->requireHr();
        Auth::verifyCsrf();
        $s = $this->load($id);
        Database::run('UPDATE vacation_schedules SET archived_at = ?, archived_by = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), (int) $me['id'], (int) $id]);
        Audit::log('vacation_schedule.archive', 'График отпусков #' . $id . ' в архив');
        flash('График перемещён в архив.');
        $this->redirect('/vacation-schedule');
    }

    /** Безвозвратное удаление (только админ, неподписанный). */
    public function delete(string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        $s = $this->load($id);
        if ($s['status'] === VS::ST_SIGNED) { flash('Подписанный график удалять нельзя — только в архив.', 'error'); $this->redirect('/vacation-schedule/' . $id . '/view'); }
        Database::run('DELETE FROM vacation_schedule_rows WHERE schedule_id = ?', [(int) $id]);
        Database::run('DELETE FROM vacation_schedules WHERE id = ?', [(int) $id]);
        Audit::log('vacation_schedule.delete', 'Удалён черновик графика отпусков #' . $id);
        flash('Черновик графика удалён.');
        $this->redirect('/vacation-schedule');
    }

    /** Сотруднику: его периоды отпуска «В графике» (из подписанных графиков). */
    public function my(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $rows = Database::all(
            "SELECT r.start_date, r.end_date, r.days, s.year, s.revision, s.signed_at, d.name AS dept_name
               FROM vacation_schedule_rows r
               JOIN vacation_schedules s ON s.id = r.schedule_id
               LEFT JOIN departments d ON d.id = s.department_id
              WHERE r.employee_id = ? AND r.status = ? AND s.status = ? AND s.archived_at IS NULL
              ORDER BY s.year DESC, r.start_date", [$uid, VS::ROW_APPROVED, VS::ST_SIGNED]);
        $this->view('vacation_schedule/my', [
            'title' => 'Мой отпуск (по графику)',
            'rows'  => $rows,
        ]);
    }
}
