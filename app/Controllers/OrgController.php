<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

/** Оргструктура: подразделения, руководители, состав; типы документов. */
class OrgController extends Controller
{
    /** Виды узлов структуры. «Должностные» узлы (заместитель/советник) — лицо с подчинёнными подразделениями. */
    public const KINDS = ['дирекция', 'заместитель', 'советник', 'управление', 'центр', 'отдел'];
    public const PERSON_KINDS = ['заместитель', 'советник']; // узел = должностное лицо (head_id)

    /** Обзор: дерево подчинённости + ссылки на разделы. */
    public function index(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        $departments = Database::all(
            'SELECT d.*, u.full_name AS head_name, c.full_name AS curator_name,
                    (SELECT COUNT(*) FROM users m WHERE m.department_id = d.id AND m.is_active=1) AS members
               FROM departments d
               LEFT JOIN users u ON u.id = d.head_id
               LEFT JOIN users c ON c.id = d.curator_id
              ORDER BY d.name'
        );
        $this->view('admin/org_overview', [
            'title' => 'Оргструктура — обзор',
            'departments' => $departments,
            'nav' => 'overview',
        ]);
    }

    /** Раздел: подразделения и подчинённость (иерархия). */
    public function departments(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        $departments = Database::all(
            'SELECT d.*, u.full_name AS head_name, c.full_name AS curator_name, p.name AS parent_name,
                    (SELECT COUNT(*) FROM users m WHERE m.department_id = d.id AND m.is_active=1) AS members
               FROM departments d
               LEFT JOIN users u ON u.id = d.head_id
               LEFT JOIN users c ON c.id = d.curator_id
               LEFT JOIN departments p ON p.id = d.parent_id
              ORDER BY d.name'
        );
        $users = Database::all("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name");
        $this->view('admin/org_departments', [
            'title' => 'Подразделения и подчинённость',
            'departments' => $departments,
            'users' => $users,
            'nav' => 'departments',
        ]);
    }

    /** Раздел: сотрудники по подразделениям (простое назначение). */
    public function staff(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        $departments = Database::all('SELECT id, name FROM departments ORDER BY name');
        $deptFilter = $this->input('dept');
        $where = 'u.is_active = 1';
        $params = [];
        if ($deptFilter === 'none') { $where .= ' AND u.department_id IS NULL'; }
        elseif ($deptFilter) { $where .= ' AND u.department_id = ?'; $params[] = (int) $deptFilter; }
        $users = Database::all(
            "SELECT u.id, u.full_name, u.position, u.department_id FROM users u WHERE $where ORDER BY u.full_name", $params);
        $this->view('admin/org_staff', [
            'title' => 'Сотрудники по подразделениям',
            'departments' => $departments,
            'users' => $users,
            'deptFilter' => $deptFilter,
            'nav' => 'staff',
        ]);
    }

    /** Раздел: роли, проекты и замещение. */
    public function rolesPage(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        $departments = Database::all('SELECT id, name FROM departments ORDER BY name');
        $projects = Database::all('SELECT * FROM projects');
        $userProjects = [];
        foreach (Database::all('SELECT * FROM user_projects') as $up) { $userProjects[$up['user_id']][] = $up['project_code']; }
        $usersFull = Database::all(
            'SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.is_active = 1 ORDER BY u.full_name');
        $rolesCatalog = Database::all('SELECT * FROM roles ORDER BY sort');
        $userRoles = [];
        foreach (Database::all('SELECT user_id, role_slug FROM user_roles') as $ur) { $userRoles[$ur['user_id']][] = $ur['role_slug']; }
        $q = mb_strtolower(trim((string) $this->input('q')));
        if ($q !== '') { $usersFull = array_values(array_filter($usersFull, fn($u) => mb_strpos(mb_strtolower($u['full_name']), $q) !== false)); }
        $this->view('admin/org_roles', [
            'title' => 'Роли, проекты и замещение',
            'usersFull' => $usersFull,
            'departments' => $departments,
            'projects' => $projects,
            'userProjects' => $userProjects,
            'rolesCatalog' => $rolesCatalog,
            'userRoles' => $userRoles,
            'q' => $q,
            'nav' => 'roles',
        ]);
    }

