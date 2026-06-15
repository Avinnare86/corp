<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\NotificationService;

/**
 * СЭД: документы с маршрутом из этапов (по образцу СЭД «Практика»).
 * Этапы: approve (согласование) / sign (подписание) / ack (ознакомление).
 * Внутри этапа участники визируют последовательно или параллельно.
 * Рег. № присваивается после завершения последнего этапа согласования/подписания;
 * ознакомление идёт после и не блокирует регистрацию.
 * Поддерживается: гриф ДСП, связи «ответ на», отзыв автором, замещение,
 * шаблоны маршрутов, поручения (резолюции) по документу.
 */
class DocumentController extends Controller
{
    private const UPLOAD_DIR = __DIR__ . '/../../storage/uploads/docs';

    public const STATUS_LABEL = [
        'draft'       => 'Черновик',
        'on_approval' => 'На маршруте',
        'revision'    => 'На доработке',
        'approved'    => 'Согласован',
    ];
    public const STAGE_LABEL = ['approve' => 'Согласование', 'sign' => 'Подписание', 'ack' => 'Ознакомление'];

    // ---------- замещение ----------
    /** id пользователей, которых $uid замещает сегодня. */
    public static function actsFor(int $uid): array
    {
        $today = date('Y-m-d');
        return array_map(fn($r) => (int) $r['id'], Database::all(
            'SELECT id FROM users WHERE deputy_id = ? AND deputy_from <= ? AND deputy_to >= ?', [$uid, $today, $today]));
    }

    // ---------- права/видимость ----------
    private function canSee(array $doc, array $me): bool
    {
        $uid = (int) $me['id'];
        if ((int) $doc['author_id'] === $uid) { return true; }
        $participants = [$uid, ...self::actsFor($uid)];
        $ph = implode(',', array_fill(0, count($participants), '?'));
        if (Database::scalar("SELECT 1 FROM doc_approvers WHERE document_id = ? AND user_id IN ($ph)", [$doc['id'], ...$participants])) { return true; }
        if (Database::scalar('SELECT 1 FROM doc_readers WHERE document_id = ? AND user_id = ?', [$doc['id'], $uid])) { return true; } // явный читатель
        if (in_array($me['role'], ['admin', 'manager'], true)) { return true; }
        if ($doc['grif'] === 'ДСП') { return false; } // гриф сужает: руководителю отдела не виден
        $headDept = Database::scalar('SELECT id FROM departments WHERE head_id = ?', [$uid]);
        return $headDept && (int) $doc['department_id'] === (int) $headDept;
    }

    private function approverCandidates(): array
    {
        return Database::all(
            "SELECT u.id, u.full_name, u.position,
                    (SELECT d.name FROM departments d WHERE d.head_id = u.id LIMIT 1) AS heads
               FROM users u WHERE u.is_active = 1 ORDER BY u.full_name"
        );
    }

    /** Задач «мне» (текущий этап, с учётом замещения). */
    public static function inboxCount(int $uid): int
    {
        $ids = [$uid, ...self::actsFor($uid)];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return (int) Database::scalar(
            "SELECT COUNT(DISTINCT doc.id) FROM documents doc
               JOIN doc_approvers a ON a.document_id = doc.id AND a.step_no = doc.current_step
              WHERE doc.status = 'on_approval' AND a.status = 'pending' AND a.user_id IN ($ph)",
            $ids
        );
    }

    // ---------- реестр ----------
    public function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $folder = (string) $this->input('folder', 'inbox');
        $isPrivileged = in_array($me['role'], ['admin', 'manager'], true);
        $headDept = Database::scalar('SELECT id FROM departments WHERE head_id = ?', [$uid]);
        $actIds = [$uid, ...self::actsFor($uid)];
        $ph = implode(',', array_fill(0, count($actIds), '?'));

        $gc = Database::groupConcat('u2.full_name');
        $base = "SELECT doc.*, dt.name AS type_name, u.full_name AS author_name, dep.name AS dept_name,
                        (SELECT $gc FROM doc_approvers a2 JOIN users u2 ON u2.id = a2.user_id
                          WHERE a2.document_id = doc.id AND a2.step_no = doc.current_step AND a2.status='pending') AS current_name,
                        (SELECT a3.stage_type FROM doc_approvers a3 WHERE a3.document_id = doc.id AND a3.step_no = doc.current_step LIMIT 1) AS current_stage_type
                   FROM documents doc
                   JOIN doc_types dt ON dt.id = doc.type_id
                   JOIN users u ON u.id = doc.author_id
                   LEFT JOIN departments dep ON dep.id = doc.department_id";

