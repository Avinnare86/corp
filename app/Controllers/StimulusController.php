<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\Settings;
use App\Services\Org;

/**
 * Служебная записка об установлении стимулирующих выплат.
 * Маршрут ЭП: начальник отдела → курирующий зам → директор → (бухгалтерия).
 * Бухгалтерия видит служебку уже после подписи зама (до утверждения директором).
 *
 * Выплата: ежемесячная (пропорц. отработке в расчёте) или единовременная (полной суммой).
 * % = сумма / (номинальный оклад × объём ставки) × 100 — без учёта отработки.
 * Основания (раздел 4) выбираются из каталога; одно основание не может повторяться
 * по одному сотруднику в одном периоде в разных служебках.
 */
class StimulusController extends Controller
{
    public const STATUS = [
        'draft'         => 'Черновик',
        'head_signed'   => 'Подписана начальником',
        'deputy_signed' => 'Утверждена замом',
        'approved'      => 'Утверждена директором',
        'rejected'      => 'Отклонена',
        'revision'      => 'На доработке',
    ];

    /** Категории показателей (Положение): Прил.№1 — для рядовых служебок, Прил.№2 — для директора. */
    public const CATS_STAFF = ['Интенсивность и высокие результаты', 'Качество выполняемых работ'];
    public const CAT_MGMT   = 'Руководители (замы, гл. бухгалтер)';

