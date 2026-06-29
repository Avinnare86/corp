<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\TripService as TS;
use App\Services\TripBudgetService as TB;
use App\Services\SignService;
use App\Services\Org;
use App\Services\NotificationService;
use App\Services\Audit;

/**
 * Командировки. Заявка = служебная записка: автор (начальник отдела/зам) формирует смету
 * (суточные по сегментам + проживание + проезд + доп.расходы) с обязательными вложениями,
 * подаёт на согласование (ЭП) на имя директора; директор согласовывает (ЭП) с проверкой
 * бюджета по источнику. Бухгалтер по окончании вносит факт. Статусы:
 * Черновик → На согласовании → Утверждён (+ Отклонён/На доработке).
 */
class TripController extends Controller
{
    private const UPLOAD_DIR = __DIR__ . '/../../storage/uploads/trips';
    public const SIGN_TYPES = ['PEP' => 'Простая ЭП', 'UNEP' => 'УНЭП', 'UKEP' => 'УКЭП'];

    // ---- доступы ----
    private function canCreate(): bool   { return Auth::effectiveHas('admin', 'dept_head', 'deputy_director', 'director'); }
    private function isDirector(): bool  { return Auth::effectiveHas('admin', 'director'); }
    private function isAccountant(): bool{ return Auth::effectiveHas('admin', 'accountant'); }
    private function seesAll(): bool     { return Auth::effectiveHas('admin', 'director', 'accountant', 'finance_manager'); }

    private function load(string $id): array
    {
        $t = Database::one('SELECT * FROM trip_requests WHERE id = ?', [(int) $id]);
        if (!$t) { flash('Заявка на командировку не найдена.', 'error'); $this->redirect('/trips'); }
        return $t;
    }

    private function canSee(array $me, array $t): bool
    {
        if ($this->seesAll()) { return true; }
        $uid = (int) $me['id'];
        if ((int) $t['author_id'] === $uid || (int) $t['employee_id'] === $uid) { return true; }
        return in_array((int) $t['department_id'], array_map('intval', Org::branchDeptIds($uid)), true);
    }

    private function canEdit(array $me, array $t): bool
    {
        return in_array($t['status'], ['draft', 'revision'], true)
            && ((int) $t['author_id'] === (int) $me['id'] || Auth::effectiveHas('admin'));
    }

    /** Сотрудники, которых автор может командировать (своя ветка; директор/админ — все). */
    private function scopeEmployees(array $me): array
    {
        if (Auth::effectiveHas('admin', 'director', 'hr_manager')) {
            return Database::all('SELECT id, full_name, position, department_id FROM users WHERE is_active = 1 ORDER BY full_name');
        }
        $depts = Org::branchDeptIds((int) $me['id']);
        if (!$depts) { return Database::all('SELECT id, full_name, position, department_id FROM users WHERE id = ?', [(int) $me['id']]); }
        $in = implode(',', array_fill(0, count($depts), '?'));
        return Database::all("SELECT id, full_name, position, department_id FROM users WHERE is_active = 1 AND department_id IN ($in) ORDER BY full_name", $depts);
    }

    private static function nextNumber(): string
    {
        $yy = date('y');
        $n = (int) Database::scalar("SELECT COUNT(*)+1 FROM trip_requests WHERE number LIKE ?", ['%/' . $yy]);
        return 'КМ-' . $n . '/' . $yy;
    }

