<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Settings;
use App\Services\Secrets;
use App\Services\PayrollService;

class AdminController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin');
        $stats = [
            'employees' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='employee'"),
            'dossiers'  => (int) Database::scalar('SELECT COUNT(*) FROM assignment_items'),
            'countries' => (int) Database::scalar('SELECT COUNT(*) FROM countries'),
            'errors'    => (int) Database::scalar('SELECT COUNT(*) FROM error_types WHERE is_active=1'),
        ];
        $this->view('admin/index', ['title' => 'Администрирование', 'stats' => $stats]);
    }

    // ---------- Управление данными: удаление/откат любой записи ----------
    public function dataManagement(): void
    {
        Auth::requireRole('admin');
        $entity = (string) $this->input('entity', 'visa_row');
        if (!isset(\App\Services\AdminDataService::entities()[$entity])) { $entity = 'visa_row'; }
        $q = (string) $this->input('q', '');
        $this->view('admin/data', [
            'title' => 'Управление данными',
            'entities' => \App\Services\AdminDataService::entities(),
            'entity' => $entity,
            'q' => $q,
            'rows' => \App\Services\AdminDataService::listRows($entity, $q),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function superDelete(string $entity, string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        $res = \App\Services\AdminDataService::delete($entity, (int) $id, (int) Auth::id());
        flash($res['message'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/admin/data?' . http_build_query(['entity' => $entity, 'q' => (string) $this->input('q', '')]));
    }

    public function revertStatus(string $entity, string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        $res = \App\Services\AdminDataService::revert($entity, (int) $id);
        flash($res['message'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/admin/data?' . http_build_query(['entity' => $entity, 'q' => (string) $this->input('q', '')]));
    }

    // ---------- Справочник должностей ----------
    public function positions(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        $list = Database::all(
            'SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.position_id = p.id) AS people
               FROM positions p ORDER BY p.is_active DESC, p.title'
        );
        $this->view('admin/positions', ['title' => 'Справочник должностей', 'positions' => $list]);
    }

    public function storePosition(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $id = $this->input('id');
        $title = $this->input('title');
        $oklad = (float) $this->input('oklad', 0);
        $active = (int) ($this->input('is_active') ? 1 : 0);
        if (!$title) {
            flash('Укажите название должности.', 'error');
            $this->redirect('/admin/positions');
        }
        if ($id) {
            Database::run('UPDATE positions SET title=?, oklad=?, is_active=? WHERE id=?', [$title, $oklad, $active, $id]);
            flash('Должность обновлена.');
        } else {
            if (Database::scalar('SELECT 1 FROM positions WHERE title = ?', [$title])) {
                flash('Такая должность уже есть.', 'error');
                $this->redirect('/admin/positions');
            }
            Database::insert('INSERT INTO positions (title, oklad, is_active) VALUES (?,?,?)', [$title, $oklad, $active]);
            flash('Должность добавлена.');
        }
        $this->redirect('/admin/positions');
    }

    public function deletePosition(string $id): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        $used = Database::scalar('SELECT 1 FROM users WHERE position_id = ?', [$id]);
        if ($used) {
            Database::run('UPDATE positions SET is_active = 0 WHERE id = ?', [$id]);
            flash('Должность используется сотрудниками — она деактивирована.');
        } else {
            Database::run('DELETE FROM positions WHERE id = ?', [$id]);
            flash('Должность удалена.');
        }
        $this->redirect('/admin/positions');
    }

    // ---------- Каталог причин-доработок и категорий ----------
    public function comments(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        $cat = trim((string) $this->input('cat'));
        $where = '1=1'; $params = [];
        if ($cat !== '') { $where .= ' AND category = ?'; $params[] = $cat; }
        $list = Database::all("SELECT * FROM dorabotka_comments WHERE $where ORDER BY category, text", $params);
        $categories = array_map(fn($r) => $r['category'],
            Database::all('SELECT DISTINCT category FROM dorabotka_comments ORDER BY category'));
        $this->view('admin/comments', [
            'title' => 'Причины доработок',
            'list' => $list,
            'categories' => $categories,
            'cat' => $cat,
        ]);
    }

    public function storeComment(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        $id = $this->input('id');
        $text = trim((string) $this->input('text'));
        $category = trim((string) $this->input('category')) ?: 'Прочее';
        $active = (int) ($this->input('is_active') ? 1 : 0);
        if ($text === '') { flash('Укажите текст причины.', 'error'); $this->redirect('/admin/comments'); }
        // защита от дублей по тексту (есть UNIQUE-индекс)
        $dup = Database::scalar('SELECT id FROM dorabotka_comments WHERE text = ? AND id <> ?', [$text, (int) ($id ?: 0)]);
        if ($dup) { flash('Такая причина уже есть в каталоге.', 'error'); $this->redirect('/admin/comments?cat=' . urlencode($category)); }
        if ($id) {
            Database::run('UPDATE dorabotka_comments SET text=?, category=?, is_active=? WHERE id=?', [$text, $category, $active, $id]);
            flash('Причина обновлена.');
        } else {
            Database::insert('INSERT INTO dorabotka_comments (text, category, is_active) VALUES (?,?,?)', [$text, $category, $active]);
            flash('Причина добавлена.');
        }
        $this->redirect('/admin/comments?cat=' . urlencode($category));
    }

    public function deleteComment(string $id): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        $used = Database::scalar('SELECT 1 FROM item_comments WHERE comment_id = ?', [$id]);
        if ($used) {
            Database::run('UPDATE dorabotka_comments SET is_active = 0 WHERE id = ?', [$id]);
            flash('Причина использовалась — деактивирована.');
        } else {
            Database::run('DELETE FROM dorabotka_comments WHERE id = ?', [$id]);
            flash('Причина удалена.');
        }
        $this->redirect('/admin/comments');
    }

    public function renameCategory(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        $from = trim((string) $this->input('from'));
        $to = trim((string) $this->input('to'));
        if ($from !== '' && $to !== '') {
            Database::run('UPDATE dorabotka_comments SET category=? WHERE category=?', [$to, $from]);
            flash("Категория «{$from}» переименована в «{$to}».");
        }
        $this->redirect('/admin/comments');
    }

    // ---------- Каталог операций (визы и пр. сделка) ----------
    public function operations(): void
    {
        Auth::requireRole('admin', 'visa_manager');
        $list = Database::all('SELECT * FROM operations ORDER BY is_active DESC, name');
        $this->view('admin/operations', ['title' => 'Операции (сделка)', 'operations' => $list]);
    }

    public function storeOperation(): void
    {
        Auth::requireRole('admin', 'visa_manager');
        Auth::verifyCsrf();
        $id = $this->input('id');
        $name = $this->input('name');
        $price = (float) $this->input('unit_price', 0);
        $active = (int) ($this->input('is_active') ? 1 : 0);
        $stageIn = (int) $this->input('stage', 0);
        $stage = in_array($stageIn, [1, 2, 3], true) ? $stageIn : null;  // этап визы (для акцепта/гейта ЗП)
        if (!$name) {
            flash('Укажите название операции.', 'error');
            $this->redirect('/admin/operations');
        }
        if ($id) {
            Database::run('UPDATE operations SET name=?, unit_price=?, is_active=?, stage=? WHERE id=?', [$name, $price, $active, $stage, $id]);
            flash('Операция обновлена.');
        } else {
            Database::insert('INSERT INTO operations (name, unit_price, is_active, stage) VALUES (?,?,?,?)', [$name, $price, $active, $stage]);
            flash('Операция добавлена.');
        }
        $this->redirect('/admin/operations');
    }

    public function deleteOperation(string $id): void
    {
        Auth::requireRole('admin', 'visa_manager');
        Auth::verifyCsrf();
        $used = Database::scalar('SELECT 1 FROM piecework WHERE operation_id = ?', [$id]);
        if ($used) {
            Database::run('UPDATE operations SET is_active = 0 WHERE id = ?', [$id]);
            flash('Операция использовалась — она деактивирована.');
        } else {
            Database::run('DELETE FROM operations WHERE id = ?', [$id]);
            flash('Операция удалена.');
        }
        $this->redirect('/admin/operations');
    }

    // ---------- Фиксированные доплаты/подработки сотрудников ----------
    public function extras(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        $employees = Database::all("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
        $empId = (int) $this->input('employee', $employees[0]['id'] ?? 0);
        $extras = $empId
            ? Database::all('SELECT * FROM employee_fixed_extras WHERE employee_id = ? ORDER BY is_active DESC, name', [$empId])
            : [];
        $this->view('admin/extras', [
            'title' => 'Фикс-доплаты',
            'employees' => $employees,
            'empId' => $empId,
            'extras' => $extras,
        ]);
    }

    public function storeExtra(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        $empId = (int) $this->input('employee_id');
        $name = $this->input('name');
        $amount = (float) $this->input('monthly_amount', 0);
        if (!$empId || !$name) {
            flash('Выберите сотрудника и укажите название.', 'error');
            $this->redirect('/admin/extras?employee=' . $empId);
        }
        Database::insert(
            'INSERT INTO employee_fixed_extras (employee_id, name, monthly_amount, is_active) VALUES (?,?,?,1)',
            [$empId, $name, $amount]
        );
        flash('Фикс-доплата добавлена.');
        $this->redirect('/admin/extras?employee=' . $empId);
    }

    public function deleteExtra(string $id): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        $empId = (int) Database::scalar('SELECT employee_id FROM employee_fixed_extras WHERE id = ?', [$id]);
        Database::run('DELETE FROM employee_fixed_extras WHERE id = ?', [$id]);
        flash('Фикс-доплата удалена.');
        $this->redirect('/admin/extras?employee=' . $empId);
    }

    // ---------- Сотрудники ----------
    /** Группы по первой букве фамилии (по несколько букв). */
    private const LETTER_GROUPS = [
        'А–В' => 'АБВ', 'Г–Е' => 'ГДЕЁ', 'Ж–К' => 'ЖЗИЙК', 'Л–Н' => 'ЛМН',
        'О–Р' => 'ОПР', 'С–У' => 'СТУ', 'Ф–Ч' => 'ФХЦЧ', 'Ш–Я' => 'ШЩЪЫЬЭЮЯ',
    ];

    private static function letterGroup(string $name): string
    {
        $ch = mb_strtoupper(mb_substr(trim($name), 0, 1));
        foreach (self::LETTER_GROUPS as $key => $set) {
            if (mb_strpos($set, $ch) !== false) { return $key; }
        }
        return 'Прочее';
    }

    /** Список сотрудников: НЕ грузим всех сразу — по буквам/поиску, компактно. */
    public function employees(): void
    {
        // Раздел «Сотрудники» — только кадры (кадровик/менеджер кадров) и директор (+ админ).
        Auth::requireRole('admin', 'hr_manager', 'hr', 'director');
        $q = trim((string) $this->input('q'));
        $letter = (string) $this->input('letter');

        // Лёгкая выборка (без расчёта ЗП): ФИО, отдел, должность, ставка.
        $all = Database::all(
            'SELECT u.id, u.full_name, u.position, u.rate_volume, u.is_active, u.role, d.name AS dept_name
               FROM users u LEFT JOIN departments d ON d.id = u.department_id
              ORDER BY u.full_name');

        // Счётчики по группам — для навигации по буквам.
        $counts = [];
        foreach ($all as $u) { $g = self::letterGroup($u['full_name']); $counts[$g] = ($counts[$g] ?? 0) + 1; }

        // Что показываем: поиск важнее буквы; без выбора — список не грузим.
        $shown = [];
        if ($q !== '') {
            foreach ($all as $u) { if (mb_stripos($u['full_name'], $q) !== false) { $shown[] = $u; } }
        } elseif ($letter === 'all') {
            $shown = $all;
        } elseif ($letter !== '') {
            foreach ($all as $u) { if (self::letterGroup($u['full_name']) === $letter) { $shown[] = $u; } }
        }

        $this->view('admin/employees', [
            'title' => 'Сотрудники',
            'allPositions' => Database::all('SELECT * FROM positions WHERE is_active = 1 ORDER BY title'),
            'groups' => array_keys(self::LETTER_GROUPS),
            'counts' => $counts,
            'total' => count($all),
            'shown' => $shown,
            'q' => $q,
            'letter' => $letter,
        ]);
    }

    /** Является ли $meId руководителем сотрудника по структуре (глава/куратор отдела или выше по цепочке). */
    private static function isStructuralSuperior(int $meId, ?int $deptId): bool
    {
        return \App\Services\Org::isSuperiorOfDept($meId, $deptId);
    }

    /** Карточка одного сотрудника: подробности, перевод, удаление + роли/доступы + надбавка. */
    public function employeeCard(string $id): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr', 'director');
        $u = Database::one(
            'SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?', [$id]);
        if (!$u) { flash('Сотрудник не найден.', 'error'); $this->redirect('/admin/employees'); }
        $period = $this->input('period', date('Y-m'));
        $meId = (int) Auth::id();

        // Кадры управляют данными/ролями; надбавку видит и ставит бухгалтерия (+директор/админ/руководители — видят).
        $canManage = Auth::has('hr_manager', 'hr') || Auth::isAdmin();
        $canEditAllowance = Auth::has('accountant') || Auth::isAdmin();
        $isSuperior = Auth::has('director') || Auth::isAdmin() || self::isStructuralSuperior($meId, $u['department_id'] ? (int) $u['department_id'] : null);
        // Надбавку НЕ показываем кадрам (если только они одновременно не бухгалтер/директор/руководитель).
        $canSeeAllowance = $canEditAllowance || $isSuperior;

        $this->view('admin/employee_card', [
            'title' => $u['full_name'],
            'u' => $u,
            'payroll' => $u['role'] === 'employee' ? PayrollService::calculate((int) $u['id'], $period) : null,
            'period' => $period,
            'canManage' => $canManage,
            'canSeeAllowance' => $canSeeAllowance,
            'canEditAllowance' => $canEditAllowance,
            'stimGrounds' => Database::all('SELECT * FROM stimulus_grounds WHERE is_active=1 AND category IN (?,?) ORDER BY category, percent DESC, text', \App\Controllers\StimulusController::CATS_STAFF),
            'paySources' => Database::all('SELECT * FROM pay_sources ORDER BY id'),
            'allowanceGrants' => Database::all(
                "SELECT g.*, (SELECT COUNT(*) FROM stimulus_memos m WHERE m.grant_id=g.id) AS memos,
                        (SELECT COUNT(*) FROM stimulus_memos m WHERE m.grant_id=g.id AND m.status='approved') AS approved
                   FROM allowance_grants g WHERE g.user_id=? ORDER BY g.id DESC", [$u['id']]),
            'allPositions' => Database::all('SELECT * FROM positions WHERE is_active = 1 ORDER BY title'),
            'departments' => Database::all('SELECT id, name FROM departments ORDER BY name'),
            'rolesCatalog' => Database::all('SELECT * FROM roles ORDER BY sort'),
            'myRoles' => array_column(Database::all('SELECT role_slug FROM user_roles WHERE user_id=?', [$u['id']]), 'role_slug'),
            'users' => Database::all("SELECT id, full_name FROM users WHERE is_active=1 AND id<>? ORDER BY full_name", [$u['id']]),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function storeEmployee(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();

        $login = $this->input('login');
        $name  = $this->input('full_name');
        $pass  = $this->input('password');
        if (!$login || !$name || !$pass) {
            flash('Заполните ФИО, логин и пароль.', 'error');
            $this->redirect('/admin/employees');
        }
        if (Database::scalar('SELECT 1 FROM users WHERE login = ?', [$login])) {
            flash('Логин уже занят.', 'error');
            $this->redirect('/admin/employees');
        }
        $positionId = $this->input('position_id') ? (int) $this->input('position_id') : null;
        $posTitle = '';
        $oklad = (float) $this->input('oklad', 0);
        if ($positionId) {
            $pos = Database::one('SELECT * FROM positions WHERE id = ?', [$positionId]);
            if ($pos) {
                $posTitle = $pos['title'];
                $oklad = (float) $pos['oklad']; // оклад наследуется от должности
            }
        }
        Database::insert(
            'INSERT INTO users (full_name, login, password_hash, role, position, position_id, oklad, rate_volume, schedule_type, allowance, does_anketas, does_operations, is_active, must_change_password)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,1)',
            [
                $name, $login, password_hash($pass, PASSWORD_DEFAULT),
                $this->input('role', 'employee'),
                $posTitle, $positionId, $oklad,
                (float) $this->input('rate_volume', 1),
                $this->input('schedule_type', '5_2'),
                (float) $this->input('allowance', 0),
                (int) ($this->input('does_anketas') ? 1 : 0),
                (int) ($this->input('does_operations') ? 1 : 0),
            ]
        );
        flash('Сотрудник добавлен.');
        $this->redirect('/admin/employees');
    }

    /** Импорт сотрудников из xlsx-штатки: создаёт недостающие отделы/должности, дубли по ФИО пропускает. */
    public function importEmployees(): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('Выберите файл .xlsx со штатным расписанием.', 'error');
            $this->redirect('/admin/employees');
        }
        $people = \App\Services\EmployeeImport::parse($_FILES['file']['tmp_name']);
        if (!$people) {
            flash('Не удалось распознать сотрудников (ожидается выгрузка штатки: № / Сотрудник / Должность / Оклад, отделы — отдельными строками).', 'error');
            $this->redirect('/admin/employees');
        }
        $report = \App\Services\EmployeeImport::import($people, (int) Auth::id());
        $this->view('admin/import_result', [
            'title'  => 'Импорт сотрудников из штатки',
            'report' => $report,
            'total'  => count($people),
        ]);
    }

    public function updateEmployee(string $id): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();

        $positionId = $this->input('position_id') ? (int) $this->input('position_id') : null;
        $posTitle = '';
        $oklad = (float) $this->input('oklad', 0);
        if ($positionId) {
            $pos = Database::one('SELECT * FROM positions WHERE id = ?', [$positionId]);
            if ($pos) {
                $posTitle = $pos['title'];
                $oklad = (float) $pos['oklad'];
            }
        }
        // does_anketas/does_operations — из ролей (Оргструктура → syncLegacyFlags).
        // Надбавка НЕ здесь: её устанавливает только бухгалтерия (setAllowance), кадры её не трогают.
        $hireDate = trim((string) $this->input('hire_date', '')) ?: null;   // дата приёма; пусто → NULL
        $fireDate = trim((string) $this->input('fire_date', '')) ?: null;   // дата увольнения; пусто → NULL
        Database::run(
            'UPDATE users SET full_name=?, role=?, position=?, position_id=?, oklad=?, rate_volume=?, schedule_type=?, email=?, is_active=?, hourly_bonus_pct=?, hourly_bonus_rub=?, hire_date=?, fire_date=? WHERE id=?',
            [
                $this->input('full_name'),
                $this->input('role', 'employee'),
                $posTitle, $positionId, $oklad,
                (float) $this->input('rate_volume', 1),
                $this->input('schedule_type', '5_2'),
                trim((string) $this->input('email', '')),
                (int) ($this->input('is_active') ? 1 : 0),
                max(0.0, (float) str_replace(',', '.', (string) $this->input('hourly_bonus_pct', 0))),
                max(0.0, (float) str_replace(',', '.', (string) $this->input('hourly_bonus_rub', 0))),
                $hireDate, $fireDate,
                $id,
            ]
        );

        $newPass = $this->input('password');
        if ($newPass) {
            // Сброшенный админом пароль — временный: потребовать смену при следующем входе.
            Database::run('UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?',
                [password_hash($newPass, PASSWORD_DEFAULT), $id]);
        }
        flash('Данные сотрудника обновлены.');
        $this->redirect('/admin/employees/' . (int) $id);
    }

    public function deleteEmployee(string $id): void
    {
        Auth::requireRole('admin', 'hr_manager', 'hr');
        Auth::verifyCsrf();
        if ((int) $id === (int) Auth::id()) {
            flash('Нельзя удалить самого себя.', 'error');
            $this->redirect('/admin/employees');
        }
        // Есть ли связанные досье?
        $hasData = Database::scalar('SELECT 1 FROM assignment_items WHERE assigned_to = ?', [$id]);
        if ($hasData) {
            // Мягкое отключение, чтобы не терять историю.
            Database::run('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);
            flash('У сотрудника есть досье — он деактивирован (история сохранена).');
        } else {
            Database::run('DELETE FROM users WHERE id = ?', [$id]);
            flash('Сотрудник удалён.');
        }
        $this->redirect('/admin/employees');
    }

    /** Надбавка к окладу — устанавливает только бухгалтерия (и админ). */
    public function setAllowance(string $id): void
    {
        Auth::requireRole('admin', 'accountant');
        Auth::verifyCsrf();
        $allow = (float) str_replace([' ', ','], ['', '.'], (string) $this->input('allowance', 0));
        if ($allow < 0) { $allow = 0; }
        Database::run('UPDATE users SET allowance = ? WHERE id = ?', [$allow, $id]);
        flash('Надбавка обновлена.');
        $this->redirect('/admin/employees/' . (int) $id);
    }

    /** Назначить надбавку (стимул) на период → ежемесячные служебки-проекты. */
    public function allowanceGrant(string $id): void
    {
        Auth::requireRole('admin', 'accountant');
        Auth::verifyCsrf();
        $res = \App\Services\AllowanceService::grant([
            'user_id' => (int) $id,
            'amount' => $this->input('amount', 0),
            'period_from' => (string) $this->input('period_from'),
            'period_to' => (string) $this->input('period_to'),
            'grounds_ids' => $_POST['grounds'] ?? [],
            'source_id' => $this->input('source_id'),
            'purpose' => (string) $this->input('purpose', 'other'),
            'assigned_by' => (int) Auth::id(),
        ]);
        flash($res['message'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/admin/employees/' . (int) $id);
    }

    /** Отменить назначение надбавки (удалить неутверждённые проекты). */
    public function cancelAllowanceGrant(string $id): void
    {
        Auth::requireRole('admin', 'accountant');
        Auth::verifyCsrf();
        $g = Database::one('SELECT user_id FROM allowance_grants WHERE id=?', [(int) $id]);
        $res = \App\Services\AllowanceService::cancel((int) $id);
        flash($res['message'], $res['ok'] ? 'success' : 'error');
        $this->redirect('/admin/employees/' . (int) ($g['user_id'] ?? 0));
    }

    // ---------- Справочник стран ----------
    public function countries(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        $list = Database::all(
            'SELECT c.*, pg.price FROM countries c
             LEFT JOIN price_groups pg ON pg.group_no = c.group_no
             ORDER BY c.code'
        );
        $groups = Database::all('SELECT * FROM price_groups ORDER BY group_no');
        $this->view('admin/countries', ['title' => 'Справочник стран', 'countries' => $list, 'groups' => $groups]);
    }

    public function storeCountry(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        $code = strtoupper(trim((string) $this->input('code')));
        $name = $this->input('name');
        $group = (int) $this->input('group_no', 1);
        if (!$code || !$name) {
            flash('Укажите код и название страны.', 'error');
            $this->redirect('/admin/countries');
        }
        $exists = Database::scalar('SELECT 1 FROM countries WHERE code = ?', [$code]);
        if ($exists) {
            Database::run('UPDATE countries SET name=?, group_no=? WHERE code=?', [$name, $group, $code]);
            flash("Страна {$code} обновлена.");
        } else {
            Database::insert('INSERT INTO countries (code, name, group_no) VALUES (?,?,?)', [$code, $name, $group]);
            flash("Страна {$code} добавлена.");
        }
        $this->redirect('/admin/countries');
    }

    public function deleteCountry(string $code): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        Database::run('DELETE FROM countries WHERE code = ?', [strtoupper($code)]);
        flash('Страна удалена из справочника.');
        $this->redirect('/admin/countries');
    }

    /** Перенести выбранные страны в другую группу (тариф). */
    public function moveCountries(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        $codes = array_values(array_filter(array_map('strval', $_POST['codes'] ?? [])));
        $group = (int) $this->input('group');
        if (!$codes || !in_array($group, [1, 2, 3], true)) {
            flash('Выберите страны и целевую группу.', 'error');
            $this->redirect('/admin/countries');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE countries SET group_no = ? WHERE code = ?');
        foreach ($codes as $c) { $stmt->execute([$group, strtoupper($c)]); }
        $pdo->commit();
        flash('Перенесено стран: ' . count($codes) . '.');
        $this->redirect('/admin/countries');
    }

    // ---------- Ценники по группам ----------
    public function pricing(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        $groups = Database::all('SELECT * FROM price_groups ORDER BY group_no');
        $this->view('admin/pricing', ['title' => 'Тарифы по группам', 'groups' => $groups]);
    }

    public function savePricing(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        $prices = $_POST['price'] ?? [];
        $titles = $_POST['title'] ?? [];
        foreach ($prices as $groupNo => $price) {
            $groupNo = (int) $groupNo;
            $title = $titles[$groupNo] ?? '';
            $exists = Database::scalar('SELECT 1 FROM price_groups WHERE group_no = ?', [$groupNo]);
            if ($exists) {
                Database::run('UPDATE price_groups SET price=?, title=? WHERE group_no=?',
                    [(float) $price, $title, $groupNo]);
            } else {
                Database::insert('INSERT INTO price_groups (group_no, title, price) VALUES (?,?,?)',
                    [$groupNo, $title, (float) $price]);
            }
        }
        flash('Тарифы сохранены.');
        $this->redirect('/admin/pricing');
    }

    // ---------- Типы ошибок ----------
    public function errorTypes(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        $list = Database::all('SELECT * FROM error_types ORDER BY is_active DESC, name');
        $this->view('admin/errors', ['title' => 'Типы ошибок', 'errors' => $list]);
    }

    public function storeErrorType(): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        $id = $this->input('id');
        $name = $this->input('name');
        $penalty = (float) $this->input('penalty', 0);
        $active = (int) ($this->input('is_active') ? 1 : 0);
        if (!$name) {
            flash('Укажите название типа ошибки.', 'error');
            $this->redirect('/admin/errors');
        }
        if ($id) {
            Database::run('UPDATE error_types SET name=?, penalty=?, is_active=? WHERE id=?',
                [$name, $penalty, $active, $id]);
            flash('Тип ошибки обновлён.');
        } else {
            Database::insert('INSERT INTO error_types (name, penalty, is_active) VALUES (?,?,?)',
                [$name, $penalty, $active]);
            flash('Тип ошибки добавлен.');
        }
        $this->redirect('/admin/errors');
    }

    public function deleteErrorType(string $id): void
    {
        Auth::requireRole('admin', 'anketa_manager');
        Auth::verifyCsrf();
        // Если уже использовался — деактивируем, чтобы не ломать историю.
        $used = Database::scalar('SELECT 1 FROM inspections WHERE error_type_id = ?', [$id]);
        if ($used) {
            Database::run('UPDATE error_types SET is_active = 0 WHERE id = ?', [$id]);
            flash('Тип ошибки использовался — он деактивирован.');
        } else {
            Database::run('DELETE FROM error_types WHERE id = ?', [$id]);
            flash('Тип ошибки удалён.');
        }
        $this->redirect('/admin/errors');
    }

    // (Табель отработанных дней удалён — норма/отработано теперь из электронного табеля через TabelController::syncMonth)

    // ---------- Настройки расчёта ----------
    public function settings(): void
    {
        Auth::requireRole('admin');
        $this->view('admin/settings', [
            'title' => 'Настройки',
            'inspectionPercent'    => Settings::inspectionPercent(),
            'penaltyStep'          => Settings::penaltyStep(),
            'penaltyMaxMultiplier' => Settings::penaltyMaxMultiplier(),
            'dailyNorm'            => Settings::dailyNorm(),
            'nightPct'             => Settings::nightPct(),
            'holidayMult'          => Settings::holidayMult(),
            'overtimeMult'         => Settings::overtimeMult(),
            'nightStart'           => Settings::nightStart(),
            'nightEnd'             => Settings::nightEnd(),
            'or' => [
                'key_set' => Secrets::isSet('openrouter_key'),
                'model'   => (string) Settings::get('openrouter_model'),
                'prompt'  => (string) Settings::get('visa_prompt'),
            ],
            'smtp' => [
                'enabled' => Settings::get('smtp_enabled') === '1',
                'host'    => (string) Settings::get('smtp_host'),
                'port'    => (string) (Settings::get('smtp_port') ?: '465'),
                'secure'  => (string) (Settings::get('smtp_secure') ?: 'ssl'),
                'user'    => (string) Settings::get('smtp_user'),
                'from'    => (string) Settings::get('smtp_from'),
            ],
            'visaSignerName'     => (string) Settings::get('visa_signer_name', ''),
            'visaSignerPosition' => (string) Settings::get('visa_signer_position', \App\Services\VisaDocs::DEFAULT_POSITION),
            'stimulDirectorName'     => (string) Settings::get('stimul_director_name', ''),
            'stimulDirectorPosition' => (string) Settings::get('stimul_director_position', ''),
            'calendar' => [
                'curYear'   => (int) date('Y'),
                'nextYear'  => (int) date('Y') + 1,
                'curFetched'  => \App\Services\ProductionCalendar::fetchedAt((int) date('Y')),
                'nextFetched' => \App\Services\ProductionCalendar::fetchedAt((int) date('Y') + 1),
                'curMonthWd'  => \App\Services\ProductionCalendar::workingDaysInMonth((int) date('Y'), (int) date('n')),
            ],
        ]);
    }

    /** Обновить производственный календарь РФ (isdayoff.ru) на текущий и следующий год. */
    public function refreshCalendar(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        $done = [];
        foreach ([(int) date('Y'), (int) date('Y') + 1] as $yr) {
            $done[] = $yr . ': ' . (\App\Services\ProductionCalendar::fetch($yr) ? 'обновлён' : 'источник недоступен');
        }
        flash('Производственный календарь — ' . implode('; ', $done) . '.');
        $this->redirect('/admin/settings');
    }

    public function saveSettings(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        Settings::set('inspection_percent', (float) $this->input('inspection_percent', 8));
        Settings::set('penalty_step', (float) $this->input('penalty_step', 0.5));
        Settings::set('penalty_max_multiplier', (float) $this->input('penalty_max_multiplier', 2.0));
        Settings::set('daily_norm', (float) $this->input('daily_norm', 60));
        // Почасовая оплата (колл-центр 2/2) — только если отправлена форма с этими полями
        if ($this->input('night_pct') !== null) { Settings::set('night_pct', (float) $this->input('night_pct', 20)); }
        if ($this->input('holiday_mult') !== null) { Settings::set('holiday_mult', (float) $this->input('holiday_mult', 2)); }
        if ($this->input('overtime_mult') !== null) { Settings::set('overtime_mult', (float) $this->input('overtime_mult', 1.5)); }
        // Ночное окно (ТК ст.96) — для авторазбивки смен на дневные/ночные часы
        if ($this->input('night_start') !== null) { Settings::set('night_start', preg_match('/^\d{1,2}:\d{2}$/', (string) $this->input('night_start')) ? (string) $this->input('night_start') : '22:00'); }
        if ($this->input('night_end') !== null) { Settings::set('night_end', preg_match('/^\d{1,2}:\d{2}$/', (string) $this->input('night_end')) ? (string) $this->input('night_end') : '06:00'); }
        // OpenRouter (ИИ для виз)
        if ($this->input('openrouter_key') !== '' && $this->input('openrouter_key') !== null) {
            Secrets::set('openrouter_key', trim((string) $this->input('openrouter_key')));
        }
        if ($this->input('openrouter_model') !== null) {
            Settings::set('openrouter_model', trim((string) $this->input('openrouter_model')));
        }
        if ($this->input('visa_prompt') !== null && trim((string) $this->input('visa_prompt')) !== '') {
            Settings::set('visa_prompt', (string) $this->input('visa_prompt'));
        }
        // Директор-подписант служебок о стимуле — только если отправлена его форма
        if (array_key_exists('stimul_director_name', $_POST)) {
            Settings::set('stimul_director_name', trim((string) $this->input('stimul_director_name')));
            Settings::set('stimul_director_position', trim((string) $this->input('stimul_director_position')));
        }
        // Подписант описей/ГП — только если отправлена его форма (есть поле visa_signer_position)
        if (array_key_exists('visa_signer_position', $_POST)) {
            Settings::set('visa_signer_name', trim((string) $this->input('visa_signer_name')));
            $pos = trim((string) $this->input('visa_signer_position'));
            Settings::set('visa_signer_position', $pos !== '' ? $pos : \App\Services\VisaDocs::DEFAULT_POSITION);
        }
        // SMTP — обновляем только если форма SMTP действительно отправлена (есть поле smtp_host)
        if (array_key_exists('smtp_host', $_POST)) {
            Settings::set('smtp_enabled', $this->input('smtp_enabled') ? '1' : '0');
            Settings::set('smtp_host', trim((string) $this->input('smtp_host')));
            Settings::set('smtp_port', (int) ($this->input('smtp_port') ?: 465));
            Settings::set('smtp_secure', in_array($this->input('smtp_secure'), ['ssl','tls','none'], true) ? $this->input('smtp_secure') : 'ssl');
            Settings::set('smtp_user', trim((string) $this->input('smtp_user')));
            if ($this->input('smtp_pass') !== '') { Secrets::set('smtp_pass', (string) $this->input('smtp_pass')); }
            Settings::set('smtp_from', trim((string) $this->input('smtp_from')));
        }
        flash('Настройки сохранены.');
        $this->redirect('/admin/settings');
    }

    // ---------- Основания стимула (раздел 4) ----------
    public function grounds(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        $this->view('admin/grounds', [
            'title' => 'Основания стимула',
            'grounds' => Database::all('SELECT * FROM stimulus_grounds ORDER BY category, text'),
            'cap' => (float) Settings::get('stimul_max_percent', 0),
        ]);
    }

    public function storeGround(): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        if ($this->input('cap') !== null) {
            Settings::set('stimul_max_percent', (float) $this->input('cap'));
        }
        $text = trim((string) $this->input('text'));
        if ($text !== '') {
            $cat = trim((string) $this->input('category')) ?: 'Общие';
            $pct = (float) str_replace(',', '.', (string) $this->input('percent'));
            $id = $this->input('id');
            if ($id) {
                Database::run('UPDATE stimulus_grounds SET text=?, category=?, percent=?, is_active=? WHERE id=?',
                    [$text, $cat, $pct, (int)($this->input('is_active') ? 1 : 0), (int)$id]);
            } else {
                Database::insert('INSERT INTO stimulus_grounds (text, category, percent, is_active) VALUES (?,?,?,1)', [$text, $cat, $pct]);
            }
            flash('Основание сохранено.');
        } else {
            flash('Настройки оснований сохранены.');
        }
        $this->redirect('/admin/grounds');
    }

    public function deleteGround(string $id): void
    {
        Auth::requireRole('admin', 'finance_manager');
        Auth::verifyCsrf();
        Database::run('UPDATE stimulus_grounds SET is_active=0 WHERE id=?', [$id]);
        flash('Основание отключено.');
        $this->redirect('/admin/grounds');
    }
}
