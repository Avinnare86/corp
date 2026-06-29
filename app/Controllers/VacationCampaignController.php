<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\VacationCampaignService as VC;
use App\Services\VacationScheduleService as VS;
use App\Services\SignService;
use App\Services\Org;
use App\Services\NotificationService;
use App\Services\Audit;

/**
 * Кампания по отпускам (этапы): остатки → запретные периоды → самозапись → подписание.
 * Кадры открывают кампанию и утверждают остатки; начальники/замы задают запретные периоды
 * и подписывают служебки по отделам; сотрудники сами вводят даты; кадры формируют график.
 */
class VacationCampaignController extends Controller
{
    public const SIGN_TYPES = ['PEP' => 'Простая ЭП', 'UNEP' => 'УНЭП', 'UKEP' => 'УКЭП'];

    private function isHr(): bool   { return Auth::effectiveHas('admin', 'hr_manager', 'hr'); }
    private function isHead(): bool  { return Auth::effectiveHas('admin', 'dept_head', 'deputy_director', 'director', 'hr_manager'); }

    private function year(): int
    {
        $y = (int) $this->input('year', 0);
        return $y >= 2020 && $y <= 2100 ? $y : (int) date('Y') + 1;
    }

    /** Отделы, доступные текущему как начальнику/заму (своя ветка); кадры/директор — все. */
    private function myDepts(): array
    {
        if (Auth::effectiveHas('admin', 'director', 'hr_manager', 'hr')) {
            return array_map(fn($d) => (int) $d['id'], Database::all('SELECT id FROM departments ORDER BY name'));
        }
        return array_map('intval', Org::branchDeptIds((int) Auth::id()));
    }