    // ===== Список =====
    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canCreate() && !$this->seesAll()) { $this->redirect('/trips/my'); }

        $all = Database::all(
            "SELECT t.*, u.full_name AS emp_name, d.name AS dept_name, s.name AS source_name
               FROM trip_requests t
               JOIN users u ON u.id = t.employee_id
               LEFT JOIN departments d ON d.id = t.department_id
               LEFT JOIN pay_sources s ON s.id = t.source_id
              ORDER BY t.archived_at IS NULL DESC, t.id DESC");
        $visible = array_values(array_filter($all, fn($t) => $this->canSee($me, $t)));
        foreach ($visible as &$t) { $t['plan'] = TS::effectiveTotal($t); }
        unset($t);

        $this->view('trip/index', [
            'title'    => 'Командировки',
            'actual'   => array_filter($visible, fn($t) => $t['archived_at'] === null),
            'archive'  => array_filter($visible, fn($t) => $t['archived_at'] !== null),
            'statuses' => TS::STATUS,
            'canCreate'=> $this->canCreate(),
            'csrf'     => Auth::csrf(),
        ]);
    }

    /** Мои командировки (как командируемого) — только чтение. */
    public function my(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $rows = Database::all(
            "SELECT t.*, d.name AS dept_name, s.name AS source_name FROM trip_requests t
               LEFT JOIN departments d ON d.id = t.department_id
               LEFT JOIN pay_sources s ON s.id = t.source_id
              WHERE t.employee_id = ? AND t.archived_at IS NULL ORDER BY t.id DESC", [$uid]);
        $this->view('trip/my', ['title' => 'Мои командировки', 'rows' => $rows, 'statuses' => TS::STATUS]);
    }

    // ===== Форма (создание/редактирование черновика) =====
    public function form(string $id = ''): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->canCreate()) { flash('Создавать заявки могут начальники отделов и заместители.', 'error'); $this->redirect('/trips'); }

        $t = null;
        if ($id !== '') {
            $t = $this->load($id);
            if (!$this->canEdit($me, $t)) { $this->redirect('/trips/' . (int) $t['id']); }
        }
        $this->view('trip/form', [
            'title'      => $t ? 'Заявка на командировку' . ($t['number'] ? ' № ' . $t['number'] : '') : 'Новая заявка на командировку',
            't'          => $t,
            'employees'  => $this->scopeEmployees($me),
            'sources'    => Database::all('SELECT * FROM pay_sources ORDER BY id'),
            'kinds'      => Database::all('SELECT * FROM trip_expense_kinds WHERE is_active = 1 ORDER BY name'),
            'segments'   => $t ? TS::segments((int) $t['id']) : [],
            'extras'     => $t ? TS::extras((int) $t['id']) : [],
            'attachments'=> $t ? TS::attachments((int) $t['id']) : [],
            'estimate'   => $t ? TS::estimate($t) : null,
            'budget'     => $t ? TB::breakdown((int) $t['department_id'], TS::budgetYear($t)) : [],
            'locLabels'  => TS::LOC,
            'attKinds'   => TS::ATT_KIND,
            'signTypes'  => self::SIGN_TYPES,
            'issues'     => $t ? TS::validateForSubmit($t) : ['заполните основные поля и сохраните черновик'],
            'csrf'       => Auth::csrf(),
        ]);
    }

    /** Создать/обновить основные поля заявки. */
    public function store(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->canCreate()) { $this->redirect('/trips'); }
        $id = (int) $this->input('id');

        $empId = (int) $this->input('employee_id');
        $emp = Database::one('SELECT id, department_id FROM users WHERE id = ?', [$empId]);
        if (!$emp) { flash('Выберите командируемого сотрудника.', 'error'); $this->redirect('/trips/form' . ($id ? '/' . $id : '')); }
        $deptId = $emp['department_id'] ? (int) $emp['department_id'] : (int) ($me['department_id'] ?? 0);
        // Командировать можно только сотрудника своей структуры (защита от подбора employee_id вне ветки).
        if (!Auth::effectiveHas('admin', 'director', 'hr_manager')
            && !in_array($deptId, array_map('intval', Org::branchDeptIds((int) $me['id'])), true)) {
            flash('Можно командировать только сотрудников своей структуры.', 'error');
            $this->redirect('/trips/form' . ($id ? '/' . $id : ''));
        }
        $from = (string) $this->input('date_from');
        $to   = (string) $this->input('date_to');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            flash('Укажите корректный период командировки.', 'error'); $this->redirect('/trips/form' . ($id ? '/' . $id : ''));
        }
        $fields = [
            'department_id' => $deptId,
            'employee_id'   => $empId,
            'source_id'     => (int) $this->input('source_id'),
            'destination'   => trim((string) $this->input('destination')),
            'event'         => trim((string) $this->input('event')),
            'purpose'       => trim((string) $this->input('purpose')),
            'date_from'     => $from,
            'date_to'       => $to,
            'lodging_sum'   => (float) str_replace(',', '.', (string) $this->input('lodging_sum', 0)),
            'travel_sum'    => (float) str_replace(',', '.', (string) $this->input('travel_sum', 0)),
        ];
        if ($id) {
            $t = $this->load((string) $id);
            if (!$this->canEdit($me, $t)) { $this->redirect('/trips/' . $id); }
            Database::run('UPDATE trip_requests SET department_id=?, employee_id=?, source_id=?, destination=?, event=?, purpose=?, date_from=?, date_to=?, lodging_sum=?, travel_sum=? WHERE id=? AND status IN (\'draft\',\'revision\')',
                array_merge(array_values($fields), [$id]));
            Audit::log('trip.update', 'Изменена заявка на командировку #' . $id . ((int) $t['author_id'] !== (int) $me['id'] ? ' (администратором)' : ''));
            flash('Заявка обновлена.');
        } else {
            $id = Database::insert('INSERT INTO trip_requests (department_id, employee_id, source_id, destination, event, purpose, date_from, date_to, lodging_sum, travel_sum, status, author_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array_merge(array_values($fields), ['draft', (int) $me['id'], (int) $me['id'], date('Y-m-d H:i:s')]));
            Audit::log('trip.create', 'Создана заявка на командировку #' . $id);
            flash('Черновик заявки создан. Добавьте сегменты пребывания, доп.расходы и вложения.');
        }
        $this->redirect('/trips/form/' . $id);
    }

    // ===== Сегменты / доп.расходы / вложения =====
    private function ownDraft(string $id): array
    {
        $me = Auth::user();
        $t = $this->load($id);
        if (!$this->canEdit($me, $t)) { flash('Изменения недоступны.', 'error'); $this->redirect('/trips/' . (int) $t['id']); }
        return $t;
    }

    public function addSegment(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $t = $this->ownDraft($id);
        $s = (string) $this->input('start_date'); $e = (string) $this->input('end_date');
        $loc = $this->input('location') === 'abroad' ? 'abroad' : 'rf';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) || $e < $s) {
            flash('Укажите корректный диапазон сегмента.', 'error'); $this->redirect('/trips/form/' . (int) $id);
        }
        Database::insert('INSERT INTO trip_segments (trip_id, start_date, end_date, location) VALUES (?,?,?,?)', [(int) $id, $s, $e, $loc]);
        flash('Сегмент пребывания добавлен.');
        $this->redirect('/trips/form/' . (int) $id);
    }

    public function deleteSegment(string $id, string $segId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $this->ownDraft($id);
        Database::run('DELETE FROM trip_segments WHERE id = ? AND trip_id = ?', [(int) $segId, (int) $id]);
        $this->redirect('/trips/form/' . (int) $id);
    }

    public function addExtra(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $this->ownDraft($id);
        $kind = (int) $this->input('kind_id');
        $amount = (float) str_replace(',', '.', (string) $this->input('amount', 0));
        if ($kind && $amount > 0) {
            Database::insert('INSERT INTO trip_extra_expenses (trip_id, kind_id, amount, note) VALUES (?,?,?,?)',
                [(int) $id, $kind, $amount, trim((string) $this->input('note'))]);
            flash('Доп.расход добавлен.');
        }
        $this->redirect('/trips/form/' . (int) $id);
    }

    public function deleteExtra(string $id, string $exId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $this->ownDraft($id);
        Database::run('DELETE FROM trip_extra_expenses WHERE id = ? AND trip_id = ?', [(int) $exId, (int) $id]);
        $this->redirect('/trips/form/' . (int) $id);
    }

    public function upload(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $this->ownDraft($id);
        $kind = in_array($this->input('kind'), ['accommodation', 'travel', 'other'], true) ? $this->input('kind') : 'other';
        if (empty($_FILES['file']['name']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('Файл не выбран или ошибка загрузки.', 'error'); $this->redirect('/trips/form/' . (int) $id);
        }
        if (($_FILES['file']['size'] ?? 0) > 20971520) { flash('Файл слишком большой (макс. 20 МБ).', 'error'); $this->redirect('/trips/form/' . (int) $id); }
        if (!is_dir(self::UPLOAD_DIR)) { mkdir(self::UPLOAD_DIR, 0775, true); }
        $orig = $_FILES['file']['name'];
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $stored = bin2hex(random_bytes(16)) . ($ext ? '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext) : '');
        if (move_uploaded_file($_FILES['file']['tmp_name'], self::UPLOAD_DIR . '/' . $stored)) {
            Database::insert('INSERT INTO trip_attachments (trip_id, kind, orig_name, stored_name, size_bytes, mime, uploaded_by) VALUES (?,?,?,?,?,?,?)',
                [(int) $id, $kind, $orig, $stored, (int) $_FILES['file']['size'], (string) ($_FILES['file']['type'] ?? ''), (int) Auth::id()]);
            flash('Вложение добавлено: ' . $orig);
        } else {
            flash('Не удалось сохранить файл.', 'error');
        }
        $this->redirect('/trips/form/' . (int) $id);
    }

    public function deleteAttachment(string $id, string $attId): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $this->ownDraft($id);
        $a = Database::one('SELECT * FROM trip_attachments WHERE id = ? AND trip_id = ?', [(int) $attId, (int) $id]);
        if ($a) {
            @unlink(self::UPLOAD_DIR . '/' . $a['stored_name']);
            Database::run('DELETE FROM trip_attachments WHERE id = ?', [(int) $attId]);
        }
        $this->redirect('/trips/form/' . (int) $id);
    }

    public function downloadAttachment(string $id, string $attId): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $t = $this->load($id);
        if (!$this->canSee($me, $t)) { http_response_code(403); echo 'Нет доступа'; return; }
        $a = Database::one('SELECT * FROM trip_attachments WHERE id = ? AND trip_id = ?', [(int) $attId, (int) $id]);
        if (!$a) { http_response_code(404); echo 'Файл не найден'; return; }
        $path = self::UPLOAD_DIR . '/' . $a['stored_name'];
        if (!is_file($path)) { http_response_code(404); echo 'Файл отсутствует на диске'; return; }
        header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($a['orig_name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    // ===== Подача / согласование / отклонение =====
    public function submit(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->load($id);
        if (!$this->canEdit($me, $t)) { flash('Подать заявку может её автор.', 'error'); $this->redirect('/trips/' . (int) $id); }
        $issues = TS::validateForSubmit($t);
        if ($issues) { flash('Нельзя подать: ' . implode('; ', $issues) . '.', 'error'); $this->redirect('/trips/form/' . (int) $id); }
        $type = strtoupper((string) $this->input('sign_type'));
        if (!isset(self::SIGN_TYPES[$type])) { flash('Выберите вид подписи.', 'error'); $this->redirect('/trips/form/' . (int) $id); }

        $payload = json_encode($this->snapshot($t), JSON_UNESCAPED_UNICODE);
        $res = SignService::signDocument('trip_request', (int) $id, (int) $me['id'], $type, (string) $this->input('password'), $payload);
        if (!$res['ok']) { flash($res['error'], 'error'); $this->redirect('/trips/form/' . (int) $id); }

        $num = $t['number'] ?: self::nextNumber();
        Database::run('UPDATE trip_requests SET status=?, number=?, submitted_at=?, author_sign_type=?, author_signed_at=?, author_sign_hash=?, author_cert=? WHERE id=? AND status IN (\'draft\',\'revision\')',
            ['on_approval', $num, $res['signed_at'], $res['sign_type'], $res['signed_at'], $res['sign_hash'], $res['serial'], (int) $id]);

        $dir = Org::directorUserId();
        if ($dir) { NotificationService::create($dir, 'Заявка на командировку № ' . $num, 'На согласование: ' . $t['destination'] . ' (' . date('d.m.Y', strtotime($t['date_from'])) . '—' . date('d.m.Y', strtotime($t['date_to'])) . ')'); }
        Audit::log('trip.submit', 'Заявка на командировку № ' . $num . ' подана на согласование (#' . $id . ')');
        flash('Заявка № ' . $num . ' подписана и подана на согласование директору.');
        $this->redirect('/trips/' . (int) $id);
    }

    public function approve(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isDirector()) { flash('Согласование доступно директору.', 'error'); $this->redirect('/trips/' . (int) $id); }
        $t = $this->load($id);
        if ($t['status'] !== 'on_approval') { flash('Заявка не на согласовании.', 'error'); $this->redirect('/trips/' . (int) $id); }
        $type = strtoupper((string) $this->input('sign_type'));
        if (!isset(self::SIGN_TYPES[$type])) { flash('Выберите вид подписи.', 'error'); $this->redirect('/trips/' . (int) $id); }

        $est = TS::estimate($t);
        $g = TB::guard((int) $t['department_id'], (int) $t['source_id'], TS::budgetYear($t), $est['total'], (int) $id);
        if (!$g['ok']) {
            flash('Недостаточно бюджета командировок по источнику: смета ' . number_format($est['total'], 2, ',', ' ') . ' ₽, доступно ' . number_format($g['available'], 2, ',', ' ') . ' ₽.', 'error');
            $this->redirect('/trips/' . (int) $id);
        }
        $payload = json_encode($this->snapshot($t), JSON_UNESCAPED_UNICODE);
        $res = SignService::signDocument('trip_request', (int) $id, (int) $me['id'], $type, (string) $this->input('password'), $payload);
        if (!$res['ok']) { flash($res['error'], 'error'); $this->redirect('/trips/' . (int) $id); }

        [$dname, $dpos] = StimulusController::directorSigner();
        $signName = (string) ($me['full_name'] ?? $dname);
        $signPos  = (string) ($me['position'] ?? '') ?: $dpos;
        Database::run('UPDATE trip_requests SET status=?, plan_total=?, director_id=?, director_sign_name=?, director_sign_position=?, director_sign_type=?, director_signed_at=?, director_sign_hash=?, director_cert=? WHERE id=? AND status=\'on_approval\'',
            ['approved', $est['total'], (int) $me['id'], $signName, $signPos, $res['sign_type'], $res['signed_at'], $res['sign_hash'], $res['serial'], (int) $id]);

        NotificationService::create((int) $t['author_id'], 'Командировка утверждена', 'Заявка № ' . $t['number'] . ' (' . $t['destination'] . ') согласована директором.');
        foreach (Database::all("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='accountant' AND u.is_active=1") as $acc) {
            NotificationService::create((int) $acc['id'], 'Командировка к учёту', 'Утверждена заявка № ' . $t['number'] . '. По окончании внесите фактические расходы.');
        }
        Audit::log('trip.approve', 'Заявка на командировку № ' . $t['number'] . ' согласована (#' . $id . ')');
        flash('Заявка № ' . $t['number'] . ' согласована (' . self::SIGN_TYPES[$type] . '). План зарезервирован в бюджете.');
        $this->redirect('/trips/' . (int) $id);
    }

    public function reject(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isDirector()) { flash('Действие доступно директору.', 'error'); $this->redirect('/trips/' . (int) $id); }
        $t = $this->load($id);
        if ($t['status'] !== 'on_approval') { $this->redirect('/trips/' . (int) $id); }
        $reason = trim((string) $this->input('reason'));
        Database::run('UPDATE trip_requests SET status=?, reject_reason=? WHERE id=?', ['revision', $reason, (int) $id]);
        NotificationService::create((int) $t['author_id'], 'Командировка на доработке', 'Заявка № ' . $t['number'] . ' возвращена: ' . ($reason ?: 'без комментария'));
        Audit::log('trip.reject', 'Заявка на командировку № ' . $t['number'] . ' возвращена на доработку (#' . $id . ')');
        flash('Заявка возвращена автору на доработку.');
        $this->redirect('/trips/' . (int) $id);
    }

    // ===== Факт (бухгалтер) =====
    public function fact(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        if (!$this->isAccountant()) { flash('Факт вносит бухгалтерия.', 'error'); $this->redirect('/trips/' . (int) $id); }
        $t = $this->load($id);
        if ($t['status'] !== 'approved') { flash('Факт вносится по утверждённой заявке.', 'error'); $this->redirect('/trips/' . (int) $id); }
        $n = fn($k) => (float) str_replace(',', '.', (string) $this->input($k, 0));
        Database::run('UPDATE trip_requests SET fact_per_diem=?, fact_lodging=?, fact_travel=?, fact_other=?, fact_at=?, fact_by=? WHERE id=? AND status=\'approved\'',
            [$n('fact_per_diem'), $n('fact_lodging'), $n('fact_travel'), $n('fact_other'), date('Y-m-d H:i:s'), (int) Auth::id(), (int) $id]);
        Audit::log('trip.fact', 'Внесён факт по командировке № ' . $t['number'] . ' (#' . $id . ')');
        flash('Фактические расходы внесены — в бюджете учитывается факт.');
        $this->redirect('/trips/' . (int) $id);
    }

    // ===== Просмотр / приказ / архив =====
    public function show(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $t = $this->load($id);
        if (!$this->canSee($me, $t)) { flash('Нет доступа к заявке.', 'error'); $this->redirect('/trips'); }
        $this->view('trip/show', [
            'title'     => 'Командировка' . ($t['number'] ? ' № ' . $t['number'] : ' (черновик)'),
            't'         => $t,
            'emp'       => Database::one('SELECT full_name, position FROM users WHERE id = ?', [(int) $t['employee_id']]),
            'author'    => Database::one('SELECT full_name, position FROM users WHERE id = ?', [(int) $t['author_id']]),
            'deptName'  => Database::scalar('SELECT name FROM departments WHERE id = ?', [(int) $t['department_id']]),
            'sourceName'=> Database::scalar('SELECT name FROM pay_sources WHERE id = ?', [(int) $t['source_id']]),
            'segments'  => TS::segments((int) $id),
            'extras'    => TS::extras((int) $id),
            'attachments'=> TS::attachments((int) $id),
            'estimate'  => TS::estimate($t),
            'factTotal' => TS::factTotal($t),
            'sig'       => SignService::lastSignature('trip_request', (int) $id),
            'statuses'  => TS::STATUS, 'locLabels' => TS::LOC, 'attKinds' => TS::ATT_KIND,
            'signTypes' => self::SIGN_TYPES,
            'isDirector'=> $this->isDirector(), 'isAccountant' => $this->isAccountant(),
            'isAdmin'   => Auth::effectiveHas('admin'),
            'budget'    => TB::breakdown((int) $t['department_id'], TS::budgetYear($t)),
            'csrf'      => Auth::csrf(),
        ]);
    }

    /** Печатная форма приказа о командировании (по утверждённой заявке). */
    public function order(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $t = $this->load($id);
        if (!$this->canSee($me, $t)) { flash('Нет доступа.', 'error'); $this->redirect('/trips'); }
        if ($t['status'] !== 'approved') { flash('Приказ доступен по утверждённой заявке.', 'error'); $this->redirect('/trips/' . (int) $id); }
        [$dname, $dpos] = StimulusController::directorSigner();
        $this->view('trip/order', [
            'title'   => 'Приказ о командировании',
            't'        => $t,
            'emp'      => Database::one('SELECT full_name, position FROM users WHERE id = ?', [(int) $t['employee_id']]),
            'deptName' => Database::scalar('SELECT name FROM departments WHERE id = ?', [(int) $t['department_id']]),
            'sourceName' => Database::scalar('SELECT name FROM pay_sources WHERE id = ?', [(int) $t['source_id']]),
            'days'     => TS::calDays($t['date_from'], $t['date_to']),
            'dirName' => $t['director_sign_name'] ?: $dname,
            'dirPos'  => $t['director_sign_position'] ?: $dpos,
        ], false);
    }

    public function archive(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->load($id);
        if (!Auth::effectiveHas('admin', 'director') && (int) $t['author_id'] !== (int) $me['id']) { flash('Архивировать может автор, директор или администратор.', 'error'); $this->redirect('/trips/' . (int) $id); }
        Database::run('UPDATE trip_requests SET archived_at=?, archived_by=? WHERE id=?', [date('Y-m-d H:i:s'), (int) $me['id'], (int) $id]);
        Audit::log('trip.archive', 'Заявка на командировку #' . $id . ' в архив');
        flash('Заявка перемещена в архив.');
        $this->redirect('/trips');
    }

    public function delete(string $id): void
    {
        Auth::requireLogin(); Auth::verifyCsrf();
        $me = Auth::user();
        $t = $this->load($id);
        $isOwnDraft = in_array($t['status'], ['draft', 'revision'], true) && (int) $t['author_id'] === (int) $me['id'];
        if (!Auth::effectiveHas('admin') && !$isOwnDraft) { flash('Удалять можно только свой черновик.', 'error'); $this->redirect('/trips/' . (int) $id); }
        if ($t['status'] === 'approved' && !Auth::effectiveHas('admin')) { flash('Утверждённую заявку удалять нельзя — только в архив.', 'error'); $this->redirect('/trips/' . (int) $id); }
        foreach (TS::attachments((int) $id) as $a) { @unlink(self::UPLOAD_DIR . '/' . $a['stored_name']); }
        Database::run('DELETE FROM trip_segments WHERE trip_id = ?', [(int) $id]);
        Database::run('DELETE FROM trip_extra_expenses WHERE trip_id = ?', [(int) $id]);
        Database::run('DELETE FROM trip_attachments WHERE trip_id = ?', [(int) $id]);
        Database::run('DELETE FROM trip_requests WHERE id = ?', [(int) $id]);
        Audit::log('trip.delete', 'Удалена заявка на командировку #' . $id);
        flash('Заявка удалена.');
        $this->redirect('/trips');
    }

    /** Канонический снимок заявки для подписи. */
    private function snapshot(array $t): array
    {
        $est = TS::estimate($t);
        return [
            'id' => (int) $t['id'], 'employee_id' => (int) $t['employee_id'], 'source_id' => (int) $t['source_id'],
            'destination' => $t['destination'], 'event' => $t['event'], 'purpose' => $t['purpose'],
            'date_from' => $t['date_from'], 'date_to' => $t['date_to'],
            'segments' => array_map(fn($s) => [$s['start_date'], $s['end_date'], $s['location']], TS::segments((int) $t['id'])),
            'estimate' => $est,
        ];
    }
}