        switch ($folder) {
            case 'inbox':
                $rows = Database::all("$base JOIN doc_approvers a ON a.document_id = doc.id AND a.step_no = doc.current_step
                    AND a.user_id IN ($ph) AND a.status='pending'
                    WHERE doc.status = 'on_approval' GROUP BY doc.id ORDER BY doc.sent_at DESC", $actIds);
                break;
            case 'drafts':
                $rows = Database::all("$base WHERE doc.author_id = ? AND doc.status IN ('draft','revision') ORDER BY doc.id DESC", [$uid]);
                break;
            case 'my':
                $rows = Database::all("$base WHERE doc.author_id = ? ORDER BY doc.id DESC", [$uid]);
                break;
            case 'participate':
                $rows = Database::all("$base WHERE doc.id IN (SELECT document_id FROM doc_approvers WHERE user_id = ?) ORDER BY doc.id DESC", [$uid]);
                break;
            case 'incoming': // входящие
                $rows = $isPrivileged
                    ? Database::all("$base WHERE doc.direction='incoming' ORDER BY doc.id DESC")
                    : Database::all("$base WHERE doc.direction='incoming' AND (doc.author_id=? OR doc.id IN (SELECT document_id FROM doc_approvers WHERE user_id=?)) ORDER BY doc.id DESC", [$uid, $uid]);
                break;
            case 'outgoing': // исходящие
                $rows = $isPrivileged
                    ? Database::all("$base WHERE doc.direction='outgoing' ORDER BY doc.id DESC")
                    : Database::all("$base WHERE doc.direction='outgoing' AND (doc.author_id=? OR doc.id IN (SELECT document_id FROM doc_approvers WHERE user_id=?)) ORDER BY doc.id DESC", [$uid, $uid]);
                break;
            case 'control': // документы на контроле
                $rows = $isPrivileged
                    ? Database::all("$base WHERE doc.on_control=1 ORDER BY doc.control_due IS NULL, doc.control_due, doc.id DESC")
                    : Database::all("$base WHERE doc.on_control=1 AND (doc.author_id=? OR doc.controller_id=? OR doc.id IN (SELECT document_id FROM doc_approvers WHERE user_id=?)) ORDER BY doc.control_due IS NULL, doc.control_due, doc.id DESC", [$uid, $uid, $uid]);
                break;
            case 'registered': // журнал регистрации
                if ($isPrivileged) { $rows = Database::all("$base WHERE doc.reg_number IS NOT NULL ORDER BY doc.finished_at DESC"); }
                else { $rows = Database::all("$base WHERE doc.reg_number IS NOT NULL AND (doc.author_id = ? OR doc.id IN (SELECT document_id FROM doc_approvers WHERE user_id = ?)) ORDER BY doc.finished_at DESC", [$uid, $uid]); }
                break;
            case 'dept':
                if (!$headDept && !$isPrivileged) { $rows = []; break; }
                $rows = ($isPrivileged && !$headDept)
                    ? Database::all("$base WHERE doc.grif <> 'ДСП' OR 1=1 ORDER BY doc.id DESC")
                    : Database::all("$base WHERE doc.department_id = ? AND doc.grif <> 'ДСП' ORDER BY doc.id DESC", [$headDept]);
                break;
            case 'all':
                $rows = $isPrivileged ? Database::all("$base ORDER BY doc.id DESC") : [];
                break;
            default:
                $rows = [];
        }

        // атрибутивный + полнотекстовый (по вложениям) поиск
        $q = mb_strtolower(trim((string) $this->input('q')));
        if ($q !== '') {
            $fileHits = array_map(fn($r) => (int) $r['document_id'], Database::all(
                'SELECT DISTINCT document_id FROM doc_files WHERE text_content LIKE ?', ['%' . $q . '%']));
            $rows = array_values(array_filter($rows, function ($r) use ($q, $fileHits) {
                if (in_array((int) $r['id'], $fileHits, true)) { return true; }
                return mb_strpos(mb_strtolower($r['title'] . ' ' . ($r['reg_number'] ?? '') . ' ' . $r['type_name'] . ' ' . $r['author_name'] . ' ' . ($r['body'] ?? '')), $q) !== false;
            }));
        }

        $counts = [
            'inbox'  => self::inboxCount($uid),
            'drafts' => (int) Database::scalar("SELECT COUNT(*) FROM documents WHERE author_id = ? AND status IN ('draft','revision')", [$uid]),
            'my'     => (int) Database::scalar('SELECT COUNT(*) FROM documents WHERE author_id = ?', [$uid]),
            'participate' => (int) Database::scalar('SELECT COUNT(DISTINCT document_id) FROM doc_approvers WHERE user_id = ?', [$uid]),
        ];

        $this->view('docs/index', [
            'title'   => 'Документы',
            'folder'  => $folder,
            'rows'    => $rows,
            'counts'  => $counts,
            'isHead'  => (bool) $headDept,
            'isPrivileged' => $isPrivileged,
            'q'       => (string) $this->input('q'),
        ]);
    }

