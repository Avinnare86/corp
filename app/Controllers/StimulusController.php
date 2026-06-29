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

    /** Сколько служебок ждут действия пользователя (для бейджа меню) — с учётом режима И.о. */
    public static function inboxCount(int $uid): int
    {
        $ids = ($uid === (int) Auth::id()) ? Auth::actorIds() : [$uid];
        $n = 0;
        foreach (array_unique($ids) as $id) { $n += self::inboxCountSelf((int) $id); }
        return $n;
    }

    private static function inboxCountSelf(int $uid): int
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
        $archive = (int) $this->input('archive') === 1;   // вкладка «Архив» (отклонённые служебки)
        $seesAllArchive = !empty($roles['deputy_director']) || !empty($roles['director']) || !empty($roles['accountant']) || !empty($roles['admin']);

        // Архив: отклонённые служебки (после подписи). Автор видит свои; руководство/бухгалтерия — все.
        $archived = [];
        if ($archive) {
            $sql = "SELECT m.*, d.name AS dept_name, u.full_name AS author_name, ua.full_name AS archiver FROM stimulus_memos m
                       LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id LEFT JOIN users ua ON ua.id=m.archived_by
                      WHERE m.archived_at IS NOT NULL " . ($seesAllArchive ? '' : 'AND m.author_id=? ') . 'ORDER BY m.id DESC';
            $archived = Database::all($sql, $seesAllArchive ? [] : [$uid]);
        }

        $mine = Database::all(
            "SELECT m.*, d.name AS dept_name FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
              WHERE m.author_id=? AND m.archived_at IS NULL ORDER BY m.id DESC", [$uid]);

        // очередь на действие
        $todo = [];
        if (!empty($roles['deputy_director']) || !empty($roles['admin'])) {
            $todo = array_merge($todo, Database::all(
                "SELECT m.*, d.name AS dept_name, u.full_name AS author_name FROM stimulus_memos m
                   LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id
                  WHERE m.status='head_signed' AND m.archived_at IS NULL AND (? = 1 OR d.curator_id = ?) ORDER BY m.id DESC",
                [!empty($roles['admin']) ? 1 : 0, $uid]));
        }
        if (!empty($roles['director']) || !empty($roles['admin'])) {
            $todo = array_merge($todo, Database::all(
                "SELECT m.*, d.name AS dept_name, u.full_name AS author_name FROM stimulus_memos m
                   LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id
                  WHERE m.status='deputy_signed' AND m.archived_at IS NULL ORDER BY m.id DESC"));
        }

        // бухгалтерия видит подписанные (после зама) и утверждённые
        $accountant = [];
        if (!empty($roles['accountant']) || !empty($roles['admin'])) {
            $accountant = Database::all(
                "SELECT m.*, d.name AS dept_name, u.full_name AS author_name FROM stimulus_memos m
                   LEFT JOIN departments d ON d.id=m.department_id JOIN users u ON u.id=m.author_id
                  WHERE m.status IN ('deputy_signed','approved') AND m.archived_at IS NULL ORDER BY m.id DESC");
        }

        $this->view('stimulus/index', [
            'title' => 'Служебки о стимуле',
            'archive' => $archive, 'archived' => $archived, 'isAdmin' => !empty($roles['admin']),
            'mine' => $mine, 'todo' => $todo, 'accountant' => $accountant,
            'canCreate' => !empty($roles['dept_head']) || !empty($roles['deputy_director']) || !empty($roles['admin']),
            'canCreateMgmt' => !empty($roles['director']) || !empty($roles['admin']),
        ]);
    }

    public function create(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'admin');
        $this->memoForm(null, (int) Auth::id(), 'staff');
    }

    /** Уже назначенный стимул по сотрудникам за период: [user_id => [m_appr,m_proj,o_appr,o_proj]]. */
    private function assignedByUser(string $period, ?int $excludeMemoId = null): array
    {
        $where = "m.status <> 'rejected' AND m.period = ?"; $params = [$period];
        if ($excludeMemoId) { $where .= ' AND m.id <> ?'; $params[] = $excludeMemoId; }
        $raw = Database::all(
            "SELECT l.user_id, l.pay_kind, l.amount, m.status,
                    (SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1) AS ov_amount
               FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id
              WHERE $where", $params);
        $out = [];
        foreach ($raw as $r) {
            $uid = (int) $r['user_id'];
            if (!isset($out[$uid])) { $out[$uid] = ['m_appr' => 0.0, 'm_proj' => 0.0, 'o_appr' => 0.0, 'o_proj' => 0.0]; }
            $eff = $r['ov_amount'] !== null ? (float) $r['ov_amount'] : (float) $r['amount'];
            $appr = $r['status'] === 'approved';
            if ($r['pay_kind'] === 'onetime') { $out[$uid][$appr ? 'o_appr' : 'o_proj'] += $eff; }
            else { $out[$uid][$appr ? 'm_appr' : 'm_proj'] += $eff; }
        }
        return $out;
    }

    /** Отдельная служебка директора: стимул заместителям и главному бухгалтеру (Прил.№2). */
    public function createMgmt(): void
    {
        Auth::requireRole('director', 'admin');
        $this->memoForm(null, (int) Auth::id(), 'mgmt');
    }

    /**
     * Должностной тир для МАРШРУТА подписи стимула — по ФАКТИЧЕСКИ назначенным ролям.
     * В отличие от Org::tier(), НЕ повышает админа до директора: для служебок на стимул первая подпись
     * всегда того, кто оформляет (а не согласующего), вне зависимости от админ-прав.
     */
    private static function signTier(int $uid): ?string
    {
        $base = (string) Database::scalar('SELECT role FROM users WHERE id=?', [$uid]);
        $slugs = array_column(Database::all('SELECT role_slug FROM user_roles WHERE user_id=?', [$uid]), 'role_slug');
        $has = fn(string $s) => $base === $s || in_array($s, $slugs, true);
        if ($has('director')) { return 'director'; }
        if ($has('deputy_director')) { return 'deputy'; }
        if ($has('dept_head')) { return 'head'; }
        return null;
    }

    /**
     * «Назначить напрямую» объединено с обычным созданием служебки: маршрут подписи теперь
     * определяется должностью оформителя автоматически (зам/директор подписывают сразу, без начальника ниже).
     * Оставлено как алиас на единую форму /memos/new.
     */
    public function createDirect(): void
    {
        Auth::requireRole('director', 'deputy_director', 'admin');
        $this->redirect('/memos/new');
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
        // Введённое при ошибке валидации (backToForm сохранил в сессию) — чтобы не терять заполненное.
        $old = $_SESSION['memo_old'] ?? null;
        unset($_SESSION['memo_old']);
        $kind = ($memo['kind'] ?? $kind) === 'mgmt' ? 'mgmt' : $kind;
        $direct = $memo['direct_tier'] ?? $direct;   // при редактировании берём из служебки
        $period = (string) ($old['period'] ?? $memo['period'] ?? date('Y-m'));
        $isMgmt = $kind === 'mgmt';
        $deptOpts = null;
        $globalMembers = false;   // создание по всей ветке (список всех сотрудников, разные отделы)
        $canSeeAll = true;        // видеть «уже назначено» по всем строкам (в общем списке — гейт по ветке)
        $pieceDeptIds = [];       // отделы, по которым считаем сделку (квота/визы)

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
        } elseif ($memo) {
            // Редактирование — в пределах отдела служебки (один отдел, legacy).
            $cats = self::CATS_STAFF;
            $deptId = (int) ($memo['department_id'] ?: 0);
            $members = $deptId ? Database::all(
                'SELECT id, full_name, position, oklad, rate_volume, position_id FROM users WHERE department_id = ? AND is_active = 1 ORDER BY full_name',
                [$deptId]) : [];
            $forecast = $deptId ? \App\Services\StimulusBudgetService::forecast($deptId, $period) : null;
        } else {
            // Создание — одна служебка на ОДИН отдел: выбор отдела из своей ветки подчинённости,
            // список работников — только выбранного отдела. Маршрут — курирующему заму этого отдела.
            $cats = self::CATS_STAFF;
            $branchIds = Org::branchDeptIds($authorId);
            $deptOpts = self::deptsByIds($branchIds);
            $deptId = (int) (($old['department_id'] ?? 0) ?: ($this->input('dept') ?: 0));
            if ((!$deptId || !in_array($deptId, $branchIds, true)) && $deptOpts) { $deptId = (int) $deptOpts[0]['id']; }
            $members = $deptId ? Database::all(
                'SELECT id, full_name, position, oklad, rate_volume, position_id FROM users WHERE department_id = ? AND is_active = 1 ORDER BY full_name',
                [$deptId]) : [];
            $pieceDeptIds = $deptId ? [$deptId] : [];
            $forecast = $deptId ? \App\Services\StimulusBudgetService::forecast((int) $deptId, $period) : null;
        }

        $assigned = $this->assignedByUser($period, $memo['id'] ?? null);
        $branch = $globalMembers ? array_flip(Org::branchDeptIds($authorId)) : [];
        $isDir = Org::tier($authorId) === 'director';
        $pieceSet = array_flip($pieceDeptIds);
        foreach ($members as &$m) {
            $okl = (float) ($m['oklad'] ?? 0);
            if ($m['position_id']) { $po = Database::scalar('SELECT oklad FROM positions WHERE id=?', [$m['position_id']]); if ($po !== false) { $okl = (float) $po; } }
            $m['oklad_load'] = round($okl * (float) ($m['rate_volume'] ?? 1), 2);
            // Сделка к 25-му (перенос из квоты/виз): для одиночного отдела — всегда; для общего списка — только по головным отделам автора.
            $wantPiece = !$isMgmt && (!$globalMembers || isset($pieceSet[(int) ($m['department_id'] ?? 0)]));
            if ($wantPiece) {
                $pk = \App\Services\PayrollService::pieceByKind((int) $m['id'], $period, 1, 25);
                $m['kvota'] = $pk['anketa']; $m['visy'] = $pk['ops']; $m['piece'] = $pk['total'];
            } else {
                $m['kvota'] = 0; $m['visy'] = 0; $m['piece'] = 0;
            }
            // Видимость «уже назначено»: вся ветка автора (или директор); чужим подразделениям — вслепую.
            $canSee = $canSeeAll || $isDir || isset($branch[(int) ($m['department_id'] ?? 0)]);
            $a = $assigned[(int) $m['id']] ?? null;
            $m['can_see']   = $canSee ? 1 : 0;
            $m['ex_m_appr'] = $canSee && $a ? $a['m_appr'] : 0;
            $m['ex_m_proj'] = $canSee && $a ? $a['m_proj'] : 0;
            $m['ex_o_appr'] = $canSee && $a ? $a['o_appr'] : 0;
            $m['ex_o_proj'] = $canSee && $a ? $a['o_proj'] : 0;
            $m['dept_name'] = $m['dept_name'] ?? '';
        }
        unset($m);

        $ph = implode(',', array_fill(0, count($cats), '?'));
        $lines = $memo ? Database::all(
            'SELECT l.*, r.text AS reason_text FROM stimulus_memo_lines l
               LEFT JOIN stimulus_reasons r ON r.id = l.reason_id WHERE l.memo_id = ? ORDER BY l.id', [$memo['id']]) : [];
        // Восстановление введённого после ошибки: строки и основания берём из old (приоритетнее БД).
        if ($old && !empty($old['row']) && is_array($old['row'])) {
            $lines = [];
            foreach ($old['row'] as $r) {
                if (empty($r['user_id'])) { continue; }
                $lines[] = [
                    'user_id'     => (int) $r['user_id'],
                    'amount'      => (float) str_replace([' ', ','], ['', '.'], (string) ($r['amount'] ?? 0)),
                    'pay_kind'    => ($r['pay_kind'] ?? 'monthly') === 'onetime' ? 'onetime' : 'monthly',
                    'purpose'     => in_array($r['purpose'] ?? '', ['anketas', 'visas', 'other'], true) ? $r['purpose'] : 'other',
                    'reason_text' => trim((string) ($r['reason'] ?? '')),
                ];
            }
        }
        $selGrounds = $old
            ? array_values(array_filter(array_map('intval', (array) ($old['grounds'] ?? []))))
            : ($memo ? array_filter(array_map('intval', explode(',', (string) $memo['grounds_ids']))) : []);
        $this->view('stimulus/form', [
            'title' => $memo ? 'Служебка №' . ($memo['number'] ?: $memo['id'])
                : ($isMgmt ? 'Стимул заместителям / гл. бухгалтеру' : 'Новая служебка о стимуле'),
            'memo' => $memo,
            'kind' => $kind,
            'direct' => $direct,
            'deptOpts' => $deptOpts,
            'deptId' => $isMgmt ? 0 : (int) $deptId,
            'showPiece' => !$isMgmt,
            'branchMode' => $globalMembers,
            'dept' => ($isMgmt || $globalMembers) ? null : Database::one('SELECT * FROM departments WHERE id = ?', [$deptId]),
            'members' => $members,
            'lines' => $lines,
            'grounds' => Database::all("SELECT * FROM stimulus_grounds WHERE is_active = 1 AND category IN ($ph) ORDER BY category, percent DESC, text", $cats),
            'reasons' => $globalMembers
                ? Database::all('SELECT id, text FROM stimulus_reasons WHERE is_active = 1 ORDER BY (department_id IS NULL), text')
                : Database::all('SELECT id, text FROM stimulus_reasons WHERE is_active = 1 AND (department_id = ? OR department_id IS NULL) ORDER BY (department_id IS NULL), text', [(int) $deptId]),
            'sources' => Database::all('SELECT * FROM pay_sources ORDER BY id'),
            'selGrounds' => $selGrounds,
            'fPeriod'  => $period,
            'fPayKind' => (string) ($old['pay_kind'] ?? $memo['pay_kind'] ?? 'monthly'),
            'fSource'  => isset($old['source_id']) ? (int) $old['source_id'] : (int) ($memo['source_id'] ?? 0),
            'forecast' => $forecast,
            'sourceBudget' => (!$isMgmt && $deptId) ? \App\Services\StimulusBudgetService::sourceBreakdown((int) $deptId, $period, $memo['id'] ?? null) : null,
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
            $tier = self::signTier($uid);
            $isDirTier = $tier === 'director';   // маршрут — по ФАКТИЧЕСКОЙ должности, админ НЕ повышается до директора
            $admin = Auth::isAdmin();            // у админа полные права (авторизация), но порядок подписи — как у роли
            if (!$isDirTier && $tier !== 'deputy' && !$admin) { flash('Прямое назначение доступно директору или курирующему заму.', 'error'); $this->redirect('/memos'); }
            if (!$deptId) { flash('Выберите подразделение для прямого назначения.', 'error'); $this->redirect('/memos'); }
            if (!$isDirTier && !$admin && !Org::isSuperiorOfDept($uid, $deptId)) { flash('Назначать напрямую можно только в курируемые вами подразделения.', 'error'); $this->redirect('/memos'); }
            // Первая подпись — того, кто оформляет: директор утверждает сразу; зам подписывает и направляет директору.
            $direct = $isDirTier ? 'director' : 'deputy';
        }

        if (!$groundIds) { flash('Выберите хотя бы одно основание (раздел 4).', 'error'); $this->backToForm($id, $kind); }
        if (!$sourceId)  { flash('Выберите источник выплат — это обязательное поле.', 'error'); $this->backToForm($id, $kind); }
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
            $lkind = $payKind;   // вид выплаты единый на всю служебку (выбирается один раз вверху формы)
            $purpose = in_array($r['purpose'] ?? '', ['anketas', 'visas', 'other'], true) ? $r['purpose'] : 'other';
            $reasonText = trim((string) ($r['reason'] ?? ''));   // основание: выбор из справочника или свободный ввод
            $lines[] = ['user_id' => $eid, 'amount' => round($amount, 2), 'pay_kind' => $lkind, 'oklad_load' => $load, 'percent' => $pct, 'purpose' => $purpose, 'reason_text' => $reasonText];
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

        // === Контроль бюджета по источнику выплат (раздельный учёт) ===
        // Сумма по отделу (для веера — по отделу каждого сотрудника; иначе — общий $deptId) не должна
        // выводить выбранный источник за лимит. mgmt/без отдела/без бюджета — без проверки.
        if ($kind !== 'mgmt') {
            $sumByDept = [];
            if (!$id && !$direct) {
                foreach ($lines as $ln) {
                    $d = (int) Database::scalar('SELECT department_id FROM users WHERE id=?', [$ln['user_id']]);
                    if ($d) { $sumByDept[$d] = ($sumByDept[$d] ?? 0) + $ln['amount']; }
                }
            } elseif ($deptId) {
                $sumByDept[(int) $deptId] = array_sum(array_map(fn($l) => $l['amount'], $lines));
            }
            foreach ($sumByDept as $d => $sum) {
                $err = $this->budgetGuard((int) $d, $sourceId, (float) $sum, $period, $id ?: null);
                if ($err) { flash($err, 'error'); $this->backToForm($id, $kind); }
            }
        }

        // Новая служебка по сотрудникам (staff, без прямого назначения) → веер по отделам:
        // одна служебка на каждый отдел сотрудника, связанные общим batch_id; маршрут — курирующему заму отдела.
        if (!$id && $kind === 'staff' && !$direct) {
            $pfrom = $period . '-01';
            $pto = date('Y-m-t', strtotime($pfrom));
            $byDept = []; $noDept = [];
            foreach ($lines as $ln) {
                $d = (int) Database::scalar('SELECT department_id FROM users WHERE id=?', [$ln['user_id']]);
                if (!$d) { $noDept[] = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [$ln['user_id']]); continue; }
                $byDept[$d][] = $ln;
            }
            if ($noDept) {
                flash('У сотрудников не указан отдел — некуда направить служебку: ' . implode(', ', array_unique($noDept)) . '. Сначала назначьте им отдел.', 'error');
                $this->backToForm($id, $kind);
            }
            // Маршрут подписи — по фактической должности ОФОРМИТЕЛЯ (а не согласующего); админ НЕ повышается до директора.
            // Директор → утверждено сразу; зам (по ЛЮБОМУ отделу своей ветки, не только курируемому лично) →
            // подписывает сразу как зам (deputy_signed → директор); начальник отдела → обычный маршрут (начальник → курирующий зам → директор).
            $atier = self::signTier($uid);
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $batchId = null; $created = []; $noCurator = [];
            foreach ($byDept as $d => $dlines) {
                $curator = (int) Database::scalar('SELECT curator_id FROM departments WHERE id=?', [$d]);
                $ctier = $atier === 'director' ? 'director' : ($atier === 'deputy' ? 'deputy' : null);
                if ($ctier === null && !$curator) { $noCurator[] = (string) Database::scalar('SELECT name FROM departments WHERE id=?', [$d]); }
                $memoId = Database::insert(
                    'INSERT INTO stimulus_memos (department_id, author_id, period, pay_kind, source_id, grounds, grounds_ids, kind, direct_tier, status, batch_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$d, $uid, $period, $payKind, $sourceId, implode('; ', $groundTexts), implode(',', $groundIds), 'staff', $ctier, 'draft', null]);
                if ($batchId === null) { $batchId = $memoId; }
                foreach ($dlines as $ln) {
                    Database::insert(
                        'INSERT INTO stimulus_memo_lines (memo_id, user_id, amount, pay_kind, period_from, period_to, oklad_load, percent, purpose, reason_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [$memoId, $ln['user_id'], $ln['amount'], $ln['pay_kind'], $pfrom, $pto, $ln['oklad_load'], $ln['percent'], $ln['purpose'], $this->resolveReason((int) $d, $ln['reason_text'] ?? '')]);
                }
                $created[] = $memoId;
            }
            Database::run('UPDATE stimulus_memos SET batch_id=? WHERE id IN (' . implode(',', array_fill(0, count($created), '?')) . ')',
                array_merge([$batchId], $created));
            $pdo->commit();
            if (count($created) === 1) {
                flash('Служебка сохранена как черновик. Подпишите её, чтобы отправить курирующему заму.');
                $this->redirect('/memos/' . (int) $created[0]);
            }
            $msg = 'Создано служебок: ' . count($created) . ' (по числу отделов). Подпишите — каждая уйдёт курирующему заму своего отдела.';
            if ($noCurator) { $msg .= ' Внимание: не назначен куратор у отдела(ов): ' . implode(', ', array_unique($noCurator)) . ' — там служебка остановится на этапе зама (назначьте куратора в Оргструктуре).'; }
            flash($msg);
            $this->redirect('/memos/batch/' . (int) $batchId);
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
            Database::insert('INSERT INTO stimulus_memo_lines (memo_id, user_id, amount, pay_kind, period_from, period_to, oklad_load, percent, purpose, reason_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$id, $ln['user_id'], $ln['amount'], $ln['pay_kind'], $pfrom, $pto, $ln['oklad_load'], $ln['percent'], $ln['purpose'], $this->resolveReason($deptId, $ln['reason_text'] ?? '')]);
        }
        $pdo->commit();
        flash($kind === 'mgmt'
            ? 'Черновик сохранён. Подпишите его, чтобы утвердить стимул приказом директора.'
            : 'Служебка сохранена как черновик. Подпишите её, чтобы отправить курирующему заму.');
        $this->redirect('/memos/' . $id);
    }

    private function backToForm(int $id, string $kind = 'staff'): void
    {
        // Сохранить введённое, чтобы при ошибке валидации форма не очищалась (memoForm подхватит из сессии).
        $_SESSION['memo_old'] = $_POST;
        if ($id) { $this->redirect('/memos/' . $id . '/edit'); }
        $this->redirect($kind === 'mgmt' ? '/memos/mgmt/new' : '/memos/new');
    }

    /**
     * Основание строки: «выбор или ввод». Текст ищем в справочнике (по отделу либо общий);
     * если такого ещё нет — добавляем в справочник отдела и возвращаем id. Пусто → NULL.
     */
    private function resolveReason(?int $deptId, string $text): ?int
    {
        $text = trim($text);
        if ($text === '') { return null; }
        $id = Database::scalar(
            'SELECT id FROM stimulus_reasons WHERE is_active = 1 AND text = ? AND (department_id = ? OR department_id IS NULL)
              ORDER BY (department_id IS NULL) LIMIT 1', [$text, $deptId]);
        if ($id) { return (int) $id; }
        return (int) Database::insert('INSERT INTO stimulus_reasons (department_id, text, is_active) VALUES (?,?,1)', [$deptId ?: null, $text]);
    }

    /** Директор: сперва по оргструктуре (глава корневой дирекции), затем первый пользователь с ролью director. */
    private static function directorUser(): ?array
    {
        $sid = Org::directorUserId();
        if ($sid) {
            $u = Database::one('SELECT id, full_name, position FROM users WHERE id = ? AND is_active = 1', [$sid]);
            if ($u) { return $u; }
        }
        return Database::one(
            "SELECT id, full_name, position FROM users
              WHERE is_active = 1 AND id IN (SELECT user_id FROM user_roles WHERE role_slug='director')
              ORDER BY id LIMIT 1") ?: null;
    }

    /**
     * ФИО и должность директора для штампа/шапки служебки: настройка (админ) → пользователь с ролью director → дефолт.
     * @return array{0:string,1:string} [ФИО, должность]
     */
    public static function directorSigner(): array
    {
        $name = trim((string) Settings::get('stimul_director_name', ''));
        $pos  = trim((string) Settings::get('stimul_director_position', ''));
        if ($name === '') {
            $du = self::directorUser();
            if ($du) { $name = (string) $du['full_name']; if ($pos === '') { $pos = (string) ($du['position'] ?? ''); } }
        }
        if ($name === '') { $name = 'Д.Н. Семёнов'; }
        if ($pos === '')  { $pos = 'Генеральный директор'; }
        return [$name, $pos];
    }

    /** Полный отпечаток ЭП (HMAC-SHA256, без сокращения) — id|подписант|время. */
    private static function makeSig(int $id, int $uid, string $at): string
    {
        return hash_hmac('sha256', $id . '|' . $uid . '|' . $at, (string) Settings::get('sign_secret', 'uchet'));
    }

    /** Отпечаток гибкого штампа (админ): id|подписант|время|порядок — уникален в пределах служебки. */
    private static function makeStampSig(int $id, int $uid, string $at, int $seq): string
    {
        return hash_hmac('sha256', $id . '|' . $uid . '|' . $at . '|' . $seq, (string) Settings::get('sign_secret', 'uchet'));
    }

    /** Дата/время подписи: админ при подписи «от имени директора» задаёт вручную (datetime-local); иначе $default. */
    private function signAtInput(string $default): string
    {
        $raw = trim((string) $this->input('sign_at'));
        if ($raw === '') { return $default; }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : $default;
    }

    /**
     * Гибкие штампы ЭП «задним числом» (только админ): произвольное число штампов в заданном порядке,
     * у каждого своя дата/время. Аддитивно к обычному маршруту — пишет в stimulus_stamps, на финале
     * делает служебку утверждённой (с номером), чтобы она попала в бухгалтерию как обычная.
     */
    public function stamps(string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        if (!Auth::isAdmin()) { http_response_code(403); exit('Только администратор.'); }
        $mid = (int) $id;
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$mid]);
        if (!$memo) { flash('Служебка не найдена.', 'error'); $this->redirect('/memos'); }

        // srow[idx][role_label|signer_user_id|signer_name|signer_position|sign_type|signed_at]
        $rows = [];
        foreach (($_POST['srow'] ?? []) as $r) {
            $label = trim((string) ($r['role_label'] ?? ''));
            $name  = trim((string) ($r['signer_name'] ?? ''));
            $atRaw = trim((string) ($r['signed_at'] ?? ''));
            if ($label === '' || $name === '' || $atRaw === '') { continue; }
            $ts = strtotime($atRaw);
            if (!$ts) { continue; }
            $rows[] = [
                'role_label'      => $label,
                'signer_user_id'  => !empty($r['signer_user_id']) ? (int) $r['signer_user_id'] : null,
                'signer_name'     => $name,
                'signer_position' => trim((string) ($r['signer_position'] ?? '')),
                'sign_type'       => in_array($r['sign_type'] ?? 'PEP', ['PEP', 'UNEP', 'UKEP'], true) ? $r['sign_type'] : 'PEP',
                'signed_at'       => date('Y-m-d H:i:s', $ts),
            ];
        }
        if (!$rows) { flash('Добавьте хотя бы один штамп: роль, подписант и дата/время.', 'error'); $this->redirect('/memos/' . $mid); }

        $uid = (int) Auth::id();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        Database::run('DELETE FROM stimulus_stamps WHERE memo_id=?', [$mid]);   // переустановка набора (идемпотентно)
        foreach ($rows as $i => $r) {
            $sig = self::makeStampSig($mid, (int) ($r['signer_user_id'] ?? 0), $r['signed_at'], $i);
            Database::insert(
                'INSERT INTO stimulus_stamps (memo_id, seq, role_label, signer_user_id, signer_name, signer_position, sign_type, signed_at, sign_hash, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$mid, $i, $r['role_label'], $r['signer_user_id'], $r['signer_name'], $r['signer_position'], $r['sign_type'], $r['signed_at'], $sig, $uid]);
        }
        // Сделать видимой бухгалтерии как обычную утверждённую: статус + номер (если ещё нет).
        $num = $memo['number'] ?: self::nextNumber();
        Database::run('UPDATE stimulus_memos SET status=?, number=? WHERE id=?', ['approved', $num, $mid]);
        $pdo->commit();

        \App\Services\Audit::log('STAMP_ADMIN', 'Стимул: штампы ЭП проставлены админом (задним числом)',
            ['memo_id' => $mid, 'number' => $num, 'count' => count($rows)]);
        flash('Штампы проставлены, служебка утверждена (№' . $num . '). Бухгалтерия видит документ как обычный.');
        $this->redirect('/memos/' . $mid);
    }

    /** Очистка гибких штампов (только админ): возврат к стандартному выводу подписей. Статус/номер не трогаем. */
    public function stampClear(string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        if (!Auth::isAdmin()) { http_response_code(403); exit('Только администратор.'); }
        $mid = (int) $id;
        Database::run('DELETE FROM stimulus_stamps WHERE memo_id=?', [$mid]);
        flash('Гибкие штампы очищены. Печать показывает стандартные подписи маршрута.');
        $this->redirect('/memos/' . $mid);
    }

    /**
     * Контроль бюджета источника: вернуть текст ошибки, если $addAmount не помещается в доступный
     * лимит источника по отделу, иначе null. mgmt/без отдела/без источника/без заданного бюджета — null.
     */
    private function budgetGuard(int $deptId, ?int $sourceId, float $addAmount, string $period, ?int $excludeMemoId): ?string
    {
        if (!$deptId || !$sourceId) { return null; }
        $a = \App\Services\StimulusBudgetService::availableForSource($deptId, $sourceId, $period, $excludeMemoId);
        if (!$a['has_budget']) { return null; }   // бюджет отдела на год не задан — не блокируем
        if ($addAmount > $a['available'] + 0.01) {
            $sname = (string) Database::scalar('SELECT name FROM pay_sources WHERE id=?', [$sourceId]);
            $f = fn($v) => number_format(max(0, (float) $v), 2, ',', ' ');
            return 'Недостаточно средств по источнику «' . $sname . '»: доступно ' . $f($a['available'])
                . ' ₽, требуется ' . $f($addAmount) . ' ₽. Уменьшите сумму, выберите другой источник или увеличьте бюджет (Финансы → Бюджет ФОТ).';
        }
        return null;
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
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'finance_manager', 'admin');
        $memo = Database::one(
            'SELECT m.*, d.name AS dept_name, d.curator_id, a.full_name AS author_name
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
               JOIN users a ON a.id=m.author_id WHERE m.id=?', [$id]);
        if (!$memo) { flash('Служебка не найдена.', 'error'); $this->redirect('/memos'); }
        $uid = (int) Auth::id();
        $roles = Auth::effectiveRoles();   // свои роли + (в режиме И.о.) роли замещаемого
        // видимость: автор / куратор / директор / бухгалтер(после зама) / админ (с учётом режима И.о.)
        $isAuthor = Auth::actsAsUser((int)$memo['author_id']);
        $isCurator = !empty($roles['deputy_director']) && (Auth::actsAsUser((int)$memo['curator_id']) || Auth::isAdmin());
        $isDirector = !empty($roles['director']) || Auth::isAdmin();
        $isAcc = (!empty($roles['accountant']) || Auth::isAdmin()) && in_array($memo['status'], ['deputy_signed','approved'], true);
        $isFin = !empty($roles['finance_manager']);   // менеджер финансов видит весь стимул
        // вышестоящий по структуре (сам или как И.о.) видит служебки подчинённых подразделений
        $dept = $memo['department_id'] ? (int) $memo['department_id'] : null;
        $isSuperior = false;
        foreach (Auth::actorIds() as $aid) { if (Org::isSuperiorOfDept((int) $aid, $dept)) { $isSuperior = true; break; } }
        if (!($isAuthor || $isCurator || $isDirector || $isAcc || $isFin || $isSuperior || Auth::isAdmin())) {
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
        // Кросс-ветка: автор не является начальником отдела служебки → первый этап подписи он ставит как инициатор.
        $deptHead = $memo['department_id'] ? (int) Database::scalar('SELECT head_id FROM departments WHERE id=?', [$memo['department_id']]) : 0;
        $isCross = !$isMgmt && !$direct && (int) $memo['author_id'] !== $deptHead;
        $canHeadSign = !$isMgmt && !$direct && $isAuthor && $memo['status'] === 'draft';
        $canDeputySign = !$isMgmt && !$direct && (($memo['status'] === 'head_signed' && (!empty($roles['deputy_director']) && Auth::actsAsUser((int)$memo['curator_id']))) || ($memo['status']==='head_signed' && Auth::isAdmin()));
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
        // Подпись «от имени директора» (только админ): на шаге утверждения директором админ задаёт дату,
        // а штамп проставляется на имя настроенного директора (а не админа).
        $isDirectorStep = $canDirectorSign || $canMgmtSign
            || ($canDirectSign && $direct === 'director')
            || ($canDirectSign && $direct === 'deputy' && $memo['status'] === 'deputy_signed');
        $dirOnBehalf = $isDirectorStep && Auth::isAdmin();
        [$dsName, $dsPos] = self::directorSigner();
        $dirDisplay = trim($dsName . ($dsPos ? ', ' . $dsPos : ''));
        // Гибкие штампы (админ, задним числом): уже проставленные + данные для конструктора.
        $isAdmin = Auth::isAdmin();
        $flexStamps = Database::all('SELECT * FROM stimulus_stamps WHERE memo_id=? ORDER BY seq, id', [$id]) ?: null;
        $employees = $isAdmin
            ? Database::all('SELECT id, full_name, position FROM users WHERE is_active=1 ORDER BY full_name') : [];
        // Префилл конструктора из стандартных штампов (если они есть и гибких ещё нет) — чтобы ничего не потерять.
        $legacyStamps = [];
        if ($isAdmin && !$flexStamps) {
            $slots = [
                ['Начальник отдела (составил)', $memo['head_id'] ?? 0, $memo['head_signed_at'] ?? null, $memo['head_sign_type'] ?? 'PEP'],
                ['Курирующий заместитель директора (утвердил)', $memo['deputy_id'] ?? 0, $memo['deputy_signed_at'] ?? null, $memo['deputy_sign_type'] ?? 'PEP'],
                ['Директор (утвердил)', $memo['director_id'] ?? 0, $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? 'PEP'],
            ];
            foreach ($slots as [$label, $sid, $at, $stype]) {
                if (!$at) { continue; }
                if ($label === 'Директор (утвердил)' && !empty($memo['director_sign_name'])) {
                    $nm = (string) $memo['director_sign_name']; $pos = (string) ($memo['director_sign_position'] ?? ''); $sidOut = null;
                } else {
                    $u = $sid ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$sid]) : null;
                    $nm = (string) ($u['full_name'] ?? ''); $pos = (string) ($u['position'] ?? ''); $sidOut = $sid ?: null;
                }
                $legacyStamps[] = ['role_label' => $label, 'signer_user_id' => $sidOut, 'signer_name' => $nm,
                    'signer_position' => $pos, 'sign_type' => $stype ?: 'PEP', 'signed_at' => substr((string) $at, 0, 16)];
            }
        }
        $this->view('stimulus/show', [
            'title' => 'Служебка №' . ($memo['number'] ?: $memo['id']),
            'memo' => $memo, 'lines' => $lines, 'groundRows' => $groundRows,
            'kind' => $memo['kind'] ?? 'staff',
            'direct' => $direct,
            'isCross' => $isCross,
            'batchId' => $memo['batch_id'] ?? null,
            'batchCount' => !empty($memo['batch_id']) ? (int) Database::scalar('SELECT COUNT(*) FROM stimulus_memos WHERE batch_id=?', [$memo['batch_id']]) : 0,
            'total' => array_sum(array_map(fn($l)=>(float)$l['amount'], $lines)),
            'source' => $memo['source_id'] ? Database::one('SELECT * FROM pay_sources WHERE id=?', [$memo['source_id']]) : null,
            'canEdit' => $isAuthor && in_array($memo['status'], ['draft','revision'], true),
            'canHeadSign' => $canHeadSign,
            'canDeputySign' => $canDeputySign,
            'canDirectorSign' => $canDirectorSign,
            'canMgmtSign' => $canMgmtSign,
            'canDirectSign' => $canDirectSign,
            'directLabel' => $directLabel,
            'dirOnBehalf' => $dirOnBehalf,
            'dirDisplay' => $dirDisplay,
            'canReject' => ($canDeputySign || $canDirectorSign || ($direct === 'deputy' && $memo['status'] === 'deputy_signed' && $isDirector)),
            'isAdmin' => $isAdmin,
            'flexStamps' => $flexStamps,
            'employees' => $employees,
            'legacyStamps' => $legacyStamps,
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Страница пакета: служебки, созданные одной формой по разным отделам (общий batch_id). */
    public function showBatch(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $batchId = (int) $id;
        $memos = Database::all(
            "SELECT m.*, d.name AS dept_name, d.curator_id, c.full_name AS curator_name, a.full_name AS author_name,
                    (SELECT COALESCE(SUM(l.amount),0) FROM stimulus_memo_lines l WHERE l.memo_id=m.id) AS total,
                    (SELECT COUNT(*) FROM stimulus_memo_lines l WHERE l.memo_id=m.id) AS people
               FROM stimulus_memos m
               LEFT JOIN departments d ON d.id=m.department_id
               LEFT JOIN users c ON c.id=d.curator_id
               JOIN users a ON a.id=m.author_id
              WHERE m.batch_id=? ORDER BY d.name", [$batchId]);
        if (!$memos) { flash('Пакет служебок не найден.', 'error'); $this->redirect('/memos'); }
        $uid = (int) Auth::id();
        $author = (int) $memos[0]['author_id'];
        $canView = $author === $uid || Auth::has('director', 'accountant', 'finance_manager') || Auth::isAdmin();
        if (!$canView) {
            foreach ($memos as $m) {
                if (Org::isSuperiorOfDept($uid, $m['department_id'] ? (int) $m['department_id'] : null)) { $canView = true; break; }
            }
        }
        if (!$canView) { flash('Нет доступа к пакету.', 'error'); $this->redirect('/memos'); }
        $draftMine = $author === $uid ? count(array_filter($memos, fn($m) => $m['status'] === 'draft')) : 0;
        $this->view('stimulus/batch', [
            'title' => 'Пакет служебок о стимуле',
            'memos' => $memos,
            'batchId' => $batchId,
            'statusLabels' => self::STATUS,
            'isAuthor' => $author === $uid,
            'authorName' => $memos[0]['author_name'],
            'period' => $memos[0]['period'],
            'draftMine' => $draftMine,
            'total' => array_sum(array_map(fn($m) => (float) $m['total'], $memos)),
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Пакетная подпись инициатором: одна ПЭП на все черновики пакета (каждая уходит своему маршруту). */
    public function signBatch(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $batchId = (int) $id;
        $memos = Database::all(
            "SELECT m.*, d.curator_id FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
              WHERE m.batch_id=? AND m.author_id=? AND m.status='draft' ORDER BY m.id", [$batchId, $uid]);
        if (!$memos) { flash('Нет черновиков пакета для подписи.', 'error'); $this->redirect('/memos/batch/' . $batchId); }
        // ПЭП — подтверждение паролем (один раз на пакет).
        $pass = (string) $this->input('password');
        $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id=?', [$uid]);
        if (!password_verify($pass, $hash)) { flash('Неверный пароль — подпись отклонена.', 'error'); $this->redirect('/memos/batch/' . $batchId); }
        $roles = Auth::roles();
        $isDir = !empty($roles['director']) || Auth::isAdmin();
        $isDep = !empty($roles['deputy_director']) || Auth::isAdmin();
        $secret = (string) Settings::get('sign_secret', 'uchet');
        $signed = 0;
        foreach ($memos as $memo) {
            $mid = (int) $memo['id'];
            $now = date('Y-m-d H:i:s');
            $sig = substr(hash_hmac('sha256', $mid . '|' . $uid . '|' . $now, $secret), 0, 24);
            $direct = $memo['direct_tier'] ?? null;
            $num = $memo['number'] ?: self::nextNumber();
            if ($direct === 'director' && $isDir) {
                Database::run('UPDATE stimulus_memos SET status=?, number=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=? WHERE id=?',
                    ['approved', $num, $uid, 'PEP', $now, $sig, $mid]);
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='accountant'") as $acc) {
                    NotificationService::create((int) $acc['id'], 'Стимул назначен директором', "Директор назначил стимул напрямую (№{$num}).");
                }
            } elseif ($direct === 'deputy' && $isDep) {
                Database::run('UPDATE stimulus_memos SET status=?, number=?, deputy_id=?, deputy_sign_type=?, deputy_signed_at=?, deputy_sign_hash=? WHERE id=?',
                    ['deputy_signed', $num, $uid, 'PEP', $now, $sig, $mid]);
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='director'") as $dir) {
                    NotificationService::create((int) $dir['id'], 'Служебка на утверждение директором', "Зам назначил стимул напрямую (№{$num}), ожидает вашего утверждения.");
                }
            } else {
                // Обычный маршрут: инициатор подписывает → head_signed → курирующему заму отдела.
                Database::run('UPDATE stimulus_memos SET status=?, number=?, head_id=?, head_sign_type=?, head_signed_at=?, head_sign_hash=? WHERE id=?',
                    ['head_signed', $num, $uid, 'PEP', $now, $sig, $mid]);
                if ($memo['curator_id']) {
                    NotificationService::create((int) $memo['curator_id'], 'Служебка на утверждение', "Поступила служебка о стимуле №{$num} на ваше утверждение.");
                }
            }
            $signed++;
        }
        flash("Подписано как инициатор: {$signed} служебок(и). Каждая направлена курирующему заму своего отдела.");
        $this->redirect('/memos/batch/' . $batchId);
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
            // Если подпись поставлена «от имени директора» (админом) — на штампе зафиксированы ФИО/должность директора.
            'director' => !empty($memo['director_sign_name'])
                ? ['full_name' => $memo['director_sign_name'], 'position' => $memo['director_sign_position'] ?? '']
                : ($memo['director_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['director_id']]) : null),
        ];
        // Пометка И.о./ВРИО на штампе: если шаг подписал исполняющий обязанности (по дате подписи) — добавляем «И.о. <должность>».
        $mHead = \App\Services\Acting::markerOn((int) ($memo['head_id'] ?? 0), (int) $memo['author_id'], $memo['head_signed_at'] ?? null);
        $mDep  = \App\Services\Acting::markerOn((int) ($memo['deputy_id'] ?? 0), (int) ($memo['curator_id'] ?? 0), $memo['deputy_signed_at'] ?? null);
        $mDir  = \App\Services\Acting::markerOn((int) ($memo['director_id'] ?? 0), (int) (Org::directorUserId() ?? 0), $memo['director_signed_at'] ?? null);
        if (!empty($signers['head']) && $mHead)   { $signers['head']['full_name']   = $mHead . ' — ' . $signers['head']['full_name']; }
        if (!empty($signers['deputy']) && $mDep)  { $signers['deputy']['full_name'] = $mDep . ' — ' . $signers['deputy']['full_name']; }
        if (!empty($signers['director']) && $mDir && empty($memo['director_sign_name'])) { $signers['director']['full_name'] = $mDir . ' — ' . $signers['director']['full_name']; }
        $dirName = self::directorSigner()[0];
        $gids = array_values(array_filter(array_map('intval', explode(',', (string) $memo['grounds_ids']))));
        $groundRows = $gids
            ? Database::all('SELECT text, category, percent FROM stimulus_grounds WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ') ORDER BY percent DESC, category', $gids)
            : array_map(fn($t) => ['text' => $t, 'category' => '', 'percent' => 0], array_values(array_filter(array_map('trim', explode(';', (string) $memo['grounds'])))));
        // Гибкие штампы (админ, задним числом): если есть — печать выводит их вместо 3 фиксированных слотов.
        $flexStamps = Database::all(
            'SELECT role_label, signer_name AS full_name, signer_position AS position, sign_type, signed_at, sign_hash
               FROM stimulus_stamps WHERE memo_id=? ORDER BY seq, id', [$id]);
        return [
            'memo' => $memo, 'lines' => $lines, 'signers' => $signers,
            'kind' => $memo['kind'] ?? 'staff',
            'source' => $memo['source_id'] ? Database::one('SELECT * FROM pay_sources WHERE id=?', [$memo['source_id']]) : null,
            'directorName' => $dirName ?: 'Д.Н. Семёнов',
            'grounds' => array_map(fn($g) => $g['text'], $groundRows),
            'groundRows' => $groundRows,
            'flexStamps' => $flexStamps ?: null,
        ];
    }

    /** Сформированный документ служебки (А4) со штампами ЭП — печать → PDF. */
    public function printDoc(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $d = $this->printData((int) $id);
        if (!$d) { http_response_code(404); exit('Служебка не найдена'); }
        // Отметка скачивания: только когда PDF открывает бухгалтерия (для отчёта покрытия).
        if (Auth::effectiveHas('accountant')) {
            Database::run('UPDATE stimulus_memos SET pdf_downloaded_at=?, pdf_downloaded_by=? WHERE id=?',
                [date('Y-m-d H:i:s'), (int) Auth::id(), (int) $id]);
        }
        $this->view('stimulus/print', array_merge(['title' => 'Служебка №' . ($d['memo']['number'] ?: $d['memo']['id'])], $d), false);
    }

    /** Отчёт «служебки на печать»: по отделам, в разрезе ежемес./единовр., со ссылками на PDF. */
    public function printReport(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'finance_manager', 'admin');
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
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'finance_manager', 'admin');
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
                    m.head_id, m.deputy_id, m.director_id,
                    d.name AS dept_name, a.full_name AS author_name,
                    (SELECT COUNT(*) FROM stimulus_memo_lines l WHERE l.memo_id=m.id) AS people
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id JOIN users a ON a.id=m.author_id
              WHERE $where ORDER BY d.name, m.pay_kind DESC, m.id", $params);
        // Бухгалтерия / менеджер финансов / директор / админ — видят все; остальные (начальник/зам) —
        // только служебки, которые САМИ создали или СОГЛАСОВАЛИ (подписали как начальник/зам/директор).
        if ($this->seesAllStimulus()) { return $rows; }
        $uid = (int) Auth::id();
        return array_values(array_filter($rows, fn($m) =>
            (int) $m['author_id'] === $uid
            || (int) ($m['head_id'] ?? 0) === $uid
            || (int) ($m['deputy_id'] ?? 0) === $uid
            || (int) ($m['director_id'] ?? 0) === $uid));
    }

    /** Отчёт для бухгалтерии: по отделам — все служебки периода значками PDF (скачано / не скачано). */
    public function coverage(): void
    {
        Auth::requireRole('accountant', 'director', 'finance_manager', 'admin');
        $period = (string) $this->input('period', date('Y-m'));
        $this->view('stimulus/coverage', [
            'title' => 'Покрытие стимулом (бухгалтерия)',
            'byDept' => $this->coverageMemos($period),
            'statusLabels' => self::STATUS,
            'period' => $period,
            'periods' => array_column(Database::all('SELECT DISTINCT period FROM stimulus_memos ORDER BY period DESC'), 'period'),
        ]);
    }

    /**
     * Служебки периода, видимые бухгалтерии (deputy_signed/approved), сгруппированные по отделу.
     * По каждой — № / вид / эффективная сумма (с учётом последнего override) / статус / состояние скачивания.
     */
    private function coverageMemos(string $period): array
    {
        $where = "m.status IN ('deputy_signed','approved')"; $params = [];
        if ($period !== '') { $where .= ' AND m.period=?'; $params[] = $period; }
        $rows = Database::all(
            "SELECT m.id, m.number, m.status, m.period, m.pay_kind, m.department_id,
                    d.name AS dept_name, m.pdf_downloaded_at, du.full_name AS pdf_by_name,
                    (SELECT COALESCE(SUM(COALESCE(
                        (SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1),
                        l.amount)),0)
                       FROM stimulus_memo_lines l WHERE l.memo_id=m.id) AS total,
                    (SELECT COUNT(*) FROM stimulus_memo_lines l WHERE l.memo_id=m.id) AS people
               FROM stimulus_memos m
               LEFT JOIN departments d ON d.id=m.department_id
               LEFT JOIN users du ON du.id=m.pdf_downloaded_by
              WHERE $where ORDER BY (m.department_id IS NULL), d.name, m.id", $params);
        $byDept = [];
        foreach ($rows as $r) {
            $key = $r['department_id'] ? $r['dept_name'] : 'Руководство / без отдела';
            $byDept[$key][] = $r;
        }
        return $byDept;
    }

    /** Выгрузка отчёта покрытия в Excel: отдел / № / вид / сумма / статус / скачано / кем. */
    public function coverageExport(): void
    {
        Auth::requireRole('accountant', 'director', 'finance_manager', 'admin');
        $byDept = $this->coverageMemos((string) $this->input('period', date('Y-m')));
        $headers = ['Отдел', '№ служебки', 'Вид', 'Сумма', 'Статус', 'Скачано', 'Кем скачано'];
        $data = [];
        foreach ($byDept as $dept => $memos) {
            foreach ($memos as $m) {
                $data[] = [
                    $dept, $m['number'] ?: ('#' . $m['id']),
                    $m['pay_kind'] === 'onetime' ? 'единовр.' : 'ежемес.',
                    round((float) $m['total'], 2),
                    self::STATUS[$m['status']] ?? $m['status'],
                    $m['pdf_downloaded_at'] ? substr((string) $m['pdf_downloaded_at'], 0, 16) : 'не скачано',
                    (string) ($m['pdf_by_name'] ?? ''),
                ];
            }
        }
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
        $roles = Auth::effectiveRoles();   // роли с учётом режима И.о.; подпись/пароль — всегда физического $uid

        // Подпись через единый сервис ЭП: ПЭП по умолчанию; УНЭП/УКЭП — если выбрано в форме
        // (УКЭП при включённом сервисе → реальная подпись sc.ined.ru с .sig в журнале document_signatures).
        $pass = (string) $this->input('password');
        $type = strtoupper((string) ($this->input('sign_type') ?: 'PEP'));
        if (!in_array($type, ['PEP', 'UNEP', 'UKEP'], true)) { $type = 'PEP'; }
        $sPayload = json_encode(['memo' => (int) $id, 'period' => $memo['period'], 'dept' => $memo['department_id'], 'source' => $memo['source_id']], JSON_UNESCAPED_UNICODE);
        $desc = \App\Services\SignService::authAndSign('stimulus_memo', (int) $id, $uid, $type, $pass, $sPayload);
        if (!$desc['ok']) { flash($desc['error'], 'error'); $this->redirect('/memos/' . (int)$id); }
        $now = $desc['signed_at'];
        $sig = $desc['sign_hash'];        // отпечаток для штампа (заменяет makeSig)
        $signType = $desc['sign_type'];
        $record = function () use ($id, $uid, $desc) { \App\Services\SignService::recordSignature('stimulus_memo', (int) $id, $uid, $desc); };
        // Подпись «от имени директора»: ставит ТОЛЬКО админ; для УКЭП дата всегда фактическая (реальную подпись нельзя задним числом).
        $onBehalf = Auth::isAdmin();
        $dirAt    = ($onBehalf && $signType !== 'UKEP') ? $this->signAtInput($now) : $now;
        $dirSig   = ($dirAt === $now) ? $sig : self::makeSig((int) $id, $uid, $dirAt);
        [$dirName, $dirPos] = $onBehalf ? self::directorSigner() : ['', ''];

        // Повторный контроль бюджета источника при УТВЕРЖДЕНИИ (деньги могли «съесть» параллельно).
        $apprErr = null;
        if (($memo['kind'] ?? 'staff') !== 'mgmt' && !empty($memo['department_id']) && !empty($memo['source_id'])) {
            $memoSum = (float) Database::scalar(
                "SELECT COALESCE(SUM(COALESCE((SELECT o.new_amount FROM stimulus_overrides o WHERE o.memo_line_id=l.id ORDER BY o.id DESC LIMIT 1), l.amount)),0)
                   FROM stimulus_memo_lines l WHERE l.memo_id=?", [$id]);
            $av = \App\Services\StimulusBudgetService::availableForSource((int) $memo['department_id'], (int) $memo['source_id'], (string) $memo['period'], (int) $id);
            if ($av['has_budget'] && $memoSum > $av['available'] + 0.01) {
                $sn = (string) Database::scalar('SELECT name FROM pay_sources WHERE id=?', [(int) $memo['source_id']]);
                $ff = fn($v) => number_format(max(0, (float) $v), 2, ',', ' ');
                $apprErr = 'Нельзя утвердить: источник «' . $sn . '» выходит за бюджет (доступно ' . $ff($av['available']) . ' ₽, нужно ' . $ff($memoSum) . ' ₽). Снизьте суммы (корректировкой) или увеличьте бюджет ФОТ.';
            }
        }

        // Служебка директора (замам/гл. бухгалтеру) — одна подпись директора → сразу утверждена.
        if (($memo['kind'] ?? 'staff') === 'mgmt') {
            if (in_array($memo['status'], ['draft','revision'], true) && (!empty($roles['director']) || Auth::isAdmin())) {
                $num = $memo['number'] ?: self::nextNumber();
                Database::run('UPDATE stimulus_memos SET status=?, number=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=?, director_sign_name=?, director_sign_position=? WHERE id=?',
                    ['approved', $num, $uid, $signType, $dirAt, $dirSig, $dirName, $dirPos, $id]);
                $record();
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
                if ($apprErr) { flash($apprErr, 'error'); $this->redirect('/memos/' . (int)$id); }
                $num = $memo['number'] ?: self::nextNumber();
                Database::run('UPDATE stimulus_memos SET status=?, number=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=?, director_sign_name=?, director_sign_position=? WHERE id=?',
                    ['approved', $num, $uid, $signType, $dirAt, $dirSig, $dirName, $dirPos, $id]);
                $record();
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='accountant'") as $acc) {
                    NotificationService::create((int) $acc['id'], 'Стимул назначен директором', "Директор назначил стимул напрямую (№{$num}).");
                }
                flash("Стимул назначен напрямую и утверждён приказом директора (№{$num}).");
            } else { flash('Сейчас вы не можете подписать эту служебку.', 'error'); }
            $this->redirect('/memos/' . (int)$id);
        }
        if ($direct === 'deputy') {
            if ($memo['status'] === 'draft' && Auth::actsAsUser((int)$memo['author_id']) && (!empty($roles['deputy_director']) || Auth::isAdmin())) {
                $num = $memo['number'] ?: self::nextNumber();
                Database::run('UPDATE stimulus_memos SET status=?, number=?, deputy_id=?, deputy_sign_type=?, deputy_signed_at=?, deputy_sign_hash=? WHERE id=?',
                    ['deputy_signed', $num, $uid, $signType, $now, $sig, $id]);
                $record();
                foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='director'") as $dir) {
                    NotificationService::create((int) $dir['id'], 'Служебка на утверждение директором', "Зам назначил стимул напрямую (№{$num}), ожидает вашего утверждения.");
                }
                flash("Подписано заместителем напрямую (№{$num}). Ожидает утверждения директором.");
            } elseif ($memo['status'] === 'deputy_signed' && (!empty($roles['director']) || Auth::isAdmin())) {
                if ($apprErr) { flash($apprErr, 'error'); $this->redirect('/memos/' . (int)$id); }
                Database::run('UPDATE stimulus_memos SET status=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=?, director_sign_name=?, director_sign_position=? WHERE id=?',
                    ['approved', $uid, $signType, $dirAt, $dirSig, $dirName, $dirPos, $id]);
                $record();
                NotificationService::create((int)$memo['author_id'], 'Служебка утверждена', "Ваша прямая служебка о стимуле №{$memo['number']} утверждена директором.");
                flash('Стимул утверждён директором.');
            } else { flash('Сейчас вы не можете подписать эту служебку.', 'error'); }
            $this->redirect('/memos/' . (int)$id);
        }

        if ($memo['status'] === 'draft' && Auth::actsAsUser((int)$memo['author_id'])) {
            // начальник подписал → присвоить номер, отправить заму
            $num = $memo['number'] ?: self::nextNumber();
            Database::run('UPDATE stimulus_memos SET status=?, number=?, head_id=?, head_sign_type=?, head_signed_at=?, head_sign_hash=? WHERE id=?',
                ['head_signed', $num, $uid, $signType, $now, $sig, $id]);
            $record();
            // уведомить куратора
            if ($memo['curator_id']) {
                NotificationService::create((int)$memo['curator_id'], 'Служебка на утверждение', "Поступила служебка о стимуле №{$num} на ваше утверждение.");
            }
            flash("Служебка подписана и направлена курирующему заму (№{$num}).");
        } elseif ($memo['status'] === 'head_signed' && (!empty($roles['deputy_director']) && Auth::actsAsUser((int)$memo['curator_id']) || Auth::isAdmin())) {
            Database::run('UPDATE stimulus_memos SET status=?, deputy_id=?, deputy_sign_type=?, deputy_signed_at=?, deputy_sign_hash=? WHERE id=?',
                ['deputy_signed', $uid, $signType, $now, $sig, $id]);
            $record();
            // директору на утверждение + бухгалтерия уже видит
            foreach (Database::all("SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id WHERE r.role_slug='director'") as $dir) {
                NotificationService::create((int)$dir['id'], 'Служебка на утверждение директором', "Служебка о стимуле №{$memo['number']} утверждена замом, ожидает вашего решения.");
            }
            flash('Утверждено замом. Служебка направлена директору; бухгалтерия уже видит её.');
        } elseif ($memo['status'] === 'deputy_signed' && (!empty($roles['director']) || Auth::isAdmin())) {
            if ($apprErr) { flash($apprErr, 'error'); $this->redirect('/memos/' . (int)$id); }
            Database::run('UPDATE stimulus_memos SET status=?, director_id=?, director_sign_type=?, director_signed_at=?, director_sign_hash=?, director_sign_name=?, director_sign_position=? WHERE id=?',
                ['approved', $uid, $signType, $dirAt, $dirSig, $dirName, $dirPos, $id]);
            $record();
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

    /** Отклонить окончательно → в архив (отдельно от «вернуть на доработку»). Только для подписанной служебки. */
    public function rejectFinal(string $id): void
    {
        Auth::requireRole('deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$id]);
        if (!$memo) { $this->redirect('/memos'); }
        if (!in_array($memo['status'], ['head_signed', 'deputy_signed'], true)) {
            flash('Окончательно отклонить можно только подписанную служебку (на рассмотрении).', 'error');
            $this->redirect('/memos/' . (int) $id);
        }
        $reason = trim((string) $this->input('reason'));
        if ($reason === '') { flash('Укажите причину отклонения.', 'error'); $this->redirect('/memos/' . (int) $id); }
        Database::run('UPDATE stimulus_memos SET status=?, reject_reason=?, archived_at=?, archived_by=? WHERE id=?',
            ['rejected', $reason, date('Y-m-d H:i:s'), Auth::id(), $id]);
        NotificationService::create((int) $memo['author_id'], 'Служебка отклонена окончательно', "Служебка №{$memo['number']} отклонена и перенесена в архив: {$reason}");
        flash('Служебка отклонена окончательно и перенесена в архив.');
        $this->redirect('/memos?archive=1');
    }

    /** Вернуть служебку из архива на доработку (автор или админ). */
    public function unarchive(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$id]);
        if (!$memo) { $this->redirect('/memos?archive=1'); }
        if (!Auth::isAdmin() && (int) $memo['author_id'] !== (int) Auth::id()) { flash('Вернуть из архива может автор или администратор.', 'error'); $this->redirect('/memos?archive=1'); }
        Database::run('UPDATE stimulus_memos SET status=?, archived_at=NULL, archived_by=NULL WHERE id=?', ['revision', $id]);
        flash('Служебка возвращена из архива на доработку.');
        $this->redirect('/memos');
    }

    /** Удаление: не-админ — только свой черновик/доработку; админ — безвозвратно любую (вкл. подписанные/архив). */
    public function delete(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$id]);
        if (!$memo) { $this->redirect('/memos'); }
        $isAdmin = Auth::isAdmin();
        $wasArchived = $memo['archived_at'] !== null;
        if (!$isAdmin) {
            if ((int) $memo['author_id'] !== (int) Auth::id() || !in_array($memo['status'], ['draft', 'revision'], true) || $wasArchived) {
                flash('Удалить безвозвратно может только администратор. Свой черновик удаляет автор.', 'error');
                $this->redirect($wasArchived ? '/memos?archive=1' : '/memos');
            }
        }
        Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
        Database::run('DELETE FROM stimulus_memos WHERE id=?', [$id]);
        flash($isAdmin ? 'Служебка удалена безвозвратно.' : 'Черновик служебки удалён.');
        $this->redirect($wasArchived ? '/memos?archive=1' : '/memos');
    }

    /** Утверждённые служебки прошлого месяца, видимые пользователю (для переноса). */
    private function carrySource(int $uid, string $period): array
    {
        $rows = Database::all(
            "SELECT m.id, m.department_id, m.author_id, d.name AS dept_name
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
              WHERE m.period=? AND m.status='approved' AND (m.kind IS NULL OR m.kind='staff')
              ORDER BY d.name, m.id", [$period]);
        if ($this->seesAllStimulus()) { return $rows; }
        $scope = array_flip(Org::branchDeptIds($uid));
        return array_values(array_filter($rows, fn($m) =>
            (int) $m['author_id'] === $uid || (($m['department_id'] ?? 0) && isset($scope[(int) $m['department_id']]))));
    }

    /** Экран переноса выплат с прошлого месяца (справочно — суммы прошлого месяца по видам). */
    public function carry(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        $uid = (int) Auth::id();
        $cur = (string) $this->input('period', date('Y-m'));
        $prev = date('Y-m', strtotime($cur . '-01 -1 month'));
        $src = $this->carrySource($uid, $prev);
        $ids = array_map(fn($m) => (int) $m['id'], $src);
        $sum = ['monthly' => ['cnt' => 0, 'amt' => 0.0], 'onetime' => ['cnt' => 0, 'amt' => 0.0]];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT l.pay_kind AS lk, m.pay_kind AS mk, l.amount FROM stimulus_memo_lines l JOIN stimulus_memos m ON m.id=l.memo_id WHERE l.memo_id IN ($ph)", $ids) as $l) {
                $k = (($l['lk'] ?: $l['mk']) === 'onetime') ? 'onetime' : 'monthly';
                $sum[$k]['cnt']++; $sum[$k]['amt'] += (float) $l['amount'];
            }
        }
        $this->view('stimulus/carry', [
            'title' => 'Перенос выплат с прошлого месяца',
            'cur' => $cur, 'prev' => $prev, 'sum' => $sum, 'memos' => count($src),
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Перенести выплаты выбранного вида с прошлого месяца → черновики текущего (автор — текущий пользователь). */
    public function carryRun(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'admin');
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $kind = $this->input('kind') === 'onetime' ? 'onetime' : 'monthly';
        $cur = (string) $this->input('period', date('Y-m'));
        $prev = date('Y-m', strtotime($cur . '-01 -1 month'));
        $kindRu = $kind === 'onetime' ? 'единовременные' : 'ежемесячные';

        $src = $this->carrySource($uid, $prev);
        if (!$src) { flash("Нет утверждённых выплат за {$prev} для переноса.", 'error'); $this->redirect('/memos/carry?period=' . urlencode($cur)); }

        $pfrom = $cur . '-01'; $pto = date('Y-m-t', strtotime($pfrom));
        $pdo = Database::pdo(); $pdo->beginTransaction();
        $created = []; $batchId = null; $copied = 0; $skipped = 0;
        foreach ($src as $s) {
            $m = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [(int) $s['id']]);
            $lines = Database::all('SELECT * FROM stimulus_memo_lines WHERE memo_id=?', [(int) $s['id']]);
            $newLines = [];
            foreach ($lines as $ln) {
                $lk = (($ln['pay_kind'] ?: $m['pay_kind']) === 'onetime') ? 'onetime' : 'monthly';
                if ($lk !== $kind) { continue; }
                // дедуп: получатель уже имеет неотклонённую выплату этого вида за текущий месяц от этого автора
                $dup = Database::scalar(
                    "SELECT 1 FROM stimulus_memo_lines l JOIN stimulus_memos mm ON mm.id=l.memo_id
                      WHERE l.user_id=? AND mm.period=? AND mm.pay_kind=? AND mm.author_id=? AND mm.status<>'rejected' LIMIT 1",
                    [(int) $ln['user_id'], $cur, $kind, $uid]);
                if ($dup) { $skipped++; continue; }
                $newLines[] = $ln;
            }
            if (!$newLines) { continue; }
            $memoId = Database::insert(
                'INSERT INTO stimulus_memos (department_id, author_id, period, pay_kind, source_id, grounds, grounds_ids, kind, status, batch_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$m['department_id'], $uid, $cur, $kind, $m['source_id'], $m['grounds'], $m['grounds_ids'], 'staff', 'draft', null]);
            if ($batchId === null) { $batchId = $memoId; }
            foreach ($newLines as $ln) {
                Database::insert(
                    'INSERT INTO stimulus_memo_lines (memo_id, user_id, amount, pay_kind, period_from, period_to, oklad_load, percent, purpose, reason_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$memoId, $ln['user_id'], $ln['amount'], $kind, $pfrom, $pto, $ln['oklad_load'], $ln['percent'], $ln['purpose'] ?? 'other', $ln['reason_id'] ?? null]);
                $copied++;
            }
            $created[] = $memoId;
        }
        if ($created) {
            Database::run('UPDATE stimulus_memos SET batch_id=? WHERE id IN (' . implode(',', array_fill(0, count($created), '?')) . ')', array_merge([$batchId], $created));
        }
        $pdo->commit();
        if (!$created) {
            flash("Новых черновиков не создано: получатели уже имеют {$kindRu} выплаты за {$cur}" . ($skipped ? " (пропущено дублей {$skipped})" : '') . '.', 'error');
            $this->redirect('/memos/carry?period=' . urlencode($cur));
        }
        flash("Перенесены {$kindRu}: черновиков " . count($created) . ", строк {$copied}" . ($skipped ? ", пропущено дублей {$skipped}" : '') . '. Подпишите их для утверждения.');
        $this->redirect($batchId ? '/memos/batch/' . (int) $batchId : '/memos');
    }

    /** Видит ли пользователь весь стимул (директор/бухгалтер/менеджер финансов/админ). */
    private function seesAllStimulus(): bool
    {
        return Auth::has('director', 'accountant', 'finance_manager') || Auth::isAdmin();
    }

    /** Ветка видимости стимула: null = видит всё; иначе set id отделов (своё + ниже по структуре). */
    private function visibleDeptScope(int $uid): ?array
    {
        if ($this->seesAllStimulus()) { return null; }
        return array_flip(Org::branchDeptIds($uid));
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
        $scope = $seeAll ? null : array_flip(Org::branchDeptIds($uid));   // отделы своей ветки
        $canBase = Auth::has('director') || Auth::isAdmin();
        $out = [];
        foreach ($rows as $r) {
            // Строго по своей ветке: всё видят директор/бухгалтерия/фин-менеджер/админ; остальные (зам/начальник) —
            // только отделы своей ветки (свой отдел и ниже по структуре). Чужая ветка не видна, даже если сам автор/получатель.
            $dept = (int) ($r['department_id'] ?? 0);
            $visible = $seeAll || ($dept && isset($scope[$dept]));
            if (!$visible) { continue; }
            $oversee = Org::canOverseeUser($uid, (int) $r['user_id']);
            $r['effective'] = $r['ov_amount'] !== null ? (float) $r['ov_amount'] : (float) $r['amount'];
            $r['can_override'] = $r['status'] === 'approved' && ($canBase || $oversee);
            $out[] = $r;
        }
        return $out;
    }

    /** Сводная таблица: кто кому какой стимул назначил по месяцам (+ корректировки). */
    public function summary(): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'finance_manager', 'admin');
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
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'finance_manager', 'admin');
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