    // ===== Дашборд кампании =====
    public function index(): void
    {
        Auth::requireLogin();
        $year = $this->year();
        $camp = VC::current($year);
        $depts = Database::all('SELECT id, name FROM departments ORDER BY name');
        $myDepts = $this->myDepts();
        $memos = Database::all(
            "SELECT m.*, d.name AS dept_name FROM vacation_memos m LEFT JOIN departments d ON d.id=m.department_id
              WHERE m.year=? ORDER BY d.name", [$year]);
        $this->view('vacation_campaign/index', [
            'title'   => 'Кампания по отпускам ' . $year,
            'year'    => $year,
            'camp'    => $camp,
            'stage'   => $camp ? (string) $camp['stage'] : '',
            'stages'  => VC::STAGES,
            'isHr'    => $this->isHr(),
            'isHead'  => $this->isHead(),
            'myDepts' => $myDepts,
            'memos'   => array_filter($memos, fn($m) => $this->isHr() || in_array((int) $m['department_id'], $myDepts, true)),
            'years'   => [(int) date('Y') + 1, (int) date('Y'), (int) date('Y') + 2],
            'csrf'    => Auth::csrf(),
        ]);
    }

    public function open(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { flash('Открыть кампанию могут кадры.', 'error'); $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $res = VC::open($year, (int) Auth::id());
        flash($res['ok'] ? 'Кампания на ' . $year . ' открыта — этап «Сбор остатков».' : $res['error'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/vacation-campaign?year=' . $year);
    }

    public function approveBalances(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $res = VC::approveBalances($year, (int) Auth::id());
        flash($res['ok'] ? 'Остатки утверждены — открыт этап «Запретные периоды».' : $res['error'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/vacation-campaign?year=' . $year);
    }

    public function advance(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $res = VC::advance($year, (string) $this->input('to'), (int) Auth::id());
        flash($res['ok'] ? 'Этап изменён.' : $res['error'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/vacation-campaign?year=' . $year);
    }

    // ===== Остатки (кадры) =====
    public function balances(): void
    {
        Auth::requireLogin();
        if (!$this->isHr()) { flash('Раздел доступен кадрам.', 'error'); $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $emps = Database::all('SELECT id, full_name, position, department_id FROM users WHERE is_active=1 ORDER BY full_name');
        $bal = VS::balances($year, array_map(fn($e) => (int) $e['id'], $emps));
        $this->view('vacation_campaign/balances', [
            'title' => 'Остатки отпусков ' . $year, 'year' => $year, 'emps' => $emps, 'bal' => $bal,
            'camp' => VC::current($year), 'csrf' => Auth::csrf(),
        ]);
    }

    public function saveBalances(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $n = 0;
        foreach ((array) ($_POST['days'] ?? []) as $empId => $days) {
            VS::setBalance((int) $empId, $year, max(0, (int) $days), '', (int) Auth::id());
            $n++;
        }
        flash('Остатки сохранены (' . $n . ').');
        $this->redirect('/vacation-campaign/balances?year=' . $year);
    }

    // ===== Правила непересечения (кадры/менеджер) =====
    public function rules(): void
    {
        Auth::requireLogin();
        if (!$this->isHr()) { flash('Раздел доступен кадрам.', 'error'); $this->redirect('/vacation-campaign'); }
        $limits = [];
        foreach (Database::all('SELECT department_id, max_simultaneous FROM vacation_dept_limits') as $r) { $limits[(int) $r['department_id']] = (int) $r['max_simultaneous']; }
        $groups = Database::all('SELECT * FROM vacation_overlap_groups ORDER BY is_active DESC, name');
        $members = [];
        foreach (Database::all('SELECT m.group_id, m.employee_id, u.full_name FROM vacation_overlap_group_members m JOIN users u ON u.id=m.employee_id ORDER BY u.full_name') as $m) {
            $members[(int) $m['group_id']][] = $m;
        }
        $this->view('vacation_campaign/rules', [
            'title' => 'Правила непересечения отпусков',
            'departments' => Database::all('SELECT id, name FROM departments ORDER BY name'),
            'limits' => $limits, 'groups' => $groups, 'members' => $members,
            'employees' => Database::all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name'),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function saveDeptLimits(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        foreach ((array) ($_POST['limit'] ?? []) as $deptId => $max) {
            $max = max(0, (int) $max);
            $ex = Database::scalar('SELECT 1 FROM vacation_dept_limits WHERE department_id=?', [(int) $deptId]);
            if ($ex) { Database::run('UPDATE vacation_dept_limits SET max_simultaneous=? WHERE department_id=?', [$max, (int) $deptId]); }
            else { Database::insert('INSERT INTO vacation_dept_limits (department_id, max_simultaneous) VALUES (?,?)', [(int) $deptId, $max]); }
        }
        flash('Лимиты по отделам сохранены.');
        $this->redirect('/vacation-campaign/rules');
    }

    public function storeGroup(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        $name = trim((string) $this->input('name'));
        $max = max(1, (int) $this->input('max', 1));
        if ($name === '') { flash('Укажите название группы.', 'error'); $this->redirect('/vacation-campaign/rules'); }
        Database::insert('INSERT INTO vacation_overlap_groups (name, max_simultaneous, is_active) VALUES (?,?,1)', [$name, $max]);
        flash('Группа добавлена.');
        $this->redirect('/vacation-campaign/rules');
    }

    public function deleteGroup(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        Database::run('DELETE FROM vacation_overlap_group_members WHERE group_id=?', [(int) $id]);
        Database::run('DELETE FROM vacation_overlap_groups WHERE id=?', [(int) $id]);
        flash('Группа удалена.');
        $this->redirect('/vacation-campaign/rules');
    }

    public function addGroupMember(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        $emp = (int) $this->input('employee_id');
        if ($emp && !Database::scalar('SELECT 1 FROM vacation_overlap_group_members WHERE group_id=? AND employee_id=?', [(int) $id, $emp])) {
            Database::insert('INSERT INTO vacation_overlap_group_members (group_id, employee_id) VALUES (?,?)', [(int) $id, $emp]);
        }
        $this->redirect('/vacation-campaign/rules');
    }

    public function removeGroupMember(string $id, string $empId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        Database::run('DELETE FROM vacation_overlap_group_members WHERE group_id=? AND employee_id=?', [(int) $id, (int) $empId]);
        $this->redirect('/vacation-campaign/rules');
    }

    // ===== Запретные периоды (начальники/замы/кадры) =====
    public function blackouts(): void
    {
        Auth::requireLogin();
        if (!$this->isHead()) { flash('Раздел доступен руководителям и кадрам.', 'error'); $this->redirect('/vacation-campaign'); }
        $myDepts = $this->myDepts();
        $deptFilter = $myDepts ? (' WHERE (b.department_id IN (' . implode(',', array_fill(0, count($myDepts), '?')) . ') OR b.department_id IS NULL)') : '';
        $rows = Database::all(
            "SELECT b.*, d.name AS dept_name, u.full_name AS emp_name FROM vacation_blackouts b
               LEFT JOIN departments d ON d.id=b.department_id LEFT JOIN users u ON u.id=b.employee_id" . $deptFilter
            . ' ORDER BY b.start_date DESC', $myDepts);
        $this->view('vacation_campaign/blackouts', [
            'title' => 'Запретные периоды для отпусков',
            'rows' => $rows,
            'isHr' => $this->isHr(),
            'departments' => Database::all('SELECT id, name FROM departments WHERE id IN (' . (count($myDepts) ? implode(',', array_fill(0, count($myDepts), '?')) : '0') . ') ORDER BY name', $myDepts ?: []),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function addBlackout(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHead()) { $this->redirect('/vacation-campaign'); }
        $dept = (int) $this->input('department_id');
        $start = (string) $this->input('start_date'); $end = (string) $this->input('end_date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
            flash('Укажите корректный период.', 'error'); $this->redirect('/vacation-campaign/blackouts');
        }
        // Общеорганизационный запрет (отдел не указан) — только кадры/директор; руководитель
        // обязан выбрать отдел из своей зоны (иначе dept=0 → NULL даёт запрет на всю компанию).
        if (!$this->isHr()) {
            if (!$dept || !in_array($dept, $this->myDepts(), true)) {
                flash('Укажите отдел из вашей зоны. Общеорганизационный запрет могут ставить только кадры.', 'error');
                $this->redirect('/vacation-campaign/blackouts');
            }
        } elseif ($dept && !in_array($dept, $this->myDepts(), true)) {
            flash('Этот отдел вне вашей зоны.', 'error'); $this->redirect('/vacation-campaign/blackouts');
        }
        Database::insert('INSERT INTO vacation_blackouts (department_id, start_date, end_date, reason) VALUES (?,?,?,?)',
            [$dept ?: null, $start, $end, trim((string) $this->input('reason'))]);
        flash('Запретный период добавлен.');
        $this->redirect('/vacation-campaign/blackouts');
    }

    public function deleteBlackout(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHead()) { $this->redirect('/vacation-campaign'); }
        // Проверяем принадлежность записи зоне: чужие отделы и общеорганизационные (NULL)
        // запреты руководитель удалять не может — иначе по прямому id снимет чужие ограничения.
        $bl = Database::one('SELECT department_id FROM vacation_blackouts WHERE id=?', [(int) $id]);
        if (!$bl) { flash('Запретный период не найден.', 'error'); $this->redirect('/vacation-campaign/blackouts'); }
        $dept = $bl['department_id'] !== null ? (int) $bl['department_id'] : null;
        if ($dept === null) {
            if (!$this->isHr()) { flash('Общеорганизационные запреты снимают только кадры.', 'error'); $this->redirect('/vacation-campaign/blackouts'); }
        } elseif (!in_array($dept, $this->myDepts(), true)) {
            flash('Этот отдел вне вашей зоны.', 'error'); $this->redirect('/vacation-campaign/blackouts');
        }
        Database::run('DELETE FROM vacation_blackouts WHERE id=?', [(int) $id]);
        flash('Запретный период удалён.');
        $this->redirect('/vacation-campaign/blackouts');
    }

    // ===== Самозапись (сотрудник) =====
    public function myBooking(): void
    {
        Auth::requireLogin();
        $year = $this->year();
        $uid = (int) Auth::id();
        $this->view('vacation_campaign/booking', [
            'title' => 'Моя запись на отпуск ' . $year, 'year' => $year, 'camp' => VC::current($year),
            'picks' => VC::picksOf($uid, $year), 'balance' => VS::balanceOf($uid, $year),
            'planned' => VC::plannedDays($uid, $year), 'csrf' => Auth::csrf(),
        ]);
    }

    public function addPick(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $year = $this->year();
        $uid = (int) Auth::id();
        if (VC::stageOf($year) !== 'booking') { flash('Самозапись сейчас закрыта (этап кампании).', 'error'); $this->redirect('/vacation-campaign/booking?year=' . $year); }
        $start = (string) $this->input('start_date'); $end = (string) $this->input('end_date');
        $issues = VC::validatePick($uid, $year, $start, $end);
        if ($issues) { flash('Нельзя записать: ' . implode('; ', $issues) . '.', 'error'); $this->redirect('/vacation-campaign/booking?year=' . $year); }
        Database::insert('INSERT INTO vacation_picks (year, employee_id, start_date, end_date, days, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$year, $uid, $start, $end, VS::calDays($start, $end), trim((string) $this->input('note')), $uid, date('Y-m-d H:i:s')]);
        flash('Период записан: ' . date('d.m.Y', strtotime($start)) . ' — ' . date('d.m.Y', strtotime($end)) . '.');
        $this->redirect('/vacation-campaign/booking?year=' . $year);
    }

    public function deletePick(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $year = $this->year();
        $uid = (int) Auth::id();
        $p = Database::one('SELECT * FROM vacation_picks WHERE id=?', [(int) $id]);
        if ($p && ((int) $p['employee_id'] === $uid || Auth::effectiveHas('admin', 'hr_manager'))) {
            Database::run('DELETE FROM vacation_picks WHERE id=?', [(int) $id]);
            flash('Период удалён.');
        }
        $this->redirect('/vacation-campaign/booking?year=' . $year);
    }

    // ===== Карта отпусков =====
    public function map(): void
    {
        Auth::requireLogin();
        if (!$this->isHead()) { flash('Карта доступна руководителям и кадрам.', 'error'); $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $month = (int) $this->input('month', (int) date('n'));
        if ($month < 1 || $month > 12) { $month = 1; }
        $dept = (int) $this->input('dept', 0);
        $myDepts = $this->myDepts();
        if ($dept && !in_array($dept, $myDepts, true)) { $dept = 0; }
        $deptIds = $dept ? [$dept] : $myDepts;
        $emps = $deptIds ? Database::all(
            'SELECT id, full_name, department_id FROM users WHERE is_active=1 AND department_id IN (' . implode(',', array_fill(0, count($deptIds), '?')) . ') ORDER BY full_name', $deptIds) : [];
        $picks = [];
        foreach ($emps as $e) { $picks[(int) $e['id']] = VC::picksOf((int) $e['id'], $year); }
        $this->view('vacation_campaign/map', [
            'title' => 'Карта отпусков ' . $year, 'year' => $year, 'month' => $month, 'dept' => $dept,
            'emps' => $emps, 'picks' => $picks,
            'departments' => Database::all('SELECT id, name FROM departments WHERE id IN (' . (count($myDepts) ? implode(',', array_fill(0, count($myDepts), '?')) : '0') . ') ORDER BY name', $myDepts ?: []),
            'csrf' => Auth::csrf(),
        ]);
    }

    // ===== Служебка отдела (ЭП) → цепочка → формирование графика =====
    public function memo(string $deptId): void
    {
        Auth::requireLogin();
        if (!$this->isHead()) { $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $dept = (int) $deptId;
        if (!in_array($dept, $this->myDepts(), true)) { flash('Отдел вне вашей зоны.', 'error'); $this->redirect('/vacation-campaign'); }
        $emps = Database::all('SELECT id, full_name, position FROM users WHERE is_active=1 AND department_id=? ORDER BY full_name', [$dept]);
        $bal = VS::balances($year, array_map(fn($e) => (int) $e['id'], $emps));
        $rows = [];
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $rows[] = ['emp' => $e, 'picks' => VC::picksOf($eid, $year), 'planned' => VC::plannedDays($eid, $year), 'balance' => (int) ($bal[$eid] ?? 0)];
        }
        $this->view('vacation_campaign/memo', [
            'title' => 'Служебка на отпуск — ' . (string) Database::scalar('SELECT name FROM departments WHERE id=?', [$dept]),
            'year' => $year, 'dept' => $dept, 'rows' => $rows,
            'memo' => Database::one('SELECT * FROM vacation_memos WHERE year=? AND department_id=?', [$year, $dept]),
            'signTypes' => self::SIGN_TYPES,
            'isHr' => $this->isHr(),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function signMemo(string $deptId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHead()) { $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $dept = (int) $deptId;
        if (!in_array($dept, $this->myDepts(), true)) { flash('Отдел вне вашей зоны.', 'error'); $this->redirect('/vacation-campaign'); }
        $uid = (int) Auth::id();
        $back = '/vacation-campaign/memo/' . $dept . '?year=' . $year;
        $type = strtoupper((string) $this->input('sign_type'));
        if (!isset(self::SIGN_TYPES[$type])) { flash('Выберите вид подписи.', 'error'); $this->redirect($back); }

        $memo = Database::one('SELECT * FROM vacation_memos WHERE year=? AND department_id=?', [$year, $dept]);
        $status = $memo['status'] ?? 'new';

        // определить этап подписи и право на него
        $isAdmin = Auth::effectiveHas('admin');
        if ($status === 'new' || $status === 'draft') {
            $action = 'head';
            if (!$isAdmin && !Auth::effectiveHas('hr_manager') && !in_array($dept, Org::headedDeptIds($uid), true) && !Org::isSuperiorOfDept($uid, $dept)) {
                flash('Подписать служебку отдела может его начальник.', 'error'); $this->redirect($back);
            }
            $issues = $this->deptCompleteness($year, $dept);
            if ($issues) { flash('Нельзя подписать — не заполнено: ' . implode('; ', $issues) . '.', 'error'); $this->redirect($back); }
        } elseif ($status === 'head_signed') {
            $action = 'deputy';
            if (!$isAdmin && !(Auth::effectiveHas('deputy_director') && Org::isSuperiorOfDept($uid, $dept))) {
                flash('Утвердить служебку на этом этапе может курирующий заместитель.', 'error'); $this->redirect($back);
            }
        } elseif ($status === 'deputy_signed') {
            $action = 'director';
            if (!$isAdmin && !Auth::effectiveHas('director')) {
                flash('Утвердить служебку на этом этапе может директор.', 'error'); $this->redirect($back);
            }
        } else {
            flash('Служебка уже утверждена.', 'error'); $this->redirect($back);
        }

        // 1) аутентификация подписанта (без записи в журнал — id служебки нужен для записи)
        $payload = json_encode(['type' => 'vacation_memo', 'year' => $year, 'dept' => $dept, 'stage' => $action], JSON_UNESCAPED_UNICODE);
        $entityId = (int) ($memo['id'] ?? 0);
        $d = SignService::authAndSign('vacation_memo', $entityId, $uid, $type, (string) $this->input('password'), $payload);
        if (!$d['ok']) { flash($d['error'], 'error'); $this->redirect($back); }

        // 2) стейт-машина + запись подписи в журнал с реальным id
        if ($action === 'head') {
            if ($memo) {
                // повторная подпись после возврата на доработку: строка уже есть
                // (UNIQUE(year,department_id)) — обновляем, а не вставляем дубль.
                $entityId = (int) $memo['id'];
                Database::run('UPDATE vacation_memos SET status=?, head_id=?, head_signed_at=?, head_sign_type=?, head_sign_hash=?, reject_reason=NULL WHERE id=?',
                    ['head_signed', $uid, $d['signed_at'], $d['sign_type'], $d['sign_hash'], $entityId]);
            } else {
                $entityId = (int) Database::insert(
                    'INSERT INTO vacation_memos (year, department_id, status, head_id, head_signed_at, head_sign_type, head_sign_hash) VALUES (?,?,?,?,?,?,?)',
                    [$year, $dept, 'head_signed', $uid, $d['signed_at'], $d['sign_type'], $d['sign_hash']]);
            }
            SignService::recordSignature('vacation_memo', $entityId, $uid, $d);
            $deptName = (string) Database::scalar('SELECT name FROM departments WHERE id=?', [$dept]);
            foreach (array_unique(array_filter(array_merge(Org::superiorUserIds($uid), [Org::directorUserId()]))) as $boss) {
                NotificationService::create((int) $boss, 'Отпуска: служебка отдела на утверждение',
                    'Начальник подписал график отпусков отдела «' . $deptName . '» на ' . $year . ' — ожидает вашего утверждения.');
            }
            flash('Служебка отдела подписана и направлена на утверждение.');
        } elseif ($action === 'deputy') {
            Database::run('UPDATE vacation_memos SET status=?, deputy_id=?, deputy_signed_at=?, deputy_sign_type=?, deputy_sign_hash=? WHERE id=?',
                ['deputy_signed', $uid, $d['signed_at'], $d['sign_type'], $d['sign_hash'], $entityId]);
            SignService::recordSignature('vacation_memo', $entityId, $uid, $d);
            if ($dir = Org::directorUserId()) { NotificationService::create($dir, 'Отпуска: служебка на утверждение директором', 'Зам утвердил график отпусков отдела — ожидает вашего утверждения.'); }
            flash('Служебка утверждена — ожидает директора.');
        } else { // director
            Database::run('UPDATE vacation_memos SET status=?, director_id=?, director_signed_at=?, director_sign_type=?, director_sign_hash=? WHERE id=?',
                ['approved', $uid, $d['signed_at'], $d['sign_type'], $d['sign_hash'], $entityId]);
            SignService::recordSignature('vacation_memo', $entityId, $uid, $d);
            flash('Служебка отдела утверждена директором — кадры могут сформировать график.');
        }
        $this->redirect($back);
    }

    public function rejectMemo(string $deptId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!Auth::effectiveHas('deputy_director', 'director', 'admin')) { $this->redirect('/vacation-campaign'); }
        $dept = (int) $deptId;
        // Возврат служебки чужого отдела недопустим: revoke() гасит подпись начальника.
        if (!Auth::effectiveHas('admin') && !in_array($dept, $this->myDepts(), true)) {
            flash('Отдел вне вашей зоны.', 'error'); $this->redirect('/vacation-campaign');
        }
        $year = $this->year();
        $memo = Database::one('SELECT * FROM vacation_memos WHERE year=? AND department_id=?', [$year, $dept]);
        if ($memo && in_array($memo['status'], ['head_signed', 'deputy_signed'], true)) {
            // Возвращаем в черновик и очищаем реквизиты подписей (журнал гасится revoke),
            // чтобы строка не рассинхронизировалась с погашенными подписями.
            Database::run(
                'UPDATE vacation_memos SET status=?, reject_reason=?, head_id=NULL, head_signed_at=NULL, head_sign_type=NULL, head_sign_hash=NULL, deputy_id=NULL, deputy_signed_at=NULL, deputy_sign_type=NULL, deputy_sign_hash=NULL WHERE id=?',
                ['draft', trim((string) $this->input('reason')), (int) $memo['id']]);
            SignService::revoke('vacation_memo', (int) $memo['id'], (int) Auth::id());
            flash('Служебка возвращена начальнику на доработку.');
        }
        $this->redirect('/vacation-campaign/memo/' . $dept . '?year=' . $year);
    }

    /** Кадры: сформировать график отпусков (документ) по утверждённой служебке отдела. */
    public function formSchedule(string $deptId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isHr()) { $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $dept = (int) $deptId;
        $memo = Database::one('SELECT * FROM vacation_memos WHERE year=? AND department_id=?', [$year, $dept]);
        if (!$memo || $memo['status'] !== 'approved') { flash('Служебка отдела ещё не утверждена директором.', 'error'); $this->redirect('/vacation-campaign/memo/' . $dept . '?year=' . $year); }
        $rev = VS::nextRevision($year, $dept);
        $sid = Database::insert('INSERT INTO vacation_schedules (year, department_id, revision, status, created_by, created_at) VALUES (?,?,?,?,?,?)',
            [$year, $dept, $rev, VS::ST_DRAFT, (int) Auth::id(), date('Y-m-d H:i:s')]);
        $n = 0;
        foreach (Database::all('SELECT vp.* FROM vacation_picks vp JOIN users u ON u.id=vp.employee_id WHERE vp.year=? AND u.department_id=? ORDER BY u.full_name, vp.start_date', [$year, $dept]) as $p) {
            Database::insert('INSERT INTO vacation_schedule_rows (schedule_id, employee_id, start_date, end_date, days, status) VALUES (?,?,?,?,?,?)',
                [$sid, (int) $p['employee_id'], $p['start_date'], $p['end_date'], (int) $p['days'], VS::ROW_APPROVED]);
            $n++;
        }
        Audit::log('vacation_campaign.form_schedule', 'Сформирован график отпусков ' . $year . ' отдела #' . $dept . ' из служебки (' . $n . ' периодов)');
        flash('Сформирован график отпусков (' . $n . ' периодов) — откройте его для подписи кадрами.');
        $this->redirect('/vacation-schedule/' . $sid . '/edit');
    }

    /** Полнота заполнения отдела: у всех остаток распределён + ≥1 часть ≥10 раб. дней. */
    private function deptCompleteness(int $year, int $dept): array
    {
        $issues = [];
        $emps = Database::all('SELECT id, full_name FROM users WHERE is_active=1 AND department_id=?', [$dept]);
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $bal = VS::balanceOf($eid, $year);
            if ($bal <= 0) { continue; }
            $picks = VC::picksOf($eid, $year);
            $planned = array_sum(array_map(fn($p) => (int) $p['days'], $picks));
            if ($planned < $bal) { $issues[] = $e['full_name'] . ' — распределено ' . $planned . ' из ' . $bal . ' дн.'; continue; }
            $longest = 0;
            foreach ($picks as $p) { $wd = VS::workingDaysBetween($p['start_date'], $p['end_date']); if ($wd > $longest) { $longest = $wd; } }
            if ($longest < VS::MIN_LONG_PART_WD) { $issues[] = $e['full_name'] . ' — нет части ≥ ' . VS::MIN_LONG_PART_WD . ' раб. дней'; }
        }
        return $issues;
    }
}