    /** Печатный лист согласования. */
    public function sheet(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $doc = Database::one(
            "SELECT doc.*, dt.name AS type_name, u.full_name AS author_name, u.position AS author_position, dep.name AS dept_name
               FROM documents doc JOIN doc_types dt ON dt.id = doc.type_id JOIN users u ON u.id = doc.author_id
               LEFT JOIN departments dep ON dep.id = doc.department_id WHERE doc.id = ?", [$id]);
        if (!$doc || !$this->canSee($doc, $me)) { $this->redirect('/docs'); }
        $route = Database::all(
            'SELECT a.*, u.full_name, u.position, ob.full_name AS behalf_name
               FROM doc_approvers a JOIN users u ON u.id = a.user_id LEFT JOIN users ob ON ob.id = a.on_behalf_of
              WHERE a.document_id = ? ORDER BY a.step_no, a.id', [$id]);
        $this->view('docs/sheet', ['title' => 'Лист согласования', 'doc' => $doc, 'route' => $route], false);
    }

    // ---------- создание/редактирование ----------
    /** Разворачивает ролевую ячейку шаблона в конкретного сотрудника для текущего автора. */
    private function resolveRoleSlot(string $slot, array $me): ?array
    {
        switch ($slot) {
            case 'author':
                return ['id' => (int) $me['id'], 'full_name' => $me['full_name']];
            case 'author_head':
                $h = $me['department_id']
                    ? Database::one('SELECT u.id, u.full_name FROM departments d JOIN users u ON u.id = d.head_id WHERE d.id = ?', [$me['department_id']])
                    : null;
                return $h ?: null;
            case 'director':
                $d = Database::one("SELECT id, full_name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1");
                return $d ?: null;
        }
        return null;
    }

    public const ROLE_SLOTS = ['author_head' => 'Руководитель автора', 'director' => 'Директор', 'author' => 'Автор документа'];

    private function formData(?array $doc): array
    {
        $me = Auth::user();
        $route = $doc ? Database::all(
            'SELECT a.*, u.full_name FROM doc_approvers a JOIN users u ON u.id = a.user_id WHERE a.document_id = ? ORDER BY a.step_no, a.id',
            [$doc['id']]) : [];
        $templates = Database::all('SELECT * FROM route_templates ORDER BY name');
        $tplSteps = [];
        foreach (Database::all(
            'SELECT s.*, u.full_name FROM route_template_steps s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.template_id, s.step_no, s.id') as $st) {
            // ролевые ячейки разворачиваем под текущего автора
            if (!empty($st['role_slot'])) {
                $resolved = $this->resolveRoleSlot($st['role_slot'], $me);
                if (!$resolved) { continue; } // роль не разрешилась (нет руководителя) — пропускаем
                $st['user_id'] = $resolved['id'];
                $st['full_name'] = $resolved['full_name'] . ' (' . (self::ROLE_SLOTS[$st['role_slot']] ?? $st['role_slot']) . ')';
            }
            $tplSteps[$st['template_id']][] = $st;
        }
        $uid = (int) Auth::id();
        $linkable = Database::all(
            "SELECT id, reg_number, title FROM documents
              WHERE (author_id = ? OR id IN (SELECT document_id FROM doc_approvers WHERE user_id = ?)) AND id <> ?
              ORDER BY id DESC LIMIT 50", [$uid, $uid, $doc['id'] ?? 0]);
        return [
            'doc' => $doc,
            'route' => $route,
            'types' => Database::all('SELECT * FROM doc_types ORDER BY name'),
            'candidates' => $this->approverCandidates(),
            'templates' => $templates,
            'tplSteps' => $tplSteps,
            'linkable' => $linkable,
            'stageLabels' => self::STAGE_LABEL,
            'correspondents' => Database::all('SELECT id, name, kind FROM correspondents WHERE is_active = 1 ORDER BY name'),
        ];
    }

    /** Разобрать поля направления и корреспондента из формы (создаёт корреспондента при необходимости). */
    private function resolveCorrespondent(): array
    {
        $dir = in_array($this->input('direction'), ['incoming', 'outgoing', 'internal'], true) ? $this->input('direction') : 'internal';
        $cid = $this->input('correspondent_id') ? (int) $this->input('correspondent_id') : null;
        $cname = trim((string) $this->input('correspondent_name'));
        if ($dir === 'internal') { return ['direction' => 'internal', 'id' => null, 'name' => '', 'inc_no' => '', 'inc_date' => null, 'delivery' => '']; }
        // если выбран из справочника — берём его имя; если введён новый текст и нет id — создаём
        if ($cid) {
            $cname = (string) Database::scalar('SELECT name FROM correspondents WHERE id = ?', [$cid]) ?: $cname;
        } elseif ($cname !== '') {
            $cid = Database::insert('INSERT INTO correspondents (name, kind) VALUES (?,?)', [$cname, $this->input('corr_kind') ?: 'org']);
        }
        return [
            'direction' => $dir,
            'id' => $cid,
            'name' => $cname,
            'inc_no' => trim((string) $this->input('incoming_number')),
            'inc_date' => $this->input('incoming_date') ?: null,
            'delivery' => trim((string) $this->input('delivery')),
        ];
    }

    public function create(): void
    {
        Auth::requireLogin();
        $this->view('docs/form', array_merge(['title' => 'Новый документ'], $this->formData(null)));
    }

    public function store(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $title = trim((string) $this->input('title'));
        $typeId = (int) $this->input('type_id');
        if ($title === '' || !$typeId) { flash('Укажите тип и заголовок.', 'error'); $this->redirect('/docs/create'); }
        $c = $this->resolveCorrespondent();
        $docId = Database::insert(
            'INSERT INTO documents (type_id, title, body, author_id, department_id, grif, reply_to_id, direction, correspondent_id, correspondent_name, incoming_number, incoming_date, delivery)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$typeId, $title, (string) $this->input('body'), $me['id'], $me['department_id'] ?: null,
             $this->input('grif') === 'ДСП' ? 'ДСП' : '', $this->input('reply_to_id') ?: null,
             $c['direction'], $c['id'], $c['name'], $c['inc_no'], $c['inc_date'], $c['delivery']]
        );
        $this->saveFile($docId);
        $this->saveRoute($docId);
        $this->history($docId, 'Документ создан');
        if ($this->input('action') === 'send') { $this->doSend($docId); }
        else { flash('Черновик сохранён.'); }
        $this->redirect('/docs/' . $docId);
    }

    public function edit(string $id): void
    {
        Auth::requireLogin();
        $doc = $this->authorEditable($id);
        $this->view('docs/form', array_merge(['title' => 'Редактирование документа'], $this->formData($doc)));
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $this->authorEditable($id);
        $c = $this->resolveCorrespondent();
        Database::run(
            'UPDATE documents SET type_id=?, title=?, body=?, grif=?, reply_to_id=?, direction=?, correspondent_id=?, correspondent_name=?, incoming_number=?, incoming_date=?, delivery=? WHERE id=?',
            [(int) $this->input('type_id'), trim((string) $this->input('title')), (string) $this->input('body'),
             $this->input('grif') === 'ДСП' ? 'ДСП' : '', $this->input('reply_to_id') ?: null,
             $c['direction'], $c['id'], $c['name'], $c['inc_no'], $c['inc_date'], $c['delivery'], $id]
        );
        $this->saveFile((int) $id);
        $this->saveRoute((int) $id);
        $this->history((int) $id, 'Документ изменён');
        if ($this->input('action') === 'send') { $this->doSend((int) $id); }
        else { flash('Сохранено.'); }
        $this->redirect('/docs/' . $id);
    }

    public function send(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $this->authorEditable($id);
        $this->doSend((int) $id);
        $this->redirect('/docs/' . $id);
    }

    /** Отзыв документа автором с маршрута. */
    public function recall(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if ($doc && (int) $doc['author_id'] === (int) Auth::id() && $doc['status'] === 'on_approval') {
            Database::run("UPDATE documents SET status='revision' WHERE id=?", [$id]);
            $this->history((int) $id, 'Отозван автором с маршрута');
            flash('Документ отозван на доработку.');
        }
        $this->redirect('/docs/' . $id);
    }

    public function destroy(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $this->authorEditable($id);
        Database::run('DELETE FROM doc_approvers WHERE document_id = ?', [$id]);
        Database::run('DELETE FROM doc_history WHERE document_id = ?', [$id]);
        Database::run('DELETE FROM documents WHERE id = ?', [$id]);
        flash('Документ удалён.');
        $this->redirect('/docs?folder=drafts');
    }

    // ---------- карточка ----------
    public function show(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $doc = Database::one(
            "SELECT doc.*, dt.name AS type_name, u.full_name AS author_name, u.position AS author_position, dep.name AS dept_name,
                    r.reg_number AS reply_reg, r.title AS reply_title
               FROM documents doc
               JOIN doc_types dt ON dt.id = doc.type_id
               JOIN users u ON u.id = doc.author_id
               LEFT JOIN departments dep ON dep.id = doc.department_id
               LEFT JOIN documents r ON r.id = doc.reply_to_id
              WHERE doc.id = ?", [$id]);
        if (!$doc || !$this->canSee($doc, $me)) {
            http_response_code(403);
            echo \App\Core\View::render('errors/403', ['title' => 'Нет доступа']);
            exit;
        }
        $route = Database::all(
            'SELECT a.*, u.full_name, u.position, ob.full_name AS behalf_name
               FROM doc_approvers a JOIN users u ON u.id = a.user_id LEFT JOIN users ob ON ob.id = a.on_behalf_of
              WHERE a.document_id = ? ORDER BY a.step_no, a.id', [$id]);
        $history = Database::all('SELECT * FROM doc_history WHERE document_id = ? ORDER BY id', [$id]);

        // моя ли очередь (с учётом замещения и параллельности)
        $uid = (int) $me['id'];
        $actIds = [$uid, ...self::actsFor($uid)];
        $myTurn = null;
        foreach ($route as $r) {
            if ((int) $r['step_no'] === (int) $doc['current_step'] && $doc['status'] === 'on_approval'
                && $r['status'] === 'pending' && in_array((int) $r['user_id'], $actIds, true)) {
                $myTurn = $r; break;
            }
        }
        $replies = Database::all('SELECT id, reg_number, title FROM documents WHERE reply_to_id = ?', [$id]);
        $docOrders = Database::all(
            'SELECT o.*, ua.full_name AS author_name, ue.full_name AS assignee_name FROM orders o
               JOIN users ua ON ua.id=o.author_id JOIN users ue ON ue.id=o.assignee_id WHERE o.doc_id = ? ORDER BY o.id DESC', [$id]);
        $isBoss = in_array($me['role'], ['admin', 'manager'], true) || Database::scalar('SELECT 1 FROM departments WHERE head_id = ?', [$uid]);

        $this->view('docs/show', [
            'title'   => $doc['title'],
            'doc'     => $doc,
            'route'   => $route,
            'history' => $history,
            'myTurn'  => $myTurn,
            'isAuthor'=> (int) $doc['author_id'] === $uid,
            'replies' => $replies,
            'docOrders' => $docOrders,
            'isBoss'  => (bool) $isBoss,
            'allUsers'=> Database::all('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name'),
            'files'   => Database::all('SELECT * FROM doc_files WHERE document_id = ? ORDER BY version DESC', [$id]),
            'readers' => Database::all('SELECT r.*, u.full_name FROM doc_readers r JOIN users u ON u.id = r.user_id WHERE r.document_id = ?', [$id]),
            'canManageReaders' => (int) $doc['author_id'] === $uid || in_array($me['role'], ['admin', 'manager'], true),
            'canFile' => in_array($me['role'], ['admin', 'manager'], true) || \App\Core\Auth::has('hr') || (int) $doc['author_id'] === $uid,
            'cases' => Database::all("SELECT id, index_code, title FROM nomenclature_cases WHERE status='open' ORDER BY index_code"),
            'caseInfo' => $doc['case_id'] ? Database::one('SELECT index_code, title FROM nomenclature_cases WHERE id=?', [$doc['case_id']]) : null,
        ]);
    }

    /** Виза текущего участника (или заместителя). */
    public function decide(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc || $doc['status'] !== 'on_approval') { $this->redirect('/docs/' . $id); }
        $actIds = [$uid, ...self::actsFor($uid)];
        $ph = implode(',', array_fill(0, count($actIds), '?'));
        $step = Database::one(
            "SELECT * FROM doc_approvers WHERE document_id = ? AND step_no = ? AND status = 'pending' AND user_id IN ($ph) LIMIT 1",
            [$id, $doc['current_step'], ...$actIds]);
        if (!$step) { flash('Сейчас не ваша очередь.', 'error'); $this->redirect('/docs/' . $id); }

        // последовательный этап: визирует только первый pending
        if (!(int) $step['parallel']) {
            $firstPending = Database::one(
                "SELECT * FROM doc_approvers WHERE document_id=? AND step_no=? AND status='pending' ORDER BY id LIMIT 1",
                [$id, $doc['current_step']]);
            if ((int) $firstPending['id'] !== (int) $step['id']) {
                flash('Этап последовательный — очередь ещё не дошла.', 'error');
                $this->redirect('/docs/' . $id);
            }
        }

        $verdict = (string) $this->input('verdict');
        $comment = trim((string) $this->input('comment'));
        $now = date('Y-m-d H:i:s');
        $onBehalf = (int) $step['user_id'] !== $uid ? (int) $step['user_id'] : null;
        $signerName = $me['full_name'] . ($onBehalf ? ' (за ' . Database::scalar('SELECT full_name FROM users WHERE id=?', [$onBehalf]) . ')' : '');

        if ($verdict === 'reject' && $step['stage_type'] !== 'ack') {
            if ($comment === '') { flash('При отклонении комментарий обязателен.', 'error'); $this->redirect('/docs/' . $id); }
            Database::run('UPDATE doc_approvers SET status=?, comment=?, decided_at=?, on_behalf_of=?, file_version=? WHERE id=?',
                ['rejected', $comment, $now, $onBehalf ? $uid : null, self::currentVersion((int) $id) ?: null, $step['id']]);
            Database::run("UPDATE documents SET status='revision' WHERE id=?", [$id]);
            $this->history((int) $id, (self::STAGE_LABEL[$step['stage_type']] ?? '') . ': отклонено — ' . $signerName, $comment);
            NotificationService::create((int) $doc['author_id'], 'Документ отклонён', "«{$doc['title']}»: {$signerName} — {$comment}");
            flash('Документ возвращён автору на доработку.');
            $this->redirect('/docs/' . $id);
        }

        // «Не в моей компетенции»: этап пропускается без влияния на результат (только согласование)
        if ($verdict === 'incompetent' && $step['stage_type'] === 'approve') {
            Database::run('UPDATE doc_approvers SET status=?, comment=?, decided_at=?, on_behalf_of=? WHERE id=?',
                ['skipped', $comment ?: 'Не в моей компетенции', $now, $onBehalf ? $uid : null, $step['id']]);
            $this->history((int) $id, 'Согласование: не в моей компетенции — ' . $signerName, $comment ?: null);
            $this->advance($doc, (int) $doc['current_step']);
            flash('Отмечено: не в вашей компетенции, этап продолжен без вашей визы.');
            $this->redirect('/docs/' . $id);
        }

        $withRemarks = $verdict === 'approve_rem';
        if ($withRemarks && $comment === '') {
            flash('Для «согласовано с замечаниями» укажите замечания в комментарии.', 'error');
            $this->redirect('/docs/' . $id);
        }
        $okStatus = $step['stage_type'] === 'ack' ? 'acked' : 'approved';
        if ($withRemarks) { $comment = '[С замечаниями] ' . $comment; }
        Database::run('UPDATE doc_approvers SET status=?, comment=?, decided_at=?, on_behalf_of=?, file_version=? WHERE id=?',
            [$okStatus, $comment ?: null, $now, $onBehalf ? $uid : null, self::currentVersion((int) $id) ?: null, $step['id']]);
        $verb = $withRemarks ? 'согласовано с замечаниями'
            : (['approve' => 'согласовано', 'sign' => 'подписано', 'ack' => 'ознакомлен'][$step['stage_type']] ?? 'выполнено');
        $this->history((int) $id, (self::STAGE_LABEL[$step['stage_type']] ?? '') . ': ' . $verb . ' — ' . $signerName, $comment ?: null);

        $this->advance($doc, (int) $doc['current_step']);
        flash('Готово: ' . $verb . '.');
        $this->redirect('/docs/' . $id);
    }

    /** Продвижение маршрута: завершение этапа → следующий этап / финал. */
    private function advance(array $doc, int $stepNo): void
    {
        $id = (int) $doc['id'];
        $pendingHere = (int) Database::scalar(
            "SELECT COUNT(*) FROM doc_approvers WHERE document_id=? AND step_no=? AND status='pending'", [$id, $stepNo]);
        if ($pendingHere > 0) {
            // последовательный этап: уведомить следующего
            $next = Database::one("SELECT * FROM doc_approvers WHERE document_id=? AND step_no=? AND status='pending' ORDER BY id LIMIT 1", [$id, $stepNo]);
            if ($next && !(int) $next['parallel']) {
                NotificationService::create((int) $next['user_id'], 'Документ ожидает вас', "«{$doc['title']}» — ваша очередь на этапе.");
            }
            return;
        }
        // этап завершён; рег.№ — если дальше только ознакомление (или ничего)
        $nextStage = Database::one('SELECT * FROM doc_approvers WHERE document_id=? AND step_no=? ORDER BY id LIMIT 1', [$id, $stepNo + 1]);
        $remainNonAck = (int) Database::scalar(
            "SELECT COUNT(*) FROM doc_approvers WHERE document_id=? AND step_no > ? AND stage_type <> 'ack'", [$id, $stepNo]);
        $fresh = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$fresh['reg_number'] && $remainNonAck === 0) {
            $reg = $this->assignRegNumber($fresh);
            Database::run('UPDATE documents SET reg_number=? WHERE id=?', [$reg, $id]);
            $this->history($id, "Зарегистрирован, № {$reg}");
            NotificationService::create((int) $doc['author_id'], 'Документ зарегистрирован', "«{$doc['title']}» — рег. № {$reg}.");
        }
        if ($nextStage) {
            Database::run('UPDATE documents SET current_step=? WHERE id=?', [$stepNo + 1, $id]);
            // уведомления участникам нового этапа (параллельный — всем, последовательный — первому)
            $targets = (int) $nextStage['parallel']
                ? Database::all("SELECT user_id FROM doc_approvers WHERE document_id=? AND step_no=? AND status='pending'", [$id, $stepNo + 1])
                : [['user_id' => $nextStage['user_id']]];
            $label = self::STAGE_LABEL[$nextStage['stage_type']] ?? 'Этап';
            foreach ($targets as $t2) {
                NotificationService::create((int) $t2['user_id'], "Документ: {$label}", "«{$doc['title']}» ожидает вас.");
            }
        } else {
            Database::run("UPDATE documents SET status='approved', finished_at=? WHERE id=?", [date('Y-m-d H:i:s'), $id]);
            $this->history($id, 'Маршрут завершён');
            NotificationService::create((int) $doc['author_id'], 'Маршрут завершён', "«{$doc['title']}» прошёл все этапы.");
        }
    }

    /** Поручение (резолюция) по документу. */
    public function order(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc || !$this->canSee($doc, $me)) { $this->redirect('/docs'); }
        $isBoss = in_array($me['role'], ['admin', 'manager'], true) || Database::scalar('SELECT 1 FROM departments WHERE head_id = ?', [$me['id']]);
        if (!$isBoss) { flash('Резолюции дают руководители.', 'error'); $this->redirect('/docs/' . $id); }
        $assignee = (int) $this->input('assignee_id');
        $text = trim((string) $this->input('title'));
        if (!$assignee || $text === '') { flash('Укажите исполнителя и текст резолюции.', 'error'); $this->redirect('/docs/' . $id); }
        Database::insert('INSERT INTO orders (author_id, assignee_id, title, body, due_date, doc_id) VALUES (?,?,?,?,?,?)',
            [$me['id'], $assignee, $text, 'Резолюция по документу «' . $doc['title'] . '»' . ($doc['reg_number'] ? " (№ {$doc['reg_number']})" : ''), $this->input('due_date') ?: null, $id]);
        NotificationService::create($assignee, 'Поручение по документу', "«{$text}» — документ «{$doc['title']}»");
        $this->history((int) $id, 'Резолюция: ' . $text . ' → ' . Database::scalar('SELECT full_name FROM users WHERE id=?', [$assignee]));
        flash('Поручение по документу дано.');
        $this->redirect('/docs/' . $id);
    }

    // ---------- шаблоны маршрутов ----------
    /** Замещение в СЭД: кто кого замещает и на какой срок (раздел Документы). */
    public function deputies(): void
    {
        Auth::requireRole('admin', 'docs_manager');
        $rows = Database::all(
            "SELECT u.id, u.full_name, u.position, u.deputy_id, u.deputy_from, u.deputy_to,
                    du.full_name AS deputy_name
               FROM users u LEFT JOIN users du ON du.id = u.deputy_id
              WHERE u.is_active = 1 AND u.deputy_id IS NOT NULL
              ORDER BY u.full_name");
        $this->view('docs/deputies', [
            'title' => 'Замещение в СЭД',
            'rows' => $rows,
            'users' => Database::all('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name'),
            'csrf' => Auth::csrf(),
        ]);
    }

    public function saveDeputies(): void
    {
        Auth::requireRole('admin', 'docs_manager');
        Auth::verifyCsrf();
        $userId = (int) $this->input('user_id');
        if (!$userId) { $this->redirect('/docs/deputies'); }
        $deputyId = $this->input('deputy_id') ? (int) $this->input('deputy_id') : null;
        if ($deputyId === $userId) { $deputyId = null; }
        Database::run('UPDATE users SET deputy_id=?, deputy_from=?, deputy_to=? WHERE id=?', [
            $deputyId,
            $deputyId ? ($this->input('deputy_from') ?: null) : null,
            $deputyId ? ($this->input('deputy_to') ?: null) : null,
            $userId,
        ]);
        flash($deputyId ? 'Замещение в СЭД сохранено.' : 'Замещение снято.');
        $this->redirect('/docs/deputies');
    }

    public function templates(): void
    {
        Auth::requireRole('admin', 'manager');
        $templates = Database::all('SELECT * FROM route_templates ORDER BY name');
        $steps = [];
        foreach (Database::all('SELECT s.*, u.full_name FROM route_template_steps s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.template_id, s.step_no, s.id') as $st) {
            if (!$st['full_name'] && $st['role_slot']) { $st['full_name'] = '[Роль] ' . (self::ROLE_SLOTS[$st['role_slot']] ?? $st['role_slot']); }
            $steps[$st['template_id']][] = $st;
        }
        $this->view('docs/templates', [
            'title' => 'Шаблоны маршрутов',
            'templates' => $templates,
            'steps' => $steps,
            'candidates' => $this->approverCandidates(),
            'stageLabels' => self::STAGE_LABEL,
        ]);
    }

    public function storeTemplate(): void
    {
        Auth::requireRole('admin', 'manager');
        Auth::verifyCsrf();
        $name = trim((string) $this->input('name'));
        if ($name === '') { flash('Укажите название шаблона.', 'error'); $this->redirect('/docs/templates'); }
        $tid = Database::insert('INSERT INTO route_templates (name) VALUES (?)', [$name]);
        $this->persistStages('route_template_steps', 'template_id', $tid);
        flash('Шаблон сохранён.');
        $this->redirect('/docs/templates');
    }

    public function deleteTemplate(string $id): void
    {
        Auth::requireRole('admin', 'manager');
        Auth::verifyCsrf();
        Database::run('DELETE FROM route_template_steps WHERE template_id = ?', [$id]);
        Database::run('DELETE FROM route_templates WHERE id = ?', [$id]);
        flash('Шаблон удалён.');
        $this->redirect('/docs/templates');
    }

    /** Скачивание вложения: текущая или конкретная версия (?v=N). */
    public function file(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc || !$this->canSee($doc, $me)) { http_response_code(404); exit('Нет файла'); }
        $v = (int) $this->input('v');
        $f = $v
            ? Database::one('SELECT * FROM doc_files WHERE document_id = ? AND version = ?', [$id, $v])
            : Database::one('SELECT * FROM doc_files WHERE document_id = ? ORDER BY version DESC LIMIT 1', [$id]);
        if (!$f) { http_response_code(404); exit('Нет файла'); }
        $path = self::UPLOAD_DIR . '/' . $f['stored_name'];
        if (!is_file($path)) { http_response_code(404); exit('Файл не найден'); }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($f['orig_name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /** Поставить документ на контроль / снять (привилегированные или рук. подразделения). */
    public function control(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) { $this->redirect('/docs'); }
        $can = in_array($me['role'], ['admin', 'manager'], true)
            || (int) $doc['author_id'] === (int) $me['id']
            || ($me['department_id'] && (int) $doc['department_id'] === (int) $me['department_id']
                && Database::scalar('SELECT 1 FROM departments WHERE id=? AND head_id=?', [$doc['department_id'], $me['id']]));
        if (!$can) { flash('Нет прав ставить документ на контроль.', 'error'); $this->redirect('/docs/' . (int)$id); }

        if ($this->input('off')) {
            Database::run('UPDATE documents SET on_control=0, control_off_at=? WHERE id=?', [date('Y-m-d H:i:s'), $id]);
            $this->history((int)$id, 'Снято с контроля');
            flash('Документ снят с контроля.');
        } else {
            $due = $this->input('control_due') ?: null;
            Database::run('UPDATE documents SET on_control=1, control_due=?, controller_id=?, control_off_at=NULL WHERE id=?', [$due, $me['id'], $id]);
            $this->history((int)$id, 'Поставлено на контроль' . ($due ? ' (срок ' . $due . ')' : ''));
            flash('Документ поставлен на контроль.');
        }
        $this->redirect('/docs/' . (int)$id);
    }

    /** Регистрационная карточка (РК) документа со штрихкодом — печать. */
    public function card(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $doc = Database::one(
            "SELECT doc.*, dt.name AS type_name, u.full_name AS author_name, dep.name AS dept_name, c.index_code AS case_idx, c.title AS case_title
               FROM documents doc JOIN doc_types dt ON dt.id=doc.type_id JOIN users u ON u.id=doc.author_id
               LEFT JOIN departments dep ON dep.id=doc.department_id LEFT JOIN nomenclature_cases c ON c.id=doc.case_id WHERE doc.id=?", [$id]);
        if (!$doc || !$this->canSee($doc, $me)) { http_response_code(404); exit('Нет доступа'); }
        $route = Database::all(
            'SELECT a.*, u.full_name FROM doc_approvers a JOIN users u ON u.id=a.user_id WHERE a.document_id=? ORDER BY a.step_no, a.id', [$id]);
        // штрихкод кодирует числовой id; код для печати — рег.№ либо BC-<id>
        $barcode = \App\Services\Barcode::code39svg('DOC' . str_pad((string) $doc['id'], 6, '0', STR_PAD_LEFT));
        $this->view('docs/card', ['title' => 'Рег. карточка', 'doc' => $doc, 'route' => $route, 'barcode' => $barcode], false);
    }

    /** Реестр передачи документов (печать) — по направлению и периоду, с колонками для подписи. */
    public function register(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!in_array($me['role'], ['admin', 'manager'], true) && !\App\Core\Auth::has('hr')) {
            flash('Реестр доступен делопроизводству.', 'error'); $this->redirect('/docs');
        }
        $dir = in_array($this->input('direction'), ['incoming', 'outgoing', 'internal'], true) ? $this->input('direction') : 'outgoing';
        $from = $this->input('from') ?: date('Y-m-01');
        $to = $this->input('to') ?: date('Y-m-d');
        $rows = Database::all(
            "SELECT doc.id, doc.reg_number, doc.title, doc.correspondent_name, doc.created_at, dt.name AS type_name
               FROM documents doc JOIN doc_types dt ON dt.id=doc.type_id
              WHERE doc.direction=? AND substr(COALESCE(doc.sent_at, doc.created_at),1,10) BETWEEN ? AND ?
              ORDER BY doc.reg_number, doc.id", [$dir, $from, $to]);
        $this->view('docs/register', [
            'title' => 'Реестр передачи',
            'rows' => $rows, 'direction' => $dir, 'from' => $from, 'to' => $to,
            'dirLabel' => ['incoming' => 'входящих', 'outgoing' => 'исходящих', 'internal' => 'внутренних'][$dir],
        ], false);
    }

    /** Списать документ в дело номенклатуры (или снять списание). */
    public function fileCase(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) { $this->redirect('/docs'); }
        $can = in_array($me['role'], ['admin', 'manager'], true) || \App\Core\Auth::has('hr')
            || (int) $doc['author_id'] === (int) $me['id'];
        if (!$can) { flash('Нет прав списывать в дело.', 'error'); $this->redirect('/docs/' . (int)$id); }

        if ($this->input('unfile')) {
            Database::run('UPDATE documents SET case_id=NULL, filed_at=NULL, filed_by=NULL WHERE id=?', [$id]);
            $this->history((int)$id, 'Снято из дела');
            flash('Документ изъят из дела.');
        } else {
            $caseId = (int) $this->input('case_id');
            $case = $caseId ? Database::one('SELECT * FROM nomenclature_cases WHERE id=?', [$caseId]) : null;
            if (!$case) { flash('Выберите дело.', 'error'); $this->redirect('/docs/' . (int)$id); }
            Database::run('UPDATE documents SET case_id=?, filed_at=?, filed_by=? WHERE id=?', [$caseId, date('Y-m-d H:i:s'), $me['id'], $id]);
            $this->history((int)$id, 'Списано в дело ' . $case['index_code'] . ' «' . $case['title'] . '»');
            flash('Документ списан в дело ' . $case['index_code'] . '.');
        }
        $this->redirect('/docs/' . (int)$id);
    }

    /** Образ документа: вложение как PDF прямо на странице (конвертация Word/Excel через Office). */
    public function preview(string $id): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc || !$this->canSee($doc, $me)) { http_response_code(404); exit('Нет доступа'); }
        $v = (int) $this->input('v');
        $f = $v
            ? Database::one('SELECT * FROM doc_files WHERE document_id = ? AND version = ?', [$id, $v])
            : Database::one('SELECT * FROM doc_files WHERE document_id = ? ORDER BY version DESC LIMIT 1', [$id]);
        if (!$f) { http_response_code(404); exit('Нет вложения'); }

        $ext = strtolower(pathinfo((string) $f['orig_name'], PATHINFO_EXTENSION));
        $path = self::UPLOAD_DIR . '/' . $f['stored_name'];

        // картинки показываем как есть
        if (in_array($ext, \App\Services\PdfPreview::IMAGE_EXT, true) && is_file($path)) {
            $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'][$ext];
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }

        $pdf = \App\Services\PdfPreview::ensure($f, self::UPLOAD_DIR);
        if ($pdf) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . rawurlencode(pathinfo($f['orig_name'], PATHINFO_FILENAME)) . '.pdf"');
            header('Content-Length: ' . filesize($pdf));
            readfile($pdf);
            exit;
        }

        // фолбэк: формат не поддержан или Office недоступен
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;color:#555;display:flex;align-items:center;justify-content:center;height:90vh;text-align:center">'
            . '<div>Предпросмотр для формата <b>.' . e($ext) . '</b> недоступен.<br><br>'
            . '<a href="/docs/' . (int) $id . '/file?v=' . (int) $f['version'] . '">⬇ Скачать файл ' . e($f['orig_name']) . '</a></div></body></html>';
        exit;
    }