    /** Раздел: сертификаты ЭП. */
    public function certsPage(): void
    {
        Auth::requireRole('admin');
        $this->view('admin/org_certs', [
            'title' => 'Сертификаты ЭП',
            'usersFull' => Database::all("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name"),
            'certs' => Database::all('SELECT c.*, u.full_name FROM user_certificates c JOIN users u ON u.id = c.user_id ORDER BY u.full_name, c.sign_type'),
            'nav' => 'certs',
        ]);
    }

    /** Подключение сотрудника к НАБОРУ ролей (+ табельщик отдела). Замещение СЭД — в разделе Документы. */
    public function saveAccess(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $userId = (int) $this->input('user_id');
        if (!$userId) { $this->redirect('/admin/org/roles'); }

        // Набор ролей (проекты упразднены — доступ открывается ролями).
        $roles = array_map('strval', $_POST['roles'] ?? []);
        $valid = array_column(Database::all('SELECT slug FROM roles'), 'slug');
        Database::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach (array_unique($roles) as $slug) {
            if (in_array($slug, $valid, true)) {
                Database::insert('INSERT INTO user_roles (user_id, role_slug) VALUES (?,?)', [$userId, $slug]);
            }
        }
        self::syncLegacyFlags($userId);

        // Только табельщик отдела. Замещение в СЭД настраивается в разделе «Документы».
        Database::run('UPDATE users SET timekeeper_dept_id=? WHERE id=?',
            [$this->input('timekeeper_dept_id') ? (int) $this->input('timekeeper_dept_id') : null, $userId]);
        flash('Роли и доступы сотрудника обновлены.');
        $back = (string) $this->input('back');
        $this->redirect($back !== '' && str_starts_with($back, '/') ? $back : '/admin/org/roles');
    }

    /** Синхронизация устаревших флагов users.* из набора ролей (для кода, ещё читающего флаги). */
    public static function syncLegacyFlags(int $userId): void
    {
        $slugs = array_column(Database::all('SELECT role_slug FROM user_roles WHERE user_id = ?', [$userId]), 'role_slug');
        $has = fn(string $s) => in_array($s, $slugs, true) ? 1 : 0;
        Database::run(
            'UPDATE users SET does_anketas=?, does_operations=?, is_visa_manager=?, is_hr=?, is_accountant=?, is_timekeeper_org=? WHERE id=?',
            [$has('anketa_worker'), $has('piecework_worker'), $has('visa_manager'),
             $has('hr'), $has('accountant'), $has('timekeeper'), $userId]
        );
    }

    /** Назначить курирующего зама подразделению (маршрут служебки). */
    public function saveCurator(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $deptId = (int) $this->input('department_id');
        $curator = $this->input('curator_id') ? (int) $this->input('curator_id') : null;
        Database::run('UPDATE departments SET curator_id = ? WHERE id = ?', [$curator, $deptId]);
        flash('Куратор подразделения сохранён.');
        $this->redirect('/admin/org/departments');
    }