    /** Сколько служебок ждут действия пользователя (для бейджа меню). */
    public static function inboxCount(int $uid): int
    {
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$u) { return 0; }
        $roles = Auth::roles($uid);
        $n = 0;
        // начальник: свои черновики/на доработке
        if (!empty($roles['dept_head']) || !empty($roles['admin'])) {
            $n += (int) Database::scalar("SELECT COUNT(*) FROM stimulus_memos WHERE author_id=? AND status IN ('draft','revision')", [$uid]);
        }
        // курирующий зам: ждут его подписи (head_signed) по его отделам
        if (!empty($roles['deputy_director']) || !empty($roles['admin'])) {
            $n += (int) Database::scalar(
                "SELECT COUNT(*) FROM stimulus_memos m WHERE m.status='head_signed'
                   AND (m.department_id IN (SELECT id FROM departments WHERE curator_id=?) OR ?=1)",
                [$uid, !empty($roles['admin']) ? 1 : 0]);
        }
        // директор: ждут утверждения (deputy_signed) + свои черновики по замам/гл. бухгалтеру (mgmt)
        if (!empty($roles['director']) || !empty($roles['admin'])) {
            $n += (int) Database::scalar("SELECT COUNT(*) FROM stimulus_memos WHERE status='deputy_signed'");
            $n += (int) Database::scalar("SELECT COUNT(*) FROM stimulus_memos WHERE kind='mgmt' AND author_id=? AND status IN ('draft','revision')", [$uid]);
        }
        return $n;
    }

    public function index(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $uid = (int) Auth::id();
        $roles = Auth::roles();

        $mine = Database::all(
            "SELECT m.*, d.name AS dept_name FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
              WHERE m.author_id=? ORDER BY m.id DESC", [$uid]);

        // очередь на действие
        $todo = [];
        if (!empty($roles['deputy_director']) || !empty($roles['admin'])) {
            $todo = array_merge($todo, Database::all(
                "SELECT m.*, d.name AS dept_name, u.full_name AS author_name FROM stimulus_memos m
                   LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id
                  WHERE m.status='head_signed' AND (? = 1 OR d.curator_id = ?) ORDER BY m.id DESC",
                [!empty($roles['admin']) ? 1 : 0, $uid]));
        }
        if (!empty($roles['director']) || !empty($roles['admin'])) {
            $todo = array_merge($todo, Database::all(
                "SELECT m.*, d.name AS dept_name, u.full_name AS author_name FROM stimulus_memos m
                   LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id
                  WHERE m.status='deputy_signed' ORDER BY m.id DESC"));
        }

        // бухгалтерия видит подписанные (после зама) и утверждённые
        $accountant = [];
        if (!empty($roles['accountant']) || !empty($roles['admin'])) {
            $accountant = Database::all(
                "SELECT m.*, d.name AS dept_name, u.full_name AS author_name FROM stimulus_memos m
                   LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id
                  WHERE m.status IN ('deputy_signed','approved') ORDER BY m.id DESC");
        }

        $this->view('stimulus/index', [
            'title' => 'Служебки о стимуле',
            'mine' => $mine, 'todo' => $todo, 'accountant' => $accountant,
            'canCreate' => !empty($roles['dept_head']) || !empty($roles['admin']),
            'canCreateMgmt' => !empty($roles['director']) || !empty($roles['admin']),
        ]);
    }

    public function create(): void
    {
        Auth::requireRole('dept_head', 'admin');
        $this->memoForm(null, (int) Auth::id(), 'staff');
    }

    /** Отдельная служебка директора: стимул заместителям и главному бухгалтеру (Прил.№2). */
    public function createMgmt(): void
    {
        Auth::requireRole('director', 'admin');
        $this->memoForm(null, (int) Auth::id(), 'mgmt');
    }

    /** Прямое назначение стимула вышестоящим (без участия начальников ниже): директор/зам. */
    public function createDirect(): void
    {
        Auth::requireRole('director', 'deputy_director', 'admin');
        $tier = Org::tier((int) Auth::id());
        if (!in_array($tier, ['director', 'deputy'], true)) {
            flash('Прямое назначение доступно директору или курирующему заместителю.', 'error');
            $this->redirect('/memos');
        }
        $this->memoForm(null, (int) Auth::id(), 'staff', $tier);
    }

    public function edit(string $id): void
    {
        Auth::requireRole('dept_head', 'director', 'admin');
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id = ?', [$id]);
        if (!$memo || ((int)$memo['author_id'] !== (int)Auth::id() && !Auth::isAdmin())) { $this->redirect('/memos'); }
        if (!in_array($memo['status'], ['draft', 'revision'], true)) { flash('Редактировать можно только черновик/на доработке.', 'error'); $this->redirect('/memos/' . (int)$id); }
        $this->memoForm($memo, (int)$memo['author_id'], $memo['kind'] ?? 'staff');
    }

    private function memoForm(?array $memo, int $authorId, string $kind = 'staff', ?string $direct = null): void
    {
        $kind = ($memo['kind'] ?? $kind) === 'mgmt' ? 'mgmt' : $kind;
        $direct = $memo['direct_tier'] ?? $direct;   // при редактировании берём из служебки
        $period = (string) ($memo['period'] ?? date('Y-m'));
        $isMgmt = $kind === 'mgmt';
        $deptOpts = null;

        if ($isMgmt) {
            // Стимул замам и гл. бухгалтеру: пул — заместители директора и бухгалтерия.
            $members = Database::all(
                "SELECT DISTINCT u.id, u.full_name, u.position, u.oklad, u.rate_volume, u.position_id
                   FROM users u JOIN user_roles r ON r.user_id = u.id
                  WHERE u.is_active = 1 AND r.role_slug IN ('deputy_director','accountant')
                  ORDER BY u.full_name");
            $deptId = 0;
            $cats = [self::CAT_MGMT];
            $forecast = null;
        } elseif ($direct) {
            // Прямое назначение вышестоящим: выбор подчинённого отдела (директору — все, заму — курируемые).
            $deptOpts = $direct === 'director'
                ? Database::all('SELECT id, name FROM departments ORDER BY name')
                : self::deptsByIds(Org::curatedDeptIds($authorId));
            $deptId = (int) ($memo['department_id'] ?? ($this->input('dept') ?: 0));
            if (!$deptId && $deptOpts) { $deptId = (int) $deptOpts[0]['id']; }
            $members = $deptId ? Database::all(
                'SELECT id, full_name, position, oklad, rate_volume, position_id FROM users WHERE department_id = ? AND is_active = 1 ORDER BY full_name',
                [$deptId]) : [];
            $cats = self::CATS_STAFF;
            $forecast = $deptId ? \App\Services\StimulusBudgetService::forecast((int) $deptId, $period) : null;
        } else {
            // отдел автора (начальник ведёт служебку по своему отделу)
            $deptId = $memo['department_id'] ?? (int) Database::scalar('SELECT department_id FROM users WHERE id = ?', [$authorId]);
            $headDept = Database::scalar('SELECT id FROM departments WHERE head_id = ?', [$authorId]);
            if ($headDept) { $deptId = (int) $headDept; }
            $members = Database::all(
                'SELECT id, full_name, position, oklad, rate_volume, position_id FROM users WHERE department_id = ? AND is_active = 1 ORDER BY full_name',
                [$deptId]);
            $cats = self::CATS_STAFF;
            $forecast = \App\Services\StimulusBudgetService::forecast((int) $deptId, $period);
        }

        foreach ($members as &$m) {
            $okl = (float) ($m['oklad'] ?? 0);
            if ($m['position_id']) { $po = Database::scalar('SELECT oklad FROM positions WHERE id=?', [$m['position_id']]); if ($po !== false) { $okl = (float) $po; } }
            $m['oklad_load'] = round($okl * (float) ($m['rate_volume'] ?? 1), 2);
            // Сделка к 25-му (перенос из квоты/виз) — только для рядовых служебок.
            if ($isMgmt) {
                $m['kvota'] = 0; $m['visy'] = 0; $m['piece'] = 0;
            } else {
                $pk = \App\Services\PayrollService::pieceByKind((int) $m['id'], $period, 1, 25);
                $m['kvota'] = $pk['anketa']; $m['visy'] = $pk['ops']; $m['piece'] = $pk['total'];
            }
        }
        unset($m);

        $ph = implode(',', array_fill(0, count($cats), '?'));
        $lines = $memo ? Database::all('SELECT * FROM stimulus_memo_lines WHERE memo_id = ? ORDER BY id', [$memo['id']]) : [];
        $this->view('stimulus/form', [
            'title' => $memo ? 'Служебка №' . ($memo['number'] ?: $memo['id'])
                : ($isMgmt ? 'Стимул заместителям / гл. бухгалтеру' : 'Новая служебка о стимуле'),
            'memo' => $memo,
            'kind' => $kind,
            'direct' => $direct,
            'deptOpts' => $deptOpts,
            'deptId' => $isMgmt ? 0 : (int) $deptId,
            'showPiece' => !$isMgmt,
            'dept' => $isMgmt ? null : Database::one('SELECT * FROM departments WHERE id = ?', [$deptId]),
            'members' => $members,
            'lines' => $lines,
            'grounds' => Database::all("SELECT * FROM stimulus_grounds WHERE is_active = 1 AND category IN ($ph) ORDER BY category, percent DESC, text", $cats),
            'sources' => Database::all('SELECT * FROM pay_sources ORDER BY id'),
            'selGrounds' => $memo ? array_filter(array_map('intval', explode(',', (string)$memo['grounds_ids']))) : [],
            'forecast' => $forecast,
            'csrf' => Auth::csrf(),
        ]);
    }

    public function store(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $id = (int) $this->input('id');

        $kind = $this->input('kind') === 'mgmt' ? 'mgmt' : 'staff';
        if ($kind === 'mgmt' && !Auth::has('director') && !Auth::isAdmin()) { flash('Служебку замам/гл. бухгалтеру оформляет директор.', 'error'); $this->redirect('/memos'); }
        $period   = (string) ($this->input('period') ?: date('Y-m'));
        $payKind  = $this->input('pay_kind') === 'onetime' ? 'onetime' : 'monthly';
        $deptId   = $kind === 'mgmt' ? null : ((int) $this->input('department_id') ?: null);
        $sourceId = $this->input('source_id') ? (int) $this->input('source_id') : null;
        $groundIds = array_values(array_filter(array_map('intval', $_POST['grounds'] ?? [])));

        // Прямое назначение вышестоящим (без участия начальников ниже): авто-маршрут по тиру.
        $direct = in_array($this->input('direct_tier'), ['director', 'deputy'], true) && $kind === 'staff'
            ? (string) $this->input('direct_tier') : null;
        if ($direct) {
            $tier = Org::tier($uid);
            $isDir = $tier === 'director' || Auth::isAdmin();
            if (!$isDir && $tier !== 'deputy') { flash('Прямое назначение доступно директору или курирующему заму.', 'error'); $this->redirect('/memos'); }
            if (!$deptId) { flash('Выберите подразделение для прямого назначения.', 'error'); $this->redirect('/memos'); }
            if (!$isDir && !Org::isSuperiorOfDept($uid, $deptId)) { flash('Назначать напрямую можно только в курируемые вами подразделения.', 'error'); $this->redirect('/memos'); }
            $direct = $isDir ? 'director' : 'deputy';
        }

        if (!$groundIds) { flash('Выберите хотя бы одно основание (раздел 4).', 'error'); $this->backToForm($id, $kind); }
        $groundTexts = [];
        foreach ($groundIds as $gid) {
            $t = Database::scalar('SELECT text FROM stimulus_grounds WHERE id = ?', [$gid]);
            if ($t !== false) { $groundTexts[] = $t; }
        }

        // строки: row[idx][user_id|amount|pay_kind]
        $rowsIn = $_POST['row'] ?? [];
        $lines = [];
        foreach ($rowsIn as $r) {
            $eid = (int) ($r['user_id'] ?? 0);
            $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($r['amount'] ?? 0));
            if (!$eid || $amount <= 0) { continue; }
            $u = Database::one('SELECT u.*, p.oklad AS p_oklad FROM users u LEFT JOIN positions p ON p.id=u.position_id WHERE u.id=?', [$eid]);
            if (!$u) { continue; }
            $okl = $u['p_oklad'] !== null ? (float)$u['p_oklad'] : (float)($u['oklad'] ?? 0);
            $load = round($okl * (float)($u['rate_volume'] ?? 1), 2);
            $pct = $load > 0 ? round($amount / $load * 100, 1) : 0;
            $lkind = ($r['pay_kind'] ?? $payKind) === 'onetime' ? 'onetime' : 'monthly';
            $lines[] = ['user_id' => $eid, 'amount' => round($amount, 2), 'pay_kind' => $lkind, 'oklad_load' => $load, 'percent' => $pct];
        }
        if (!$lines) { flash('Добавьте хотя бы одного работника с суммой.', 'error'); $this->backToForm($id, $kind); }

        // Суммарный максимум % выбранных оснований («до X%» по каждому показателю Положения)
        // должен покрывать запрошенный % по каждому сотруднику.
        $groundsCap = (float) Database::scalar(
            'SELECT COALESCE(SUM(percent),0) FROM stimulus_grounds WHERE id IN (' . implode(',', array_fill(0, count($groundIds), '?')) . ')',
            $groundIds);
        $pf = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
        $overCap = [];
        foreach ($lines as $ln) {
            if ($ln['percent'] > $groundsCap + 0.01) {
                $overCap[] = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$ln['user_id']]) . ' — запрошено ' . $pf($ln['percent']) . '%';
            }
        }
        if ($overCap) {
            flash('Выбранных оснований недостаточно: их суммарный максимум ' . $pf($groundsCap) . '% от оклада не покрывает запрошенный процент по: ' . implode('; ', $overCap) . '. Добавьте основания (с бо́льшим нормативным %) или уменьшите сумму.', 'error');
            $this->backToForm($id, $kind);
        }

        // Проверка дублей оснований по сотруднику в этом периоде (в других служебках, не отклонённых).
        $conflicts = [];
        foreach ($lines as $ln) {
            $others = Database::all(
                "SELECT m.id, m.number, m.grounds_ids FROM stimulus_memos m
                   JOIN stimulus_memo_lines l ON l.memo_id=m.id
                  WHERE l.user_id=? AND m.period=? AND m.status NOT IN ('rejected') AND m.id<>?",
                [$ln['user_id'], $period, $id]);
            foreach ($others as $o) {
                $used = array_filter(array_map('intval', explode(',', (string)$o['grounds_ids'])));
                $dup = array_intersect($groundIds, $used);
                if ($dup) {
                    $name = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$ln['user_id']]);
                    $conflicts[] = $name . ' (служебка №' . ($o['number'] ?: $o['id']) . ')';
                }
            }
        }
        if ($conflicts) {
            flash('Основания уже использованы по этим работникам в этом периоде — нужны ДРУГИЕ основания: ' . implode('; ', array_unique($conflicts)), 'error');
            $this->backToForm($id, $kind);
        }

        // Проверка % (если задан потолок в настройках).
        $cap = (float) Settings::get('stimul_max_percent', 0);
        if ($cap > 0) {
            $over = [];
            foreach ($lines as $ln) {
                if ($ln['percent'] > $cap) {
                    $over[] = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$ln['user_id']]) . ' — ' . $ln['percent'] . '%';
                }
            }
            if ($over) {
                flash("Превышен потолок {$cap}% по: " . implode('; ', $over) . '. Разнесите сумму на несколько служебок с разными основаниями.', 'error');
                $this->backToForm($id, $kind);
            }
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        if ($id) {
            Database::run('UPDATE stimulus_memos SET department_id=?, period=?, pay_kind=?, source_id=?, grounds=?, grounds_ids=?, kind=?, direct_tier=?, status=? WHERE id=?',
                [$deptId, $period, $payKind, $sourceId, implode('; ', $groundTexts), implode(',', $groundIds), $kind, $direct, 'draft', $id]);
            Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
        } else {
            $id = Database::insert('INSERT INTO stimulus_memos (department_id, author_id, period, pay_kind, source_id, grounds, grounds_ids, kind, direct_tier, status) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$deptId, $uid, $period, $payKind, $sourceId, implode('; ', $groundTexts), implode(',', $groundIds), $kind, $direct, 'draft']);
        }
        $pfrom = $period . '-01';
        $pto = date('Y-m-t', strtotime($pfrom));
        foreach ($lines as $ln) {
            Database::insert('INSERT INTO stimulus_memo_lines (memo_id, user_id, amount, pay_kind, period_from, period_to, oklad_load, percent) VALUES (?,?,?,?,?,?,?,?)',
                [$id, $ln['user_id'], $ln['amount'], $ln['pay_kind'], $pfrom, $pto, $ln['oklad_load'], $ln['percent']]);
        }
        $pdo->commit();
        flash($kind === 'mgmt'
            ? 'Черновик сохранён. Подпишите его, чтобы утвердить стимул приказом директора.'
            : 'Служебка сохранена как черновик. Подпишите её, чтобы отправить курирующему заму.');
        $this->redirect('/memos/' . $id);
    }

    private function backToForm(int $id, string $kind = 'staff'): void
    {
        if ($id) { $this->redirect('/memos/' . $id . '/edit'); }
        $this->redirect($kind === 'mgmt' ? '/memos/mgmt/new' : '/memos/new');
    }

    /** Список отделов по id (для селектора прямого назначения). */
    private static function deptsByIds(array $ids): array
    {
        if (!$ids) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::all("SELECT id, name FROM departments WHERE id IN ($ph) ORDER BY name", $ids);
    }

    public function show(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $memo = Database::one(
            'SELECT m.*, d.name AS dept_name, d.curator_id, a.full_name AS author_name
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
               JOIN users a ON a.id=m.author_id WHERE m.id=?', [$id]);
        if (!$memo) { flash('Служебка не найдена.', 'error'); $this->redirect('/memos'); }
        $uid = (int) Auth::id();
        $roles = Auth::roles();
        // видимость: автор / куратор / директор / бухгалтер(после зама) / админ
        $isAuthor = (int)$memo['author_id'] === $uid;
        $isCurator = !empty($roles['deputy_director']) && ((int)$memo['curator_id'] === $uid || Auth::isAdmin());
        $isDirector = !empty($roles['director']) || Auth::isAdmin();
        $isAcc = (!empty($roles['accountant']) || Auth::isAdmin()) && in_array($memo['status'], ['deputy_signed','approved'], true);
        // вышестоящий по структуре видит служебки подчинённых подразделений
        $isSuperior = Org::isSuperiorOfDept($uid, $memo['department_id'] ? (int)$memo['department_id'] : null);
        if (!($isAuthor || $isCurator || $isDirector || $isAcc || $isSuperior || Auth::isAdmin())) {
            flash('Нет доступа к этой служебке.', 'error'); $this->redirect('/memos');
        }
        $lines = Database::all(
            'SELECT l.*, u.full_name, u.position FROM stimulus_memo_lines l JOIN users u ON u.id=l.user_id WHERE l.memo_id=? ORDER BY u.full_name', [$id]);
        $gids = array_values(array_filter(array_map('intval', explode(',', (string) $memo['grounds_ids']))));
        $groundRows = $gids
            ? Database::all('SELECT text, category, percent FROM stimulus_grounds WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ') ORDER BY percent DESC, category', $gids)
            : [];

        // что может сделать текущий пользователь
        $isMgmt = ($memo['kind'] ?? 'staff') === 'mgmt';
        $canMgmtSign = $isMgmt && in_array($memo['status'], ['draft','revision'], true) && $isDirector;
        $direct = $memo['direct_tier'] ?? null;
        $canHeadSign = !$isMgmt && !$direct && $isAuthor && $memo['status'] === 'draft';
        $canDeputySign = !$isMgmt && !$direct && (($memo['status'] === 'head_signed' && (!empty($roles['deputy_director']) && ((int)$memo['curator_id']===$uid))) || ($memo['status']==='head_signed' && Auth::isAdmin()));
        $canDirectorSign = !$isMgmt && !$direct && $memo['status'] === 'deputy_signed' && ($isDirector);
        // прямое назначение: своя короткая логика подписи
        $canDirectSign = false; $directLabel = '';
        if ($direct === 'director' && in_array($memo['status'], ['draft','revision'], true) && $isDirector) {
            $canDirectSign = true; $directLabel = '🖋 Назначить и утвердить (директор)';
        } elseif ($direct === 'deputy' && $memo['status'] === 'draft' && $isAuthor && (!empty($roles['deputy_director']) || Auth::isAdmin())) {
            $canDirectSign = true; $directLabel = '🖋 Подписать и направить директору';
        } elseif ($direct === 'deputy' && $memo['status'] === 'deputy_signed' && $isDirector) {
            $canDirectSign = true; $directLabel = '🖋 Утвердить (директор)';
        }
        $this->view('stimulus/show', [
            'title' => 'Служебка №' . ($memo['number'] ?: $memo['id']),
            'memo' => $memo, 'lines' => $lines, 'groundRows' => $groundRows,
            'kind' => $memo['kind'] ?? 'staff',
            'direct' => $direct,
            'total' => array_sum(array_map(fn($l)=>(float)$l['amount'], $lines)),
            'source' => $memo['source_id'] ? Database::one('SELECT * FROM pay_sources WHERE id=?', [$memo['source_id']]) : null,
            'canEdit' => $isAuthor && in_array($memo['status'], ['draft','revision'], true),
            'canHeadSign' => $canHeadSign,
            'canDeputySign' => $canDeputySign,
            'canDirectorSign' => $canDirectorSign,
            'canMgmtSign' => $canMgmtSign,
            'canDirectSign' => $canDirectSign,
            'directLabel' => $directLabel,
            'canReject' => ($canDeputySign || $canDirectorSign || ($direct === 'deputy' && $memo['status'] === 'deputy_signed' && $isDirector)),
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Данные одной служебки для печатной формы (А4). */
    private function printData(int $id): ?array
    {
        $memo = Database::one(
            'SELECT m.*, d.name AS dept_name, d.curator_id, a.full_name AS author_name, a.position AS author_position
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
               JOIN users a ON a.id=m.author_id WHERE m.id=?', [$id]);
        if (!$memo) { return null; }
        $lines = Database::all(
            'SELECT l.*, u.full_name, u.position FROM stimulus_memo_lines l JOIN users u ON u.id=l.user_id WHERE l.memo_id=? ORDER BY u.full_name', [$id]);
        $signers = [
            'head' => $memo['head_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['head_id']]) : null,
            'deputy' => $memo['deputy_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['deputy_id']]) : null,
            'director' => $memo['director_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['director_id']]) : null,
        ];
        $dirName = (string) Database::scalar("SELECT full_name FROM users WHERE id IN (SELECT user_id FROM user_roles WHERE role_slug='director') ORDER BY id LIMIT 1");
        $gids = array_values(array_filter(array_map('intval', explode(',', (string) $memo['grounds_ids']))));
        $groundRows = $gids
            ? Database::all('SELECT text, category, percent FROM stimulus_grounds WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ') ORDER BY percent DESC, category', $gids)
            : array_map(fn($t) => ['text' => $t, 'category' => '', 'percent' => 0], array_values(array_filter(array_map('trim', explode(';', (string) $memo['grounds'])))));
        return [
            'memo' => $memo, 'lines' => $lines, 'signers' => $signers,
            'kind' => $memo['kind'] ?? 'staff',
            'source' => $memo['source_id'] ? Database::one('SELECT * FROM pay_sources WHERE id=?', [$memo['source_id']]) : null,
            'directorName' => $dirName ?: 'Д.Н. Семёнов',
            'grounds' => array_map(fn($g) => $g['text'], $groundRows),
            'groundRows' => $groundRows,
        ];
    }

    /** Сформированный документ служебки (А4) со штампами ЭП — печать → PDF. */
    public function printDoc(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $d = $this->printData((int) $id);
        if (!$d) { http_response_code(404); exit('Служебка не найдена'); }
        $this->view('stimulus/print', array_merge(['title' => 'Служебка №' . ($d['memo']['number'] ?: $d['memo']['id'])], $d), false);
    }

    /** Отчёт «служебки на печать»: по отделам, в разрезе ежемес./единовр., со ссылками на PDF. */
    public function printReport(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $period = (string) $this->input('period', date('Y-m'));
        $memos = $this->reportMemos($period);
        $this->view('stimulus/print_report', [
            'title' => 'Служебки на печать',
            'memos' => $memos,
            'period' => $period,
            'periods' => array_column(Database::all('SELECT DISTINCT period FROM stimulus_memos ORDER BY period DESC'), 'period'),
            'statusLabels' => self::STATUS,
        ]);
    }

    /** Печать ВСЕХ подходящих служебок одной страницей (A4-страницы) → один PDF из браузера. */
    public function printBatch(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $period = (string) $this->input('period', date('Y-m'));
        $batch = [];
        foreach ($this->reportMemos($period) as $m) {
            $d = $this->printData((int) $m['id']);
            if ($d) { $batch[] = $d; }
        }
        $this->view('stimulus/print_batch', ['title' => 'Служебки — печать пакетом', 'batch' => $batch, 'period' => $period], false);
    }

    /** Служебки за период (approved + проекты, не отклонённые) с учётом видимости — для отчётов печати. */
    private function reportMemos(string $period): array
    {
        $where = "m.status <> 'rejected'"; $params = [];
        if ($period !== '') { $where .= ' AND m.period=?'; $params[] = $period; }
        $rows = Database::all(
            "SELECT m.id, m.number, m.period, m.status, m.pay_kind, m.department_id, m.author_id,
                    d.name AS dept_name, a.full_name AS author_name,
                    (SELECT COUNT(*) FROM stimulus_memo_lines l WHERE l.memo_id=m.id) AS people
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id JOIN users a ON a.id=m.author_id
              WHERE $where ORDER BY d.name, m.pay_kind DESC, m.id", $params);
        if ($this->seesAllStimulus()) { return $rows; }
        $uid = (int) Auth::id();
        return array_values(array_filter($rows, fn($m) =>
            Org::isSuperiorOfDept($uid, $m['department_id'] ? (int) $m['department_id'] : null) || (int) $m['author_id'] === $uid));
    }

    /** Отчёт для бухгалтерии: помесячное покрытие сотрудников стимулом (ежемес./единовр.). */
    public function coverage(): void
    {
        Auth::requireRole('accountant', 'director', 'admin');
        $period = (string) $this->input('period', date('Y-m'));
        $this->view('stimulus/coverage', [
            'title' => 'Покрытие стимулом (бухгалтерия)',
            'rows' => $this->coverageRows($period),
            'period' => $period,
            'periods' => array_column(Database::all('SELECT DISTINCT period FROM stimulus_memos ORDER BY period DESC'), 'period'),
        ]);
    }

    /** Агрегаты покрытия: по месяцу+сотруднику, утв./проект × ежемес./единовр. (эффективная сумма с override). */
    private function coverageRows(string $period): array
    {
        $where = "m.status <> 'rejected'"; $params = [];
        if ($period !== '') { $where .= ' AND m.period=?'; $params[] = $period; }
        $raw = Database::all(
            "SELECT m.period, m.status, m.department_id, d.name AS dept_name, l.user_id, l.pay_kind, l.amount, l.id AS line_id,
                    ur.full_name AS recipient,
                    (SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS ov_amount
               FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id JOIN users ur ON ur.id=l.user_id
               LEFT JOIN departments d ON d.id=m.department_id
              WHERE $where ORDER BY m.period DESC, d.name, ur.full_name", $params);
        $agg = [];
        foreach ($raw as $r) {
            $key = $r['period'] . '|' . (int) $r['user_id'];
            if (!isset($agg[$key])) {
                $agg[$key] = ['period' => $r['period'], 'dept_name' => $r['dept_name'] ?: '—', 'recipient' => $r['recipient'],
                    'm_appr' => 0.0, 'o_appr' => 0.0, 'm_proj' => 0.0, 'o_proj' => 0.0];
            }
            $eff = $r['ov_amount'] !== null ? (float) $r['ov_amount'] : (float) $r['amount'];
            $appr = $r['status'] === 'approved';
            if ($r['pay_kind'] === 'onetime') { $agg[$key][$appr ? 'o_appr' : 'o_proj'] += $eff; }
            else { $agg[$key][$appr ? 'm_appr' : 'm_proj'] += $eff; }
        }
        foreach ($agg as &$a) { $a['total'] = round($a['m_appr'] + $a['o_appr'] + $a['m_proj'] + $a['o_proj'], 2); }
        return array_values($agg);
    }

    /** Выгрузка отчёта покрытия в Excel. */
    public function coverageExport(): void
    {
        Auth::requireRole('accountant', 'director', 'admin');
        $rows = $this->coverageRows((string) $this->input('period', date('Y-m')));
        $headers = ['Месяц', 'Отдел', 'Сотрудник', 'Ежемес. (утв.)', 'Единовр. (утв.)', 'Ежемес. (проект)', 'Единовр. (проект)', 'Итого'];
        $data = array_map(fn($a) => [
            $a['period'], $a['dept_name'], $a['recipient'],
            round($a['m_appr'], 2), round($a['o_appr'], 2), round($a['m_proj'], 2), round($a['o_proj'], 2), $a['total'],
        ], $rows);
        \App\Services\Xlsx::download('stimulus-coverage-' . date('Y-m-d') . '.xlsx',
            [['name' => 'Покрытие стимулом', 'headers' => $headers, 'rows' => $data]]);
    }

    /** Подпись очередного этапа (ПЭП — подтверждение паролем). */
    public function sign(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $memo = Database::one('SELECT m.*, d.curator_id FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id WHERE m.id=?', [$id]);
        if (!$memo) { $this->redirect('/memos'); }
        $uid = (int) Auth::id();
        $roles = Auth::roles();

        // подтверждение пароля (ПЭП)
        $pass = (string) $this->input('password');
        $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id=?', [$uid]);
        if (!password_verify($pass, $hash)) { flash('Неверный пароль — подпись отклонена.', 'error'); $this->redirect('/memos/' . (int)$id); }
        $now = date('Y-m-d H:i:s');
        $sig = substr(hash_hmac('sha256', $id . '|' . $uid . '|' . $now, (string) Settings::get('sign_secret', 'uchet')), 0, 24);

        // Служебка директора (замам/гл. бухгалтеру) — одна подпись директора → сразу утверждена.
        if (($memo['kind'] ?? 'staff') === 'mgmt') {
            if (in_array($memo['status'], ['draft','revision'], true) && (!empty($roles['director']) || Auth::isAdmin())) {
                $num = $memo['number'] ?: self::nextNumber();
                Database::run('UPDATE stimulus_memos SET status=?, number=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=? WHERE id=?',
                    ['approved', $num, $uid, 'PEP', $now, $sig, $id]);
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='accountant'") as $acc) {
                    NotificationService::create((int)$acc['id'], 'Стимул руководителям утверждён', "Директор утвердил стимулирующие выплаты замам/гл. бухгалтеру (№{$num}).");
                }
                flash("Стимул заместителям/гл. бухгалтеру утверждён приказом директора (№{$num}). Бухгалтерия видит документ.");
            } else {
                flash('Сейчас вы не можете подписать этот документ.', 'error');
            }
            $this->redirect('/memos/' . (int)$id);
        }

        // Прямое назначение вышестоящим — сокращённый маршрут (без начальника отдела).
        $direct = $memo['direct_tier'] ?? null;
        if ($direct === 'director') {
            if (in_array($memo['status'], ['draft', 'revision'], true) && (!empty($roles['director']) || Auth::isAdmin())) {
                $num = $memo['number'] ?: self::nextNumber();
                Database::run('UPDATE stimulus_memos SET status=?, number=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=? WHERE id=?',
                    ['approved', $num, $uid, 'PEP', $now, $sig, $id]);
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='accountant'") as $acc) {
                    NotificationService::create((int) $acc['id'], 'Стимул назначен директором', "Директор назначил стимул напрямую (№{$num}).");
                }
                flash("Стимул назначен напрямую и утверждён приказом директора (№{$num}).");
            } else { flash('Сейчас вы не можете подписать эту служебку.', 'error'); }
            $this->redirect('/memos/' . (int)$id);
        }
        if ($direct === 'deputy') {
            if ($memo['status'] === 'draft' && (int)$memo['author_id'] === $uid && (!empty($roles['deputy_director']) || Auth::isAdmin())) {
                $num = $memo['number'] ?: self::nextNumber();
                Database::run('UPDATE stimulus_memos SET status=?, number=?, deputy_id=?, deputy_sign_type=?, deputy_signed_at=?, deputy_sign_hash=? WHERE id=?',
                    ['deputy_signed', $num, $uid, 'PEP', $now, $sig, $id]);
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='director'") as $dir) {
                    NotificationService::create((int) $dir['id'], 'Служебка на утверждение директором', "Зам назначил стимул напрямую (№{$num}), ожидает вашего утверждения.");
                }
                flash("Подписано заместителем напрямую (№{$num}). Ожидает утверждения директором.");
            } elseif ($memo['status'] === 'deputy_signed' && (!empty($roles['director']) || Auth::isAdmin())) {
                Database::run('UPDATE stimulus_memos SET status=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=? WHERE id=?',
                    ['approved', $uid, 'PEP', $now, $sig, $id]);
                NotificationService::create((int)$memo['author_id'], 'Служебка утверждена', "Ваша прямая служебка о стимуле №{$memo['number']} утверждена директором.");
                flash('Стимул утверждён директором.');
            } else { flash('Сейчас вы не можете подписать эту служебку.', 'error'); }
            $this->redirect('/memos/' . (int)$id);
        }

        if ($memo['status'] === 'draft' && (int)$memo['author_id'] === $uid) {
            // начальник подписал → присвоить номер, отправить заму
            $num = $memo['number'] ?: self::nextNumber();
            Database::run('UPDATE stimulus_memos SET status=?, number=?, head_id=?, head_sign_type=?, head_signed_at=?, head_sign_hash=? WHERE id=?',
                ['head_signed', $num, $uid, 'PEP', $now, $sig, $id]);
            // уведомить куратора
            if ($memo['curator_id']) {
                NotificationService::create((int)$memo['curator_id'], 'Служебка на утверждение', "Поступила служебка о стимуле №{$num} на ваше утверждение.");
            }
            flash("Служебка подписана и направлена курирующему заму (№{$num}).");
        } elseif ($memo['status'] === 'head_signed' && (!empty($roles['deputy_director']) && ((int)$memo['curator_id']===$uid) || Auth::isAdmin())) {
            Database::run('UPDATE stimulus_memos SET status=?, deputy_id=?, deputy_sign_type=?, deputy_signed_at=?, deputy_sign_hash=? WHERE id=?',
                ['deputy_signed', $uid, 'PEP', $now, $sig, $id]);
            // директору на утверждение + бухгалтерия уже видит
            foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='director'") as $dir) {
                NotificationService::create((int)$dir['id'], 'Служебка на утверждение директором', "Служебка о стимуле №{$memo['number']} утверждена замом, ожидает вашего решения.");
            }
            flash('Утверждено замом. Служебка направлена директору; бухгалтерия уже видит её.');
        } elseif ($memo['status'] === 'deputy_signed' && (!empty($roles['director']) || Auth::isAdmin())) {
            Database::run('UPDATE stimulus_memos SET status=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=? WHERE id=?',
                ['approved', $uid, 'PEP', $now, $sig, $id]);
            NotificationService::create((int)$memo['author_id'], 'Служебка утверждена', "Ваша служебка о стимуле №{$memo['number']} утверждена директором.");
            flash('Служебка утверждена директором.');
        } else {
            flash('Сейчас вы не можете подписать эту служебку.', 'error');
        }
        $this->redirect('/memos/' . (int)$id);
    }

    public function reject(string $id): void
    {
        Auth::requireRole('deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$id]);
        if (!$memo) { $this->redirect('/memos'); }
        $reason = trim((string) $this->input('reason'));
        if ($reason === '') { flash('Укажите причину отклонения.', 'error'); $this->redirect('/memos/' . (int)$id); }
        Database::run('UPDATE stimulus_memos SET status=?, reject_reason=? WHERE id=?', ['revision', $reason, $id]);
        NotificationService::create((int)$memo['author_id'], 'Служебка отклонена', "Служебка №{$memo['number']} возвращена на доработку: {$reason}");
        flash('Служебка возвращена автору на доработку.');
        $this->redirect('/memos/' . (int)$id);
    }

    public function delete(string $id): void
    {
        Auth::requireRole('dept_head', 'admin');
        Auth::verifyCsrf();
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$id]);
        if ($memo && ((int)$memo['author_id'] === (int)Auth::id() || Auth::isAdmin()) && in_array($memo['status'], ['draft','revision'], true)) {
            Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
            Database::run('DELETE FROM stimulus_memos WHERE id=?', [$id]);
            flash('Черновик служебки удалён.');
        }
        $this->redirect('/memos');
    }

    /** Видит ли пользователь весь стимул (директор/бухгалтер/админ). */
    private function seesAllStimulus(): bool
    {
        return Auth::has('director', 'accountant') || Auth::isAdmin();
    }

    /** Корректировка (снижение/отмена) утверждённого стимула вышестоящим — с аудитом. */
    public function override(string $lineId): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $line = Database::one(
            'SELECT l.*, m.status AS memo_status, m.period AS memo_period
               FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id WHERE l.id=?', [$lineId]);
        if (!$line) { flash('Строка стимула не найдена.', 'error'); $this->redirect('/memos/summary'); }
        $canBase = Auth::has('director') || Auth::isAdmin();
        if (!$canBase && !Org::canOverseeUser($uid, (int) $line['user_id'])) {
            flash('Корректировать можно только стимул подчинённых.', 'error'); $this->redirect('/memos/summary');
        }
        if ($line['memo_status'] !== 'approved') {
            flash('Снизить/отменить можно только утверждённый стимул.', 'error'); $this->redirect('/memos/summary');
        }
        $reason = trim((string) $this->input('reason'));
        if ($reason === '') { flash('Укажите причину снижения/отмены.', 'error'); $this->redirect('/memos/summary'); }
        $cancel = (string) $this->input('cancel') === '1';
        $new = $cancel ? 0.0 : (float) str_replace([' ', ','], ['', '.'], (string) $this->input('new_amount'));
        if ($new < 0) { $new = 0.0; }
        if ($new > (float) $line['amount'] + 0.005) {
            flash('Новая сумма не может превышать исходную (' . money($line['amount']) . '). Стимул можно только снизить или отменить.', 'error');
            $this->redirect('/memos/summary?period=' . urlencode((string) $line['memo_period']));
        }
        Database::insert('INSERT INTO stimulus_overrides (memo_line_id, new_amount, by_user_id, reason) VALUES (?,?,?,?)',
            [(int) $lineId, round($new, 2), $uid, $reason]);
        // уведомим получателя и автора служебки
        NotificationService::create((int) $line['user_id'], 'Стимул скорректирован',
            ($cancel ? 'Ваш стимул отменён' : 'Ваш стимул снижен до ' . money($new)) . ' за ' . $line['memo_period'] . '. Причина: ' . $reason);
        flash($cancel ? 'Стимул отменён (0). Корректировка зафиксирована.' : 'Стимул снижен до ' . money($new) . '. Корректировка зафиксирована.');
        $this->redirect('/memos/summary?period=' . urlencode((string) $line['memo_period']));
    }

    /** Строки сводной (с учётом видимости и последней корректировки). */
    private function summaryRows(int $uid, string $period): array
    {
        $where = '1=1'; $params = [];
        if ($period !== '') { $where .= ' AND m.period = ?'; $params[] = $period; }
        $rows = Database::all(
            "SELECT m.period, m.number, m.status, m.department_id, m.author_id, m.direct_tier,
                    d.name AS dept_name, l.id AS line_id, l.user_id, l.amount, l.pay_kind,
                    ur.full_name AS recipient, ah.full_name AS author_name,
                    (SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS ov_amount,
                    (SELECT ob.full_name FROM stimulus_overrides o JOIN users ob ON ob.id=o.by_user_id WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS ov_by,
                    (SELECT o.reason FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS ov_reason
               FROM stimulus_memo_lines l
               JOIN stimulus_memos m ON m.id=l.memo_id
               JOIN users ur ON ur.id=l.user_id
               JOIN users ah ON ah.id=m.author_id
               LEFT JOIN departments d ON d.id=m.department_id
              WHERE $where
              ORDER BY m.period DESC, d.name, ur.full_name", $params);
        $seeAll = $this->seesAllStimulus();
        $canBase = Auth::has('director') || Auth::isAdmin();
        $out = [];
        foreach ($rows as $r) {
            $oversee = Org::canOverseeUser($uid, (int) $r['user_id']);
            if (!$seeAll && !$oversee && (int) $r['author_id'] !== $uid && (int) $r['user_id'] !== $uid) { continue; }
            $r['effective'] = $r['ov_amount'] !== null ? (float) $r['ov_amount'] : (float) $r['amount'];
            $r['can_override'] = $r['status'] === 'approved' && ($canBase || $oversee);
            $out[] = $r;
        }
        return $out;
    }

    /** Сводная таблица: кто кому какой стимул назначил по месяцам (+ корректировки). */
    public function summary(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $period = (string) $this->input('period', date('Y-m'));
        $rows = $this->summaryRows((int) Auth::id(), $period);
        $periods = array_column(Database::all('SELECT DISTINCT period FROM stimulus_memos ORDER BY period DESC'), 'period');
        $this->view('stimulus/summary', [
            'title' => 'Сводная по стимулу',
            'rows' => $rows,
            'period' => $period,
            'periods' => $periods,
            'statusLabels' => self::STATUS,
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Выгрузка сводной в Excel (с учётом видимости и фильтра периода). */
    public function summaryExport(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $rows = $this->summaryRows((int) Auth::id(), (string) $this->input('period', date('Y-m')));
        $headers = ['Месяц', 'Отдел', 'Получатель', 'Вид', 'Назначено', 'Корректировка', 'Кто снизил', 'Причина', 'Итог', 'Назначил', 'Прямое', 'Статус', '№ служебки'];
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                (string) $r['period'], (string) ($r['dept_name'] ?? '—'), (string) $r['recipient'],
                $r['pay_kind'] === 'onetime' ? 'единоврем.' : 'ежемес.',
                round((float) $r['amount'], 2),
                $r['ov_amount'] !== null ? round((float) $r['ov_amount'], 2) : '',
                (string) ($r['ov_by'] ?? ''), (string) ($r['ov_reason'] ?? ''),
                round((float) $r['effective'], 2),
                (string) $r['author_name'],
                $r['direct_tier'] ? ($r['direct_tier'] === 'director' ? 'директор' : 'зам') : '',
                self::STATUS[$r['status']] ?? (string) $r['status'],
                (string) ($r['number'] ?: ('#' . $r['line_id'])),
            ];
        }
        \App\Services\Xlsx::download('stimulus-summary-' . date('Y-m-d') . '.xlsx',
            [['name' => 'Стимул по месяцам', 'headers' => $headers, 'rows' => $data]]);
    }

    private static function nextNumber(): string
    {
        $yy = date('y');
        $n = (int) Database::scalar("SELECT COUNT(*)+1 FROM stimulus_memos WHERE number LIKE ?", ['%/' . $yy]);
        return 'СТ-' . $n . '/' . $yy;
    }
}
