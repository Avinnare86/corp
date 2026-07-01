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

    /** Бейдж в меню: сколько заявок на изменение графика ждут решения этого пользователя. */
    public static function changeRequestsInboxCount(int $uid): int
    {
        $roles = Auth::roles($uid);
        $isPriv = !empty($roles['admin']) || !empty($roles['hr_manager']) || !empty($roles['hr']) || !empty($roles['director']);
        if ($isPriv) {
            return (int) Database::scalar("SELECT COUNT(*) FROM vacation_change_requests WHERE status='pending'");
        }
        $deptIds = array_map('intval', Org::branchDeptIds($uid));
        if (!$deptIds) { return 0; }
        $in = implode(',', array_fill(0, count($deptIds), '?'));
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM vacation_change_requests r JOIN users u ON u.id=r.employee_id
              WHERE r.status='pending' AND u.department_id IN ($in)", $deptIds);
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
        $camp = VC::current($year);
        $stage = $camp ? (string) $camp['stage'] : '';
        $this->view('vacation_campaign/booking', [
            'title' => 'Моя запись на отпуск ' . $year, 'year' => $year, 'camp' => $camp, 'stage' => $stage,
            'picks' => VC::picksOf($uid, $year), 'balance' => VS::balanceBreakdown($uid, $year),
            'planned' => VC::plannedDays($uid, $year), 'carriedOut' => VS::carriedOut($uid, $year),
            'myRequests' => Database::all('SELECT * FROM vacation_change_requests WHERE year=? AND employee_id=? ORDER BY created_at DESC', [$year, $uid]),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function addPick(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $year = $this->year();
        $uid = (int) Auth::id();
        $back = '/vacation-campaign/booking?year=' . $year;
        if (!VC::yearIsOpen($year)) { flash('Кампания на ' . $year . ' год не открыта — предложения по отпускам вводить нельзя.', 'error'); $this->redirect($back); }
        if (VC::stageOf($year) !== 'booking') {
            flash('Прямая самозапись закрыта (этап кампании прошёл). Подайте заявку на изменение графика — она пойдёт на утверждение начальнику отдела.', 'error');
            $this->redirect($back);
        }
        $start = (string) $this->input('start_date'); $end = (string) $this->input('end_date');
        $issues = VC::validatePick($uid, $year, $start, $end);
        if ($issues) { flash('Нельзя записать: ' . implode('; ', $issues) . '.', 'error'); $this->redirect($back); }
        Database::insert('INSERT INTO vacation_picks (year, employee_id, start_date, end_date, days, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$year, $uid, $start, $end, VS::calDays($start, $end), trim((string) $this->input('note')), $uid, date('Y-m-d H:i:s')]);
        flash('Период записан: ' . date('d.m.Y', strtotime($start)) . ' — ' . date('d.m.Y', strtotime($end)) . '.');
        $this->redirect($back);
    }

    public function deletePick(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $year = $this->year();
        $uid = (int) Auth::id();
        $back = '/vacation-campaign/booking?year=' . $year;
        $p = Database::one('SELECT * FROM vacation_picks WHERE id=?', [(int) $id]);
        if (!$p) { $this->redirect($back); }
        $isPriv = Auth::effectiveHas('admin', 'hr_manager');
        if (!$isPriv && (int) $p['employee_id'] !== $uid) { $this->redirect($back); }
        // Прямое удаление — только пока идёт самозапись; позже (правки после кампании) — только
        // заявкой (kind=remove) с утверждением начальника, либо напрямую кадрами/админом.
        if (!$isPriv && VC::stageOf($year) !== 'booking') {
            flash('Удалить период напрямую можно только на этапе самозаписи. Подайте заявку на изменение графика.', 'error');
            $this->redirect($back);
        }
        Database::run('DELETE FROM vacation_picks WHERE id=?', [(int) $id]);
        flash('Период удалён.');
        $this->redirect($back);
    }

    /**
     * Заявка на правку графика ПОСЛЕ самозаписи: добавить период / убрать период / перенести
     * неиспользованные дни на следующий год. Утверждает начальник отдела сотрудника (1 шаг).
     */
    public function storeChangeRequest(): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $year = $this->year();
        $uid = (int) Auth::id();
        $back = '/vacation-campaign/booking?year=' . $year;
        if (!VC::yearIsOpen($year)) { flash('Кампания на ' . $year . ' год не открыта.', 'error'); $this->redirect($back); }
        $kind = (string) $this->input('kind');
        $note = trim((string) $this->input('note'));
        $now = date('Y-m-d H:i:s');

        if ($kind === 'add') {
            $start = (string) $this->input('start_date'); $end = (string) $this->input('end_date');
            $issues = VC::validatePick($uid, $year, $start, $end);
            if ($issues) { flash('Нельзя подать заявку: ' . implode('; ', $issues) . '.', 'error'); $this->redirect($back); }
            Database::insert(
                'INSERT INTO vacation_change_requests (year, employee_id, kind, start_date, end_date, days, note, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$year, $uid, 'add', $start, $end, VS::calDays($start, $end), $note, 'pending', $uid, $now]);
        } elseif ($kind === 'remove') {
            $p = Database::one('SELECT * FROM vacation_picks WHERE id=? AND year=? AND employee_id=?', [(int) $this->input('pick_id'), $year, $uid]);
            if (!$p) { flash('Период не найден.', 'error'); $this->redirect($back); }
            Database::insert(
                'INSERT INTO vacation_change_requests (year, employee_id, kind, pick_id, start_date, end_date, days, note, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$year, $uid, 'remove', (int) $p['id'], $p['start_date'], $p['end_date'], (int) $p['days'], $note, 'pending', $uid, $now]);
        } elseif ($kind === 'carry_next_year') {
            $days = (int) $this->input('days');
            if ($days <= 0) { flash('Укажите количество дней для переноса.', 'error'); $this->redirect($back); }
            $free = VS::balanceOf($uid, $year) - VC::plannedDays($uid, $year) - VS::carriedOut($uid, $year);
            if ($days > $free) { flash('Нельзя перенести больше нераспределённого остатка (' . max(0, $free) . ' дн.).', 'error'); $this->redirect($back); }
            Database::insert(
                'INSERT INTO vacation_change_requests (year, employee_id, kind, days, note, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$year, $uid, 'carry_next_year', $days, $note, 'pending', $uid, $now]);
        } else {
            flash('Неизвестный тип заявки.', 'error'); $this->redirect($back);
        }

        $deptId = (int) (Database::scalar('SELECT department_id FROM users WHERE id=?', [$uid]) ?: 0);
        $headId = $deptId ? (int) (Database::scalar('SELECT head_id FROM departments WHERE id=?', [$deptId]) ?: 0) : 0;
        if ($headId && $headId !== $uid) {
            NotificationService::create($headId, 'Отпуска: заявка на изменение графика',
                (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$uid]) . ' подал(а) заявку на изменение графика отпусков ' . $year . ' года.');
        }
        flash('Заявка подана — ожидает решения начальника отдела.');
        $this->redirect($back);
    }

    /** Очередь заявок на правку графика — руководителю (свои отделы/ветка) или кадрам/admin (все). */
    public function changeRequestsQueue(): void
    {
        Auth::requireLogin();
        if (!$this->isHead()) { flash('Раздел доступен руководителям и кадрам.', 'error'); $this->redirect('/vacation-campaign'); }
        $year = $this->year();
        $deptIds = $this->myDepts();
        $rows = $deptIds ? Database::all(
            "SELECT r.*, u.full_name, u.department_id, d.name AS dept_name FROM vacation_change_requests r
               JOIN users u ON u.id=r.employee_id LEFT JOIN departments d ON d.id=u.department_id
              WHERE r.year=? AND u.department_id IN (" . implode(',', array_fill(0, count($deptIds), '?')) . ")
              ORDER BY CASE WHEN r.status='pending' THEN 0 ELSE 1 END, r.created_at DESC",
            array_merge([$year], $deptIds)) : [];
        $this->view('vacation_campaign/change_requests', [
            'title' => 'Заявки на изменение графика ' . $year, 'year' => $year, 'rows' => $rows, 'csrf' => Auth::csrf(),
        ]);
    }

    /** Решение по заявке на правку графика: approve — применить (пересобрать pick/строку графика/перенос), reject — отклонить. */
    public function decideChangeRequest(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $r = Database::one('SELECT * FROM vacation_change_requests WHERE id=?', [(int) $id]);
        if (!$r) { $this->redirect('/vacation-campaign/change-requests'); }
        $year = (int) $r['year'];
        $back = '/vacation-campaign/change-requests?year=' . $year;
        $empId = (int) $r['employee_id'];
        $empDept = (int) (Database::scalar('SELECT department_id FROM users WHERE id=?', [$empId]) ?: 0);
        $isPriv = Auth::effectiveHas('admin', 'hr_manager');
        if (!$isPriv && (!$empDept || !in_array($empDept, $this->myDepts(), true))) {
            flash('Заявка вне вашей зоны ответственности.', 'error'); $this->redirect($back);
        }
        if ($r['status'] !== 'pending') { flash('Заявка уже рассмотрена.', 'error'); $this->redirect($back); }

        $uid = (int) Auth::id();
        if ((string) $this->input('act') === 'reject') {
            Database::run('UPDATE vacation_change_requests SET status=?, decided_by=?, decided_at=?, reject_reason=? WHERE id=?',
                ['rejected', $uid, date('Y-m-d H:i:s'), trim((string) $this->input('reason')), (int) $id]);
            NotificationService::create($empId, 'Отпуска: заявка отклонена', 'Ваша заявка на изменение графика отпусков ' . $year . ' отклонена.');
            flash('Заявка отклонена.');
            $this->redirect($back);
        }

        // approve — повторная (авторитетная) проверка + применение эффекта
        if ($r['kind'] === 'add') {
            $issues = VC::validatePick($empId, $year, (string) $r['start_date'], (string) $r['end_date']);
            if ($issues) { flash('Нельзя одобрить: ' . implode('; ', $issues) . '.', 'error'); $this->redirect($back); }
            $pickId = Database::insert(
                'INSERT INTO vacation_picks (year, employee_id, start_date, end_date, days, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$year, $empId, $r['start_date'], $r['end_date'], (int) $r['days'], 'заявка на правку #' . $id, $uid, date('Y-m-d H:i:s')]);
            VS::syncRowForPick($year, $empDept, $empId, (string) $r['start_date'], (string) $r['end_date'], (int) $r['days'], 'add');
            Database::run('UPDATE vacation_change_requests SET pick_id=? WHERE id=?', [$pickId, (int) $id]);
        } elseif ($r['kind'] === 'remove') {
            $p = Database::one('SELECT * FROM vacation_picks WHERE id=?', [(int) $r['pick_id']]);
            if ($p) {
                VS::syncRowForPick($year, $empDept, $empId, (string) $p['start_date'], (string) $p['end_date'], (int) $p['days'], 'remove');
                Database::run('DELETE FROM vacation_picks WHERE id=?', [(int) $r['pick_id']]);
            }
        } elseif ($r['kind'] === 'carry_next_year') {
            VS::addCarriedOut($empId, $year, (int) $r['days']);
        }
        Database::run('UPDATE vacation_change_requests SET status=?, decided_by=?, decided_at=? WHERE id=?',
            ['approved', $uid, date('Y-m-d H:i:s'), (int) $id]);
        NotificationService::create($empId, 'Отпуска: заявка одобрена', 'Ваша заявка на изменение графика отпусков ' . $year . ' одобрена.');
        Audit::log('vacation_campaign.change_request_approved', 'Одобрена заявка на правку графика #' . $id . ' (' . $r['kind'] . ')');
        flash('Заявка одобрена.');
        $this->redirect($back);
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

    /**
     * Полнота заполнения отдела: у всех остаток либо распределён по датам, либо явно перенесён
     * на следующий год (carried_out) — дни не должны «теряться» молча; + ≥1 часть ≥10 раб. дней.
     */
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
            $carried = VS::carriedOut($eid, $year);
            if ($planned + $carried < $bal) {
                $issues[] = $e['full_name'] . ' — распределено ' . $planned . ($carried ? ' + перенесено ' . $carried : '') . ' из ' . $bal . ' дн.';
                continue;
            }
            $longest = 0;
            foreach ($picks as $p) { $wd = VS::workingDaysBetween($p['start_date'], $p['end_date']); if ($wd > $longest) { $longest = $wd; } }
            if ($longest < VS::MIN_LONG_PART_WD) { $issues[] = $e['full_name'] . ' — нет части ≥ ' . VS::MIN_LONG_PART_WD . ' раб. дней'; }
        }
        return $issues;
    }
}