    /** Регистрация сертификата УНЭП/УКЭП загрузкой файла — реквизиты читаются из сертификата. */
    public function storeCert(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        $userId = (int) $this->input('user_id');
        $type = strtoupper((string) $this->input('sign_type'));
        if (!$userId || !in_array($type, ['UNEP', 'UKEP'], true)) {
            flash('Выберите сотрудника и вид (УНЭП/УКЭП).', 'error'); $this->redirect('/admin/org/certs');
        }
        $f = $_FILES['cert_file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('Прикрепите файл сертификата (.cer / .crt / .pem).', 'error'); $this->redirect('/admin/org/certs');
        }
        $data = \App\Services\CertParser::parse($f['tmp_name']);
        if (!$data) {
            flash('Не удалось прочитать сертификат. Нужен файл открытого ключа (.cer / .crt / .pem).', 'error'); $this->redirect('/admin/org/certs');
        }
        $owner = $data['owner'] !== '' ? $data['owner'] : (string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$userId]);
        Database::insert(
            'INSERT INTO user_certificates (user_id, sign_type, serial, owner_name, issued_at, valid_to) VALUES (?,?,?,?,?,?)',
            [$userId, $type, $data['serial'], $owner, $data['from'] ?: date('Y-m-d'), $data['to'] ?: date('Y-m-d', strtotime('+1 year'))]
        );
        flash('Сертификат зарегистрирован: ' . $owner . ($data['to'] ? ', действует до ' . $data['to'] : ''));
        $this->redirect('/admin/org/certs');
    }

    public function deleteCert(string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        Database::run('DELETE FROM user_certificates WHERE id = ?', [$id]);
        flash('Сертификат удалён.');
        $this->redirect('/admin/org/certs');
    }

    // ---------- Самообслуживание: моя ЭП (доступно всем сотрудникам) ----------
    public function myCerts(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $this->view('certs/my', [
            'title'       => 'Моя электронная подпись',
            'certs'       => Database::all('SELECT * FROM user_certificates WHERE user_id = ? ORDER BY sign_type', [$uid]),
            'dss_enabled' => \App\Services\SignService::enabled(),
            'csrf'        => Auth::csrf(),
        ]);
    }

    /** Загрузка собственного сертификата УНЭП/УКЭП (ПЭП выпускается автоматически). */
    public function storeMyCert(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $type = strtoupper((string) $this->input('sign_type'));
        if (!in_array($type, ['UNEP', 'UKEP'], true)) {
            flash('Выберите вид подписи (УНЭП/УКЭП).', 'error'); $this->redirect('/certs');
        }
        $f = $_FILES['cert_file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('Прикрепите файл сертификата (.cer / .crt / .pem).', 'error'); $this->redirect('/certs');
        }
        $data = \App\Services\CertParser::parse($f['tmp_name']);
        if (!$data) {
            flash('Не удалось прочитать сертификат. Нужен файл открытого ключа (.cer / .crt / .pem).', 'error'); $this->redirect('/certs');
        }
        $owner = $data['owner'] !== '' ? $data['owner'] : (string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$uid]);
        Database::insert(
            'INSERT INTO user_certificates (user_id, sign_type, serial, owner_name, issued_at, valid_to) VALUES (?,?,?,?,?,?)',
            [$uid, $type, $data['serial'], $owner, $data['from'] ?: date('Y-m-d'), $data['to'] ?: date('Y-m-d', strtotime('+1 year'))]
        );
        flash('Сертификат загружен: ' . $owner . ($data['to'] ? ', действует до ' . $data['to'] : ''));
        $this->redirect('/certs');
    }

    public function deleteMyCert(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        Database::run('DELETE FROM user_certificates WHERE id = ? AND user_id = ?', [$id, (int) Auth::id()]);
        flash('Сертификат удалён.');
        $this->redirect('/certs');
    }

    /** Выпустить (перевыпустить) УКЭП через централизованный сервис ЭП (sc.ined.ru). */
    public function issueUkep(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $password = (string) $this->input('password');
        if (mb_strlen($password) < 6) {
            flash('Введите пароль (не короче 6 символов) для выпуска УКЭП.', 'error'); $this->redirect('/certs');
        }
        if (!\App\Services\SignService::enabled()) {
            flash('Сервис электронной подписи не настроен. Обратитесь к администратору.', 'error'); $this->redirect('/certs');
        }
        $u = Database::one('SELECT full_name, email FROM users WHERE id = ?', [$uid]);
        $res = \App\Services\SignService::issueCert($uid, $password, (string) ($u['full_name'] ?? ''), (string) ($u['email'] ?? ''));
        if (!$res['ok']) {
            flash('Не удалось выпустить УКЭП: ' . $res['error'], 'error'); $this->redirect('/certs');
        }
        $c  = $res['certificate'];
        $vf = $c['not_before'] ? substr((string) $c['not_before'], 0, 10) : date('Y-m-d');
        $vt = $c['not_after']  ? substr((string) $c['not_after'], 0, 10)  : date('Y-m-d', strtotime('+1 year'));
        $owner = $c['common_name'] !== '' ? $c['common_name'] : (string) ($u['full_name'] ?? '');
        $existing = Database::scalar("SELECT id FROM user_certificates WHERE user_id = ? AND sign_type = 'UKEP' AND source = 'dss'", [$uid]);
        if ($existing) {
            Database::run('UPDATE user_certificates SET serial=?, owner_name=?, fingerprint=?, issued_at=?, valid_from=?, valid_to=? WHERE id=?',
                [$c['serial'], $owner, $c['fingerprint'], date('Y-m-d'), $vf, $vt, (int) $existing]);
        } else {
            Database::insert('INSERT INTO user_certificates (user_id, sign_type, serial, owner_name, fingerprint, source, issued_at, valid_from, valid_to) VALUES (?,?,?,?,?,?,?,?,?)',
                [$uid, 'UKEP', $c['serial'], $owner, $c['fingerprint'], 'dss', date('Y-m-d'), $vf, $vt]);
        }
        flash('УКЭП выпущена через сервис: ' . $owner . ($vt ? ', действует до ' . $vt : '') . '.');
        $this->redirect('/certs');
    }

    public function storeDept(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $id = (int) $this->input('id');
        $name = trim((string) $this->input('name'));
        $head = $this->input('head_id') ? (int) $this->input('head_id') : null;
        $kind = in_array($this->input('kind'), self::KINDS, true) ? $this->input('kind') : 'отдел';
        // родитель: запрет на самого себя (циклы дальше не строим — дерево мелкое)
        $parent = $this->input('parent_id') ? (int) $this->input('parent_id') : null;
        if ($parent === $id) { $parent = null; }
        if ($name === '') { flash('Укажите название подразделения.', 'error'); $this->redirect('/admin/org/departments'); }
        if ($id) {
            Database::run('UPDATE departments SET name=?, head_id=?, parent_id=?, kind=? WHERE id=?', [$name, $head, $parent, $kind, $id]);
            flash('Подразделение обновлено.');
        } else {
            Database::insert('INSERT INTO departments (name, head_id, parent_id, kind) VALUES (?,?,?,?)', [$name, $head, $parent, $kind]);
            flash('Подразделение создано.');
        }
        $this->redirect('/admin/org/departments');
    }

    public function deleteDept(string $id): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        Database::run('UPDATE users SET department_id = NULL WHERE department_id = ?', [$id]);
        Database::run('UPDATE departments SET parent_id = NULL WHERE parent_id = ?', [$id]); // открепить дочерние
        Database::run('DELETE FROM departments WHERE id = ?', [$id]);
        flash('Подразделение удалено (сотрудники и дочерние узлы откреплены).');
        $this->redirect('/admin/org/departments');
    }

    /** Справочник типов документов СЭД: название, префикс № и индекс дела (нумератор). */
    public function types(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr', 'docs_manager');
        $this->view('admin/org_types', [
            'title' => 'Типы документов',
            'types' => Database::all('SELECT * FROM doc_types ORDER BY name'),
            'nav'   => 'types',
        ]);
    }

    /** Создать/обновить тип документа. */
    public function storeType(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr', 'docs_manager');
        Auth::verifyCsrf();
        $id = (int) $this->input('id');
        $name = trim((string) $this->input('name'));
        $prefix = trim((string) $this->input('prefix')) ?: 'Д';
        $journal = trim((string) $this->input('journal_index'));
        if ($name === '') { flash('Укажите название типа.', 'error'); $this->redirect('/admin/org/types'); }
        if ($id) {
            Database::run('UPDATE doc_types SET name=?, prefix=?, journal_index=? WHERE id=?', [$name, $prefix, $journal, $id]);
            flash('Тип документа обновлён.');
        } else {
            Database::insert('INSERT INTO doc_types (name, prefix, journal_index) VALUES (?,?,?)', [$name, $prefix, $journal]);
            flash('Тип документа добавлен.');
        }
        $this->redirect('/admin/org/types');
    }

    /** Удалить тип документа (если он не используется в документах). */
    public function deleteType(string $id): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr', 'docs_manager');
        Auth::verifyCsrf();
        $used = (int) Database::scalar('SELECT COUNT(*) FROM documents WHERE type_id = ?', [(int) $id]);
        if ($used > 0) {
            flash('Нельзя удалить: тип используется в ' . $used . ' документ(ах).', 'error');
            $this->redirect('/admin/org/types');
        }
        Database::run('DELETE FROM doc_types WHERE id = ?', [(int) $id]);
        flash('Тип документа удалён.');
        $this->redirect('/admin/org/types');
    }

    /** Привязка сотрудника к подразделению. */
    public function assign(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $userId = (int) $this->input('user_id');
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;
        Database::run('UPDATE users SET department_id = ? WHERE id = ?', [$deptId, $userId]);
        flash('Сотрудник перемещён.');
        $this->redirect('/admin/org/staff' . ($this->input('back') ? '?dept=' . urlencode((string)$this->input('back')) : ''));
    }

    /**
     * Перевод сотрудника (должность/отдел/оклад/ставка) — отдельное действие с логом.
     * Закрывает текущее назначение и открывает новое с даты перевода. Расчёт ЗП за месяц
     * перевода идёт пропорционально рабочим дням ПО КАЖДОМУ назначению (PayrollService),
     * ежемесячные стимулы пересчитываются пропорционально, единовременные — не пересчитываются.
     */
    public function transfer(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $userId = (int) $this->input('user_id');
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$u) { flash('Сотрудник не найден.', 'error'); $this->redirect('/admin/employees'); }

        $effective = $this->input('effective_on') ?: date('Y-m-d');
        $deptId  = $this->input('department_id') ? (int) $this->input('department_id') : null;
        $posId   = $this->input('position_id') ? (int) $this->input('position_id') : null;
        $rate    = $this->input('rate_volume') !== null && $this->input('rate_volume') !== ''
                   ? (float) $this->input('rate_volume') : (float) ($u['rate_volume'] ?? 1);
        $reason  = trim((string) $this->input('reason'));

        // должность и оклад: из справочника или вручную
        $posTitle = (string) ($u['position'] ?? '');
        $oklad = (float) ($u['oklad'] ?? 0);
        if ($posId) {
            $pos = Database::one('SELECT * FROM positions WHERE id = ?', [$posId]);
            if ($pos) { $posTitle = $pos['title']; $oklad = (float) $pos['oklad']; }
        }
        if ($this->input('oklad') !== null && $this->input('oklad') !== '') { $oklad = (float) $this->input('oklad'); }

        $prevEnd = date('Y-m-d', strtotime($effective . ' -1 day'));

        // Гарантируем «открытое» назначение для прежнего состояния (для помесячной пропорции).
        $open = Database::one('SELECT * FROM position_assignments WHERE user_id = ? AND ended_on IS NULL ORDER BY id DESC', [$userId]);
        if (!$open) {
            Database::insert(
                'INSERT INTO position_assignments (user_id, department_id, position_id, position_title, oklad, rate_volume, started_on, ended_on, reason, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$userId, $u['department_id'], $u['position_id'], $u['position'], (float) ($u['oklad'] ?? 0),
                 (float) ($u['rate_volume'] ?? 1), date('Y-m-01', strtotime($effective)), $prevEnd, 'исходное назначение', Auth::id()]
            );
        } else {
            Database::run('UPDATE position_assignments SET ended_on = ? WHERE id = ?', [$prevEnd, $open['id']]);
        }

        // Новое назначение.
        Database::insert(
            'INSERT INTO position_assignments (user_id, department_id, position_id, position_title, oklad, rate_volume, started_on, ended_on, reason, created_by)
             VALUES (?,?,?,?,?,?,?,NULL,?,?)',
            [$userId, $deptId, $posId, $posTitle, $oklad, $rate, $effective, $reason, Auth::id()]
        );

        // Обновляем карточку сотрудника.
        Database::run(
            'UPDATE users SET department_id = ?, position_id = ?, position = ?, oklad = ?, rate_volume = ? WHERE id = ?',
            [$deptId, $posId, $posTitle, $oklad, $rate, $userId]
        );

        $deptName = $deptId ? (string) Database::scalar('SELECT name FROM departments WHERE id = ?', [$deptId]) : '—';
        \App\Services\Audit::log('TRANSFER', 'Перевод сотрудника', [
            'employee' => $u['full_name'], 'c' => $effective,
            'to' => $posTitle . ' / ' . $deptName . ' / оклад ' . $oklad . ' / ставка ' . $rate,
            'reason' => $reason,
        ]);
        flash("Перевод оформлен с {$effective}: {$posTitle}, {$deptName}. Оклад за месяц перевода считается пропорционально рабочим дням по каждой должности; ежемесячные стимулы — пропорционально, единовременные не пересчитываются.");
        $this->redirect('/admin/employees');
    }

}