    /** Читатели ДСП: автор или admin/manager добавляет/удаляет явных читателей. */
    public function readers(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        $can = $doc && ((int) $doc['author_id'] === (int) $me['id'] || in_array($me['role'], ['admin', 'manager'], true));
        if (!$can) { flash('Читателей управляет автор или администратор.', 'error'); $this->redirect('/docs/' . $id); }
        if ($this->input('remove')) {
            Database::run('DELETE FROM doc_readers WHERE document_id = ? AND user_id = ?', [$id, (int) $this->input('remove')]);
            flash('Читатель удалён.');
        } else {
            $uid2 = (int) $this->input('user_id');
            if ($uid2 && Database::scalar('SELECT 1 FROM users WHERE id=? AND is_active=1', [$uid2])
                && !Database::scalar('SELECT 1 FROM doc_readers WHERE document_id=? AND user_id=?', [$id, $uid2])) {
                Database::insert('INSERT INTO doc_readers (document_id, user_id) VALUES (?,?)', [$id, $uid2]);
                $this->history((int) $id, 'Добавлен читатель: ' . Database::scalar('SELECT full_name FROM users WHERE id=?', [$uid2]));
                NotificationService::create($uid2, 'Вам открыт доступ к документу', "«{$doc['title']}»");
                flash('Читатель добавлен.');
            }
        }
        $this->redirect('/docs/' . $id);
    }

