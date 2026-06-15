<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\Settings;

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

    public function edit(string $id): void
    {
        Auth::requireRole('dept_head', 'director', 'admin');
        $memo = Database::one('SELECT * FROM stimulus_memos WHERE id = ?', [$id]);
        if (!$memo || ((int)$memo['author_id'] !== (int)Auth::id() && !Auth::isAdmin())) { $this->redirect('/memos'); }
        if (!in_array($memo['status'], ['draft', 'revision'], true)) { flash('Редактировать можно только черновик/на доработке.', 'error'); $this->redirect('/memos/' . (int)$id); }
        $this->memoForm($memo, (int)$memo['author_id'], $memo['kind'] ?? 'staff');
    }

    private function memoForm(?array $memo, int $authorId, string $kind = 'staff'): void
    {
        $kind = ($memo['kind'] ?? $kind) === 'mgmt' ? 'mgmt' : $kind;
        $period = (string) ($memo['period'] ?? date('Y-m'));
        $isMgmt = $kind === 'mgmt';

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
        Auth::requireRole('dept_head', 'director', 'admin');
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
            Database::run('UPDATE stimulus_memos SET department_id=?, period=?, pay_kind=?, source_id=?, grounds=?, grounds_ids=?, kind=?, status=? WHERE id=?',
                [$deptId, $period, $payKind, $sourceId, implode('; ', $groundTexts), implode(',', $groundIds), $kind, 'draft', $id]);
            Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
        } else {
            $id = Database::insert('INSERT INTO stimulus_memos (department_id, author_id, period, pay_kind, source_id, grounds, grounds_ids, kind, status) VALUES (?,?,?,?,?,?,?,?,?)',
                [$deptId, $uid, $period, $payKind, $sourceId, implode('; ', $groundTexts), implode(',', $groundIds), $kind, 'draft']);
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
        if (!($isAuthor || $isCurator || $isDirector || $isAcc || Auth::isAdmin())) {
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
        $canHeadSign = !$isMgmt && $isAuthor && $memo['status'] === 'draft';
        $canDeputySign = !$isMgmt && (($memo['status'] === 'head_signed' && (!empty($roles['deputy_director']) && ((int)$memo['curator_id']===$uid))) || ($memo['status']==='head_signed' && Auth::isAdmin()));
        $canDirectorSign = !$isMgmt && $memo['status'] === 'deputy_signed' && ($isDirector);
        $this->view('stimulus/show', [
            'title' => 'Служебка №' . ($memo['number'] ?: $memo['id']),
            'memo' => $memo, 'lines' => $lines, 'groundRows' => $groundRows,
            'kind' => $memo['kind'] ?? 'staff',
            'total' => array_sum(array_map(fn($l)=>(float)$l['amount'], $lines)),
            'source' => $memo['source_id'] ? Database::one('SELECT * FROM pay_sources WHERE id=?', [$memo['source_id']]) : null,
            'canEdit' => $isAuthor && in_array($memo['status'], ['draft','revision'], true),
            'canHeadSign' => $canHeadSign,
            'canDeputySign' => $canDeputySign,
            'canDirectorSign' => $canDirectorSign,
            'canMgmtSign' => $canMgmtSign,
            'canReject' => ($canDeputySign || $canDirectorSign),
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Сформированный документ служебки (А4) со штампами ЭП — печать → PDF. */
    public function printDoc(string $id): void
    {
        Auth::requireRole('dept_head', 'deputy_director', 'director', 'accountant', 'admin');
        $memo = Database::one(
            'SELECT m.*, d.name AS dept_name, d.curator_id, a.full_name AS author_name, a.position AS author_position
               FROM stimulus_memos m LEFT JOIN departments d ON d.id=m.department_id
               JOIN users a ON a.id=m.author_id WHERE m.id=?', [$id]);
        if (!$memo) { http_response_code(404); exit('Служебка не найдена'); }
        $lines = Database::all(
            'SELECT l.*, u.full_name, u.position FROM stimulus_memo_lines l JOIN users u ON u.id=l.user_id WHERE l.memo_id=? ORDER BY u.full_name', [$id]);
        $signers = [
            'head' => $memo['head_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['head_id']]) : null,
            'deputy' => $memo['deputy_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['deputy_id']]) : null,
            'director' => $memo['director_id'] ? Database::one('SELECT full_name, position FROM users WHERE id=?', [$memo['director_id']]) : null,
        ];
        $dirName = (string) Database::scalar("SELECT full_name FROM users WHERE id IN (SELECT user_id FROM user_roles WHERE role_slug='director') ORDER BY id LIMIT 1");
        // Полный перечень выбранных оснований с нормативными процентами (раздел 4).
        $gids = array_values(array_filter(array_map('intval', explode(',', (string) $memo['grounds_ids']))));
        $groundRows = $gids
            ? Database::all('SELECT text, category, percent FROM stimulus_grounds WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ') ORDER BY percent DESC, category', $gids)
            : array_map(fn($t) => ['text' => $t, 'category' => '', 'percent' => 0], array_values(array_filter(array_map('trim', explode(';', (string) $memo['grounds'])))));
        $this->view('stimulus/print', [
            'title' => 'Служебка №' . ($memo['number'] ?: $memo['id']),
            'memo' => $memo, 'lines' => $lines, 'signers' => $signers,
            'kind' => $memo['kind'] ?? 'staff',
            'source' => $memo['source_id'] ? Database::one('SELECT * FROM pay_sources WHERE id=?', [$memo['source_id']]) : null,
            'directorName' => $dirName ?: 'Д.Н. Семёнов',
            'grounds' => array_map(fn($g) => $g['text'], $groundRows),
            'groundRows' => $groundRows,
        ], false);
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

    private static function nextNumber(): string
    {
        $yy = date('y');
        $n = (int) Database::scalar("SELECT COUNT(*)+1 FROM stimulus_memos WHERE number LIKE ?", ['%/' . $yy]);
        return 'СТ-' . $n . '/' . $yy;
    }
}