    /** Переадресация своей текущей задачи другому сотруднику. */
    public function redirectTask(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $uid = (int) $me['id'];
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc || $doc['status'] !== 'on_approval') { $this->redirect('/docs/' . $id); }
        $actIds = [$uid, ...self::actsFor($uid)];
        $ph = implode(',', array_fill(0, count($actIds), '?'));
        $step = Database::one(
            "SELECT * FROM doc_approvers WHERE document_id=? AND step_no=? AND status='pending' AND user_id IN ($ph) LIMIT 1",
            [$id, $doc['current_step'], ...$actIds]);
        $to = (int) $this->input('to_user_id');
        if (!$step || !$to || !Database::scalar('SELECT 1 FROM users WHERE id=? AND is_active=1', [$to])) {
            flash('Переадресация недоступна.', 'error');
            $this->redirect('/docs/' . $id);
        }
        $toName = Database::scalar('SELECT full_name FROM users WHERE id = ?', [$to]);
        Database::run('UPDATE doc_approvers SET user_id = ? WHERE id = ?', [$to, $step['id']]);
        $this->history((int) $id, "Задача переадресована: {$me['full_name']} → {$toName}");
        NotificationService::create($to, 'Вам переадресована задача', "«{$doc['title']}» — " . (self::STAGE_LABEL[$step['stage_type']] ?? 'этап') . '.');
        flash('Задача переадресована.');
        $this->redirect('/docs/' . $id);
    }

    // ---------- helpers ----------
    private function authorEditable(string $id): array
    {
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc || (int) $doc['author_id'] !== (int) Auth::id() || !in_array($doc['status'], ['draft', 'revision'], true)) {
            flash('Документ недоступен для редактирования.', 'error');
            $this->redirect('/docs');
        }
        return $doc;
    }

    /** Сохранение маршрута из конструктора stages[N][type|parallel|users[]]. */
    private function saveRoute(int $docId): void
    {
        Database::run('DELETE FROM doc_approvers WHERE document_id = ?', [$docId]);
        $this->persistStages('doc_approvers', 'document_id', $docId);
    }

    private function persistStages(string $table, string $fk, int $fkVal): void
    {
        $allowRoles = $table === 'route_template_steps';
        $stages = $_POST['stages'] ?? [];
        ksort($stages, SORT_NUMERIC); // порядок этапов — строго по индексу конструктора
        $stepNo = 0;
        foreach ($stages as $stage) {
            $raw = array_values(array_filter(array_map('strval', $stage['users'] ?? [])));
            $members = [];
            foreach ($raw as $v) {
                if ($allowRoles && str_starts_with($v, 'role:')) {
                    $slot = substr($v, 5);
                    if (isset(self::ROLE_SLOTS[$slot])) { $members[] = ['user' => null, 'role' => $slot]; }
                } elseif ((int) $v > 0) {
                    $members[] = ['user' => (int) $v, 'role' => null];
                }
            }
            if (!$members) { continue; }
            $stepNo++;
            $type = in_array($stage['type'] ?? '', ['approve', 'sign', 'ack'], true) ? $stage['type'] : 'approve';
            $parallel = !empty($stage['parallel']) ? 1 : 0;
            foreach ($members as $m) {
                if ($allowRoles) {
                    Database::insert(
                        "INSERT INTO $table ($fk, step_no, stage_type, parallel, user_id, role_slot) VALUES (?,?,?,?,?,?)",
                        [$fkVal, $stepNo, $type, $parallel, $m['user'], $m['role']]);
                } elseif ($m['user']) {
                    Database::insert(
                        "INSERT INTO $table ($fk, step_no, stage_type, parallel, user_id) VALUES (?,?,?,?,?)",
                        [$fkVal, $stepNo, $type, $parallel, $m['user']]);
                }
            }
        }
    }

    private function doSend(int $docId): void
    {
        $doc = Database::one('SELECT * FROM documents WHERE id = ?', [$docId]);
        $first = Database::one('SELECT * FROM doc_approvers WHERE document_id = ? AND step_no = 1 ORDER BY id LIMIT 1', [$docId]);
        if (!$first) { flash('Добавьте хотя бы один этап с участником.', 'error'); return; }
        Database::run("UPDATE doc_approvers SET status='pending', comment=NULL, decided_at=NULL, on_behalf_of=NULL WHERE document_id = ?", [$docId]);
        Database::run("UPDATE documents SET status='on_approval', current_step=1, sent_at=? WHERE id=?", [date('Y-m-d H:i:s'), $docId]);
        $this->history($docId, 'Отправлен по маршруту');
        $targets = (int) $first['parallel']
            ? Database::all('SELECT user_id FROM doc_approvers WHERE document_id=? AND step_no=1', [$docId])
            : [['user_id' => $first['user_id']]];
        foreach ($targets as $t2) {
            NotificationService::create((int) $t2['user_id'], 'Документ: ' . (self::STAGE_LABEL[$first['stage_type']] ?? 'Этап'),
                "«{$doc['title']}» ожидает вас.");
        }
        flash('Документ отправлен по маршруту.');
    }

    /** Нумератор журнала: счётчик в пределах типа и ГОДА (сброс 1 января).
     *  С индексом дела: 01-15/3-2026; без — ПР-3/26. */
    private function assignRegNumber(array $doc): string
    {
        $type = Database::one('SELECT * FROM doc_types WHERE id = ?', [$doc['type_id']]);
        $Y = date('Y');
        $n = 1 + (int) Database::scalar(
            "SELECT COUNT(*) FROM documents WHERE type_id = ? AND reg_number IS NOT NULL AND substr(COALESCE(finished_at, created_at),1,4) = ?",
            [$doc['type_id'], $Y]);
        $idx = trim((string) ($type['journal_index'] ?? ''));
        return $idx !== ''
            ? $idx . '/' . $n . '-' . $Y
            : ($type['prefix'] ?: 'Д') . '-' . $n . '/' . date('y');
    }

    /** Загрузка вложения новой ВЕРСИЕЙ (история версий сохраняется). */
    private function saveFile(int $docId): void
    {
        if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { return; }
        if ($_FILES['file']['size'] > 20971520) { flash('Файл слишком большой (макс. 20 МБ).', 'error'); return; }
        if (!is_dir(self::UPLOAD_DIR)) { mkdir(self::UPLOAD_DIR, 0775, true); }
        $orig = $_FILES['file']['name'];
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $stored = bin2hex(random_bytes(16)) . ($ext ? '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext) : '');
        if (move_uploaded_file($_FILES['file']['tmp_name'], self::UPLOAD_DIR . '/' . $stored)) {
            $ver = (int) Database::scalar('SELECT COALESCE(MAX(version),0)+1 FROM doc_files WHERE document_id = ?', [$docId]);
            $text = self::extractText(self::UPLOAD_DIR . '/' . $stored, $orig);
            $fileId = Database::insert('INSERT INTO doc_files (document_id, version, orig_name, stored_name, size_bytes, text_content, uploaded_by) VALUES (?,?,?,?,?,?,?)',
                [$docId, $ver, $orig, $stored, (int) $_FILES['file']['size'], $text, Auth::id()]);
            Database::run('UPDATE documents SET file_orig=?, file_stored=? WHERE id=?', [$orig, $stored, $docId]);
            $this->history($docId, "Загружена версия {$ver} вложения: {$orig}");
            // прогрев PDF-образа (best-effort), чтобы карточка открывалась мгновенно
            try {
                \App\Services\PdfPreview::ensure(Database::one('SELECT * FROM doc_files WHERE id = ?', [$fileId]), self::UPLOAD_DIR);
            } catch (\Throwable $e) { /* образ соберётся при первом просмотре */ }
        }
    }

    /** Извлечение текста вложения для полнотекстового поиска (txt/docx/xlsx). */
    private static function extractText(string $path, string $origName): ?string
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $text = null;
        if (in_array($ext, ['txt', 'csv', 'md'], true)) {
            $text = (string) file_get_contents($path, false, null, 0, 300000);
        } elseif (in_array($ext, ['docx', 'xlsx'], true) && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $buf = '';
                for ($i = 0; $i < $zip->numFiles && strlen($buf) < 300000; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (substr($name, -4) === '.xml' && (str_contains($name, 'document') || str_contains($name, 'sharedStrings') || str_contains($name, 'sheet'))) {
                        $xml = $zip->getFromIndex($i);
                        if ($xml !== false) { $buf .= ' ' . preg_replace('/<[^>]+>/', ' ', $xml); }
                    }
                }
                $zip->close();
                $text = html_entity_decode($buf, ENT_QUOTES, 'UTF-8');
            }
        }
        if ($text !== null) {
            $text = mb_substr(preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\PC\n\t ]/u', '', $text)), 0, 100000);
        }
        return $text ?: null;
    }

    /** Текущая версия вложения документа. */
    public static function currentVersion(int $docId): int
    {
        return (int) Database::scalar('SELECT COALESCE(MAX(version),0) FROM doc_files WHERE document_id = ?', [$docId]);
    }

    private function history(int $docId, string $event, ?string $comment = null): void
    {
        Database::insert('INSERT INTO doc_history (document_id, user_name, event, comment) VALUES (?,?,?,?)',
            [$docId, $_SESSION['name'] ?? '', $event, $comment]);
    }
}
