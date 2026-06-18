<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\VisaParser;
use App\Services\OpenRouter;
use App\Services\NotificationService;
use App\Services\Settings;
use App\Services\Xlsx;

/**
 * Контур виз: загрузка ходатайств из Word, ИИ-подстановка адресов (OpenRouter),
 * распределение строк специалистам виз-менеджером, Excel-грид проверки по 10 строк,
 * экспорт: каждая строка — отдельный DOCX (ZIP) / печатная PDF-форма.
 */
class VisaController extends Controller
{
    public const FIELDS = [
        'out_no' => 'Исходящий №', 'out_date' => 'Дата исх.', 'surname_ru' => 'Фамилия (рус)',
        'names_ru' => 'Имена (рус)', 'surname_lat' => 'Фамилия (лат)', 'names_lat' => 'Имена (лат)',
        'citizenship' => 'Гражданство', 'residence' => 'Гос. проживания', 'birth_date' => 'Дата рожд.',
        'birth_place' => 'Место рожд.', 'sex' => 'Пол', 'passport_no' => 'Паспорт №',
        'issue_date' => 'Выдан', 'expiry_date' => 'Действ. до', 'work_address' => 'Адрес работы',
        'visit_places' => 'Города в РФ', 'visa_place' => 'Место получения визы', 'ai_address' => 'Адрес (ИИ)',
    ];

    /** Человекочитаемые статусы визовой анкеты (конвейер visa_rows.status). */
    public const STATUS_LABELS = [
        'loaded'     => 'Загружена',
        'assigned'   => 'Назначена (в работе)',
        'checked'    => 'Проверена',
        'in_opis'    => 'В описи',
        'instructed' => 'Указание получено',
        'rework'     => 'Доработка (отказ МИД)',
        'rework_pass'=> 'Доработка (срок паспорта)',
    ];

    /** Базовые настройки бланка (применяются к новым партиям; меняются виз-менеджером). */
    public const DEFAULTS = [
        'letter_date' => '02.05.25',
        'entry_date'  => '15.02.25',
        'stay_date'   => '15.05.26',
        'signer'      => 'В.В. СУЩИК',
    ];

    /** Текущие базовые значения бланка из настроек (с откатом на исходные дефолты). */
    public static function blankDefaults(): array
    {
        $d = [];
        foreach (self::DEFAULTS as $k => $fallback) {
            $v = (string) Settings::get('visa_def_' . $k, '');
            $d[$k] = $v !== '' ? $v : $fallback;
        }
        return $d;
    }

    private function isVisaManager(array $me): bool
    {
        return $me['role'] === 'admin' || (int) ($me['is_visa_manager'] ?? 0) === 1;
    }

    /**
     * Класс подсветки срока действия паспорта (от сегодняшней даты):
     * '' (норма) | 'exp-warn' (жёлтый, остаётся < 1 года 7 мес) | 'exp-bad' (красный, < 1 года 6 мес).
     * Принимает дату вида ДД.ММ.ГГ или ДД.ММ.ГГГГ.
     */
    public static function expiryClass(?string $expiry): string
    {
        $expiry = trim((string) $expiry);
        if ($expiry === '' || !preg_match('#^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})$#', $expiry, $m)) { return ''; }
        $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
        if ($y < 100) { $y += 2000; }
        if (!checkdate($mo, $d, $y)) { return ''; }
        $exp  = mktime(0, 0, 0, $mo, $d, $y);
        $today = strtotime('today');
        if ($exp < strtotime('+1 year +6 months', $today)) { return 'exp-bad'; }
        if ($exp < strtotime('+1 year +7 months', $today)) { return 'exp-warn'; }
        return '';
    }

    /** Журнал смены статуса визовой строки (для отчёта динамики за период). Не ломает основную работу. */
    public static function logStatus(int $rowId, string $status): void
    {
        try {
            Database::insert('INSERT INTO visa_status_events (row_id, status, changed_at, changed_by) VALUES (?,?,?,?)',
                [$rowId, $status, date('Y-m-d H:i:s'), (int) (Auth::id() ?? 0) ?: null]);
        } catch (\Throwable $e) { /* таблица может отсутствовать до миграции — игнорируем */ }
    }

    public static function inboxCount(int $uid): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM visa_rows WHERE assigned_to = ? AND checked_at IS NULL', [$uid]);
    }

    // ================= МЕНЕДЖЕР =================

    public function manage(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { flash('Раздел виз-менеджера.', 'error'); $this->redirect('/'); }

        // Фильтр по дате загрузки + пагинация (10/стр) + «показать все».
        $d = trim((string) $this->input('d', ''));
        $all = $this->input('all') === '1';
        $page = max(1, (int) $this->input('page', 1));
        $perPage = 10;
        $where = ''; $wp = [];
        if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $where = " WHERE substr(b.created_at,1,10) = ?"; $wp[] = $d; }
        $totalBatches = (int) Database::scalar("SELECT COUNT(*) FROM visa_batches b" . $where, $wp);
        $pages = max(1, (int) ceil($totalBatches / $perPage));
        if (!$all) { $page = min($page, $pages); }
        $limit = $all ? '' : (" LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $batches = Database::all(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.batch_id=b.id) AS total,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.batch_id=b.id AND (r.ai_address IS NULL OR r.ai_address='')) AS no_ai,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.batch_id=b.id AND r.assigned_to IS NULL) AS unassigned,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.batch_id=b.id AND r.checked_at IS NOT NULL) AS checked
               FROM visa_batches b" . $where . " ORDER BY b.id DESC" . $limit, $wp);
        // Страна(ы) партии — по гражданству её строк (driver-safe, в PHP).
        foreach ($batches as &$b) {
            $cs = Database::all("SELECT DISTINCT citizenship FROM visa_rows WHERE batch_id=? AND citizenship<>'' ORDER BY citizenship", [$b['id']]);
            $b['country'] = implode(', ', array_map(fn($r) => $r['citizenship'], $cs));
        }
        unset($b);

        $specialists = Database::all(
            "SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.assigned_to=u.id) AS assigned,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.assigned_to=u.id AND r.checked_at IS NOT NULL) AS checked
               FROM users u
              WHERE (u.role='employee' OR u.role='admin') AND u.is_active=1
                AND (u.role='admin'
                     OR u.id IN (SELECT user_id FROM user_roles WHERE role_slug='visa_worker'))
              ORDER BY u.full_name");
        foreach ($specialists as &$s2) { $s2['remaining'] = (int) $s2['assigned'] - (int) $s2['checked']; }
        unset($s2);

        $this->view('visas/manage', [
            'title' => 'Визы: загрузка и распределение',
            'batches' => $batches,
            'specialists' => $specialists,
            'aiReady' => OpenRouter::configured(),
            'defaults' => self::blankDefaults(),
            'csrf' => \App\Core\Auth::csrf(),
            'flt' => ['d' => $d, 'all' => $all, 'page' => $page, 'pages' => $pages, 'total' => $totalBatches],
        ]);
    }

    /** Загрузка одного или нескольких .docx с ходатайствами. */
    public function upload(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        @set_time_limit(0);

        $files = $_FILES['files'] ?? null;
        if (!$files || empty($files['name'][0])) { flash('Выберите .docx файлы с ходатайствами.', 'error'); $this->redirect('/visas/manage'); }

        $name = trim((string) $this->input('name')) ?: ('Партия ' . date('d.m.Y H:i'));
        // Новая партия наследует базовые настройки бланка (их можно поменять для партии позже).
        $bd = self::blankDefaults();
        $batchId = Database::insert(
            'INSERT INTO visa_batches (name, uploaded_by, letter_date, entry_date, stay_date, signer) VALUES (?,?,?,?,?,?)',
            [$name, $me['id'], $bd['letter_date'], $bd['entry_date'], $bd['stay_date'], $bd['signer']]);

        // Уже загруженные исходящие номера (по всем партиям) — дубли не грузим.
        $existing = [];
        foreach (Database::all("SELECT out_no FROM visa_rows WHERE out_no <> ''") as $e) {
            $existing[mb_strtoupper(trim($e['out_no']))] = true;
        }

        $added = 0; $filesOk = 0; $skipped = [];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $cols = ['out_no','out_date','surname_ru','names_ru','surname_lat','names_lat','citizenship','residence',
                 'birth_date','birth_place','sex','passport_no','issue_date','expiry_date','work_address',
                 'visit_places','visa_place','source_file','table_no'];
        $ins = $pdo->prepare('INSERT INTO visa_rows (batch_id,' . implode(',', $cols) . ') VALUES (?' . str_repeat(',?', count($cols)) . ')');
        foreach ($files['name'] as $i => $fname) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) { continue; }
            $rows = VisaParser::parse($files['tmp_name'][$i], $fname);
            if ($rows) { $filesOk++; }
            foreach ($rows as $r) {
                $no = mb_strtoupper(trim((string) ($r['out_no'] ?? '')));
                if ($no !== '' && isset($existing[$no])) {
                    $skipped[] = ($r['out_no'] ?: '?') . ' (' . trim((string) ($r['surname_lat'] ?? '')) . ')';
                    continue;
                }
                if ($no !== '') { $existing[$no] = true; } // дубль и внутри самой загрузки
                $vals = [$batchId];
                foreach ($cols as $c) { $vals[] = (string) ($r[$c] ?? ''); }
                $ins->execute($vals);
                self::logStatus((int) $pdo->lastInsertId(), 'loaded');
                $added++;
            }
        }
        $pdo->commit();

        // Отчёт о загрузке: сколько новых, сколько дублей и какие именно не загружены.
        if (!$added && !$skipped) {
            Database::run('DELETE FROM visa_batches WHERE id = ?', [$batchId]);
            flash('Анкеты не распознаны — проверьте формат файлов.', 'error');
        } elseif (!$added) {
            Database::run('DELETE FROM visa_batches WHERE id = ?', [$batchId]);
            flash("Новых анкет нет — все " . count($skipped) . " уже загружены ранее (дубли по исх. №):\n"
                . self::skippedList($skipped), 'error');
        } else {
            $msg = "Загружено: файлов {$filesOk}, НОВЫХ анкет {$added} (партия «{$name}»).";
            if ($skipped) {
                $msg .= "\nПропущено дублей по исх. №: " . count($skipped) . " — не загружены:\n" . self::skippedList($skipped);
            }
            flash($msg, $skipped ? 'error' : 'success');
        }
        $this->redirect('/visas/manage');
    }

    /** Список пропущенных дублей для отчёта (первые 100, дальше счётчиком). */
    private static function skippedList(array $skipped): string
    {
        $shown = array_slice($skipped, 0, 100);
        $tail = count($skipped) - count($shown);
        return implode(', ', $shown) . ($tail > 0 ? " … и ещё {$tail}" : '');
    }

    /** AJAX: обработать ИИ один пакет строк без адреса (до 25 за вызов). */
    public function aiBatch(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->json(['ok' => false, 'message' => 'Нет прав']); }
        @set_time_limit(0);
        $batchId = (int) $this->input('batch_id');

        $where = "(ai_address IS NULL OR ai_address='')" . ($batchId ? ' AND batch_id = ' . $batchId : '');
        $rows = Database::all("SELECT id, residence, work_address FROM visa_rows WHERE $where ORDER BY id LIMIT 25");
        $remainingBefore = (int) Database::scalar("SELECT COUNT(*) FROM visa_rows WHERE $where");
        if (!$rows) { $this->json(['ok' => true, 'processed' => 0, 'remaining' => 0]); }

        try {
            $records = array_map(fn($r) => ['id' => (int) $r['id'], 'country' => (string) $r['residence'], 'address' => (string) $r['work_address']], $rows);
            $map = OpenRouter::fillAddresses($records);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }
        $done = 0;
        foreach ($map as $id => $addr) {
            if ($addr !== '') { Database::run('UPDATE visa_rows SET ai_address = ? WHERE id = ?', [$addr, $id]); $done++; }
        }
        // не зацикливаться, если модель не вернула часть строк
        if ($done === 0) { $this->json(['ok' => false, 'message' => 'Модель не вернула адреса (формат ROW_X). Проверьте промпт/модель.']); }
        $this->json(['ok' => true, 'processed' => $done, 'remaining' => max(0, $remainingBefore - $done)]);
    }

    /** AJAX: строки для панели распределения. */
    public function items(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->json(['items' => [], 'total' => 0]); }
        $owner = (string) $this->input('owner', 'pool');
        $batch = $this->input('batch_id');
        $q = mb_strtolower(trim((string) $this->input('q')));

        // обычная доска распределения — без строк «на доработке» (у них своя доска)
        $where = "checked_at IS NULL AND status != 'rework'";
        $params = [];
        if ($owner === 'pool') { $where .= ' AND assigned_to IS NULL'; }
        else { $where .= ' AND assigned_to = ?'; $params[] = (int) $owner; }
        if ($batch) { $where .= ' AND batch_id = ?'; $params[] = (int) $batch; }
        if ($q !== '') { $where .= ' AND (LOWER(out_no) LIKE ? OR LOWER(surname_lat) LIKE ? OR LOWER(surname_ru) LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM visa_rows WHERE $where", $params);
        $rows = Database::all("SELECT id, out_no, surname_lat, citizenship FROM visa_rows WHERE $where ORDER BY id LIMIT 400", $params);
        $this->json(['items' => $rows, 'total' => $total, 'shown' => count($rows)]);
    }

    /** AJAX: перенос выбранных строк (pool ↔ специалист). */
    public function move(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->json(['ok' => false]); }
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        $to = (string) $this->input('to', 'pool');
        if (!$ids) { $this->json(['ok' => false, 'moved' => 0]); }
        $assignTo = $to === 'pool' ? null : (int) $to;
        if ($assignTo !== null && (!$assignTo || !Database::scalar('SELECT 1 FROM users WHERE id=? AND is_active=1', [$assignTo]))) {
            $this->json(['ok' => false, 'message' => 'Получатель не найден']);
        }
        // обычное распределение — только строки на этапе проверки (не «на доработке» после МИД)
        $newStatus = $assignTo === null ? 'loaded' : 'assigned';
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::run("UPDATE visa_rows SET assigned_to = ?, status = ? WHERE id IN ($place) AND checked_at IS NULL AND status != 'rework'",
            array_merge([$assignTo, $newStatus], $ids));
        $moved = $stmt->rowCount();
        foreach ($ids as $rid) { self::logStatus((int) $rid, $newStatus); }
        if ($assignTo && $moved) {
            NotificationService::create($assignTo, 'Назначены визовые анкеты', "Вам назначено {$moved} анкет на проверку виз.");
        }
        $this->json(['ok' => true, 'moved' => $moved]);
    }

    // ================= СПЕЦИАЛИСТ =================

    /** Грид проверки: 10 строк, все поля редактируются как ячейки. */
    public function grid(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $rows = Database::all(
            'SELECT * FROM visa_rows WHERE assigned_to = ? AND checked_at IS NULL ORDER BY id LIMIT 10', [$uid]);
        $remaining = self::inboxCount($uid);
        $doneToday = (int) Database::scalar(
            "SELECT COUNT(*) FROM visa_rows WHERE assigned_to=? AND substr(checked_at,1,10)=?", [$uid, date('Y-m-d')]);
        $this->view('visas/grid', [
            'title' => 'Проверка виз',
            'rows' => $rows,
            'remaining' => $remaining,
            'doneToday' => $doneToday,
            'fields' => self::FIELDS,
        ]);
    }

    /**
     * Сохранить введённые поля по показанным анкетам. Режимы:
     *   finalize — отметить проверенными ТОЛЬКО анкеты с галочкой (done[id]); остальные сохранить как черновик;
     *   draft    — только записать данные, без отметки «проверено» (анкеты остаются в очереди).
     */
    public function saveGrid(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        if (!AttendanceController::isWorking($uid)) {
            flash('Рабочий день не открыт. Нажмите «Приступить к работе» в кабинете.', 'error');
            $this->redirect('/dashboard');
        }
        $mode = $this->input('mode') === 'draft' ? 'draft' : 'finalize';
        $data = $_POST['row'] ?? [];   // row[id][field]
        $done = $_POST['done'] ?? [];  // done[id] = '1' — отметить проверенной (только в режиме finalize)
        $editable = array_keys(self::FIELDS);
        $now = date('Y-m-d H:i:s');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $saved = 0; $checked = 0; $credited = 0;
        foreach ($data as $id => $fields) {
            $row = Database::one('SELECT id, credited_at FROM visa_rows WHERE id=? AND assigned_to=? AND checked_at IS NULL', [(int) $id, $uid]);
            if (!$row) { continue; }
            $set = []; $vals = [];
            foreach ($editable as $f) {
                if (array_key_exists($f, $fields)) { $set[] = "$f = ?"; $vals[] = trim((string) $fields[$f]); }
            }
            $finalize = $mode === 'finalize' && !empty($done[$id]);
            if ($finalize) {
                $set[] = 'checked_at = ?'; $vals[] = $now;
                $set[] = "status = 'checked'";
                // зачёт в сделку один раз (повторная проверка после доработки не удваивает)
                if (empty($row['credited_at'])) { $set[] = 'credited_at = ?'; $vals[] = $now; $credited++; }
                $checked++;
            }
            if (!$set) { continue; }
            $vals[] = (int) $id;
            Database::run('UPDATE visa_rows SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
            if ($finalize) { self::logStatus((int) $id, 'checked'); }
            $saved++;
        }
        $pdo->commit();
        if ($credited) { self::creditStage2($uid, $credited); }
        if ($mode === 'draft') {
            flash("Черновик сохранён: {$saved} анкет (без отметки «проверено» — остаются в очереди).");
        } else {
            flash("Сохранено: {$saved}. Отмечено проверенными: {$checked}." . ($credited ? " Зачтено в сделку («Виза — этап 2»): {$credited}." : ''));
        }
        $this->redirect('/visas');
    }

    /** Автозачёт проверки виз как операции «Виза — этап 2» (сделка), N штук за сегодня. */
    private static function creditStage2(int $uid, int $qty): void
    {
        $opId = Database::scalar("SELECT id FROM operations WHERE name LIKE '%этап 2%' AND is_active=1 ORDER BY id LIMIT 1");
        if (!$opId) {
            $opId = Database::insert('INSERT INTO operations (name, unit_price, is_active) VALUES (?,?,1)', ['Виза — этап 2', 15]);
        }
        $today = date('Y-m-d');
        $pw = Database::one('SELECT id, quantity FROM piecework WHERE employee_id=? AND operation_id=? AND work_date=?', [$uid, $opId, $today]);
        if ($pw) {
            Database::run('UPDATE piecework SET quantity = quantity + ? WHERE id = ?', [$qty, $pw['id']]);
        } else {
            Database::insert('INSERT INTO piecework (employee_id, operation_id, work_date, quantity) VALUES (?,?,?,?)', [$uid, (int) $opId, $today, $qty]);
        }
    }

    /** Отработанное специалистом: сводка по датам и партиям + строки за выбранный день. */
    public function done(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $byDay = Database::all(
            "SELECT substr(r.checked_at,1,10) AS d, b.name AS batch_name, COUNT(*) AS cnt
               FROM visa_rows r JOIN visa_batches b ON b.id = r.batch_id
              WHERE r.assigned_to = ? AND r.checked_at IS NOT NULL
              GROUP BY substr(r.checked_at,1,10), b.id ORDER BY d DESC, b.id DESC", [$uid]);
        $date = (string) $this->input('date', '');
        $rows = [];
        if ($date !== '') {
            $rows = Database::all(
                "SELECT r.*, b.name AS batch_name FROM visa_rows r JOIN visa_batches b ON b.id=r.batch_id
                  WHERE r.assigned_to = ? AND substr(r.checked_at,1,10) = ? ORDER BY r.checked_at, r.id", [$uid, $date]);
        }
        $total = (int) Database::scalar('SELECT COUNT(*) FROM visa_rows WHERE assigned_to=? AND checked_at IS NOT NULL', [$uid]);
        $this->view('visas/done', [
            'title' => 'Визы: отработанное',
            'byDay' => $byDay, 'rows' => $rows, 'date' => $date, 'total' => $total,
        ]);
    }

    // ================= ОТДЕЛЬНАЯ СТРОКА (доработка) =================

    /** Доступ к строке: её специалист или виз-менеджер. */
    private function rowFor(int $id): ?array
    {
        $me = Auth::user();
        $row = Database::one(
            'SELECT r.*, b.name AS batch_name, u.full_name AS spec_name
               FROM visa_rows r JOIN visa_batches b ON b.id=r.batch_id LEFT JOIN users u ON u.id=r.assigned_to
              WHERE r.id = ?', [$id]);
        if (!$row) { return null; }
        if ($this->isVisaManager($me) || (int) $row['assigned_to'] === (int) $me['id']) { return $row; }
        return null;
    }

    /** Карточка строки: просмотр/правка всех полей, возврат на доработку. */
    public function row(string $id): void
    {
        Auth::requireLogin();
        $row = $this->rowFor((int) $id);
        if (!$row) { flash('Анкета не найдена или нет доступа.', 'error'); $this->redirect('/visas'); }
        $this->view('visas/row', [
            'title' => 'Анкета ' . ($row['out_no'] ?: '#' . $row['id']),
            'row' => $row,
            'fields' => self::FIELDS,
            'isManager' => $this->isVisaManager(Auth::user()),
        ]);
    }

    /** Сохранить правки строки (без смены статуса). */
    public function rowSave(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $row = $this->rowFor((int) $id);
        if (!$row) { $this->redirect('/visas'); }
        $set = []; $vals = [];
        foreach (array_keys(self::FIELDS) as $f) {
            if (array_key_exists($f, $_POST)) { $set[] = "$f = ?"; $vals[] = trim((string) $_POST[$f]); }
        }
        if ($set) {
            $vals[] = (int) $id;
            Database::run('UPDATE visa_rows SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
        }
        flash('Анкета сохранена.');
        $this->redirect('/visas/row/' . (int) $id);
    }

    /** Вернуть строку на доработку (менеджер или сам специалист): снова попадает в грид. */
    public function rowRework(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $row = $this->rowFor((int) $id);
        if (!$row) { $this->redirect('/visas'); }
        if (empty($row['checked_at'])) { flash('Анкета и так в работе.', 'error'); $this->redirect('/visas/row/' . (int) $id); }
        $note = trim((string) $this->input('note'));
        Database::run(
            "UPDATE visa_rows SET checked_at = NULL, status = 'assigned', rework_note = ?, rework_by = ?, rework_at = ?, rework_count = rework_count + 1 WHERE id = ?",
            [$note !== '' ? $note : 'Возврат на доработку', (int) $me['id'], date('Y-m-d H:i:s'), (int) $id]);
        if ($row['assigned_to'] && (int) $row['assigned_to'] !== (int) $me['id']) {
            NotificationService::create((int) $row['assigned_to'], 'Визовая анкета возвращена на доработку',
                'Анкета ' . ($row['out_no'] ?: '#' . $row['id']) . ($note !== '' ? ': ' . $note : '') . ' — она снова в вашей очереди проверки.');
        }
        self::logStatus((int) $id, 'assigned');
        flash('Анкета возвращена на доработку — снова в очереди специалиста.');
        $this->redirect($this->isVisaManager($me) ? '/visas/batch/' . (int) $row['batch_id'] . '/rows' : '/visas');
    }

    /** Доработка по сроку действия паспорта (с грида специалиста или карточки) — без штрафа. */
    public function rowPassportRework(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        $rid = (int) $id;
        $row = Database::one('SELECT id, assigned_to, batch_id, out_no FROM visa_rows WHERE id = ?', [$rid]);
        if (!$row) { $this->redirect('/visas'); }
        $own = (int) ($row['assigned_to'] ?? 0) === (int) $me['id'];
        if (!$own && !$this->isVisaManager($me)) { flash('Строка недоступна.', 'error'); $this->redirect('/visas'); }
        \App\Services\VisaReworkService::passportRework($rid, (int) $me['id']);
        self::logStatus($rid, 'rework_pass');
        flash('Анкета ' . ($row['out_no'] ?: '#' . $rid) . ' отправлена на доработку (срок действия паспорта).');
        $this->redirect($this->isVisaManager($me) ? '/visas/rework' : '/visas');
    }

    // ================= СТРОКИ ПАРТИИ (менеджер) =================

    /** Реестр строк партии: фильтры, постраничный просмотр, выбор для выгрузки. */
    public function batchRows(string $batchId): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        $batch = Database::one('SELECT * FROM visa_batches WHERE id = ?', [$batchId]);
        if (!$batch) { flash('Партия не найдена.', 'error'); $this->redirect('/visas/manage'); }

        $status = (string) $this->input('status', '');
        $q = mb_strtolower(trim((string) $this->input('q', '')));
        [$where, $params] = self::rowsFilter((int) $batchId, $status, $q);

        $perPage = 50;
        $page = max(1, (int) $this->input('page', 1));
        $total = (int) Database::scalar("SELECT COUNT(*) FROM visa_rows r WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = Database::all(
            "SELECT r.*, u.full_name AS spec_name FROM visa_rows r LEFT JOIN users u ON u.id = r.assigned_to
              WHERE $where ORDER BY r.id LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);

        $this->view('visas/rows', [
            'title' => 'Строки партии — ' . $batch['name'],
            'batch' => $batch, 'rows' => $rows,
            'status' => $status, 'q' => $q,
            'page' => $page, 'pages' => $pages, 'total' => $total, 'perPage' => $perPage,
            'csrf' => Auth::csrf(),
        ]);
    }

    /** Общий фильтр реестра строк (для страницы и для выгрузки «все по фильтру»). */
    private static function rowsFilter(int $batchId, string $status, string $q): array
    {
        $where = 'r.batch_id = ?';
        $params = [$batchId];
        if ($status === 'checked')   { $where .= ' AND r.checked_at IS NOT NULL'; }
        if ($status === 'unchecked') { $where .= ' AND r.checked_at IS NULL'; }
        if ($status === 'rework')    { $where .= ' AND r.rework_count > 0 AND r.checked_at IS NULL'; }
        if ($q !== '') {
            $where .= ' AND (LOWER(r.out_no) LIKE ? OR LOWER(r.surname_lat) LIKE ? OR LOWER(r.surname_ru) LIKE ? OR LOWER(r.passport_no) LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        return [$where, $params];
    }

    /** Выгрузка ходатайств: выбранные строки (со всех страниц) или все по фильтру; DOCX-ZIP или печать. */
    public function export(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        @set_time_limit(0);
        $batchId = (int) $this->input('batch_id');
        $batch = Database::one('SELECT * FROM visa_batches WHERE id = ?', [$batchId]);
        if (!$batch) { flash('Партия не найдена.', 'error'); $this->redirect('/visas/manage'); }

        if ((string) $this->input('all') === '1') {
            [$where, $params] = self::rowsFilter($batchId, (string) $this->input('status', ''), mb_strtolower(trim((string) $this->input('q', ''))));
            $rows = Database::all("SELECT r.* FROM visa_rows r WHERE $where ORDER BY r.id", $params);
        } else {
            $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', (string) $this->input('ids')))));
            if (!$ids) { flash('Не выбрано ни одной строки.', 'error'); $this->redirect('/visas/batch/' . $batchId . '/rows'); }
            $place = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all("SELECT r.* FROM visa_rows r WHERE r.batch_id = ? AND r.id IN ($place) ORDER BY r.id", array_merge([$batchId], $ids));
        }
        if (!$rows) { flash('Под условия не попало ни одной строки.', 'error'); $this->redirect('/visas/batch/' . $batchId . '/rows'); }

        $fmt = (string) $this->input('fmt');
        if ($fmt === 'pdf') {
            $this->streamPdf($rows, $batch, 'visas-' . $batchId . '-selected.pdf');
            return;
        }
        $this->streamZip($rows, $batch, 'visas-' . $batchId . '-selected.zip');
    }

    // ================= ЭКСПОРТ =================

    /** Путь к официальному бланку МИД (заполняется подстановкой, сам шаблон не меняется). */
    public static function templatePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/templates/visa_template.docx';
    }

    /** Сохранить БАЗОВЫЕ настройки бланка (применяются ко вновь создаваемым партиям). */
    public function saveDefaults(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        foreach (array_keys(self::DEFAULTS) as $k) {
            Settings::set('visa_def_' . $k, trim((string) $this->input($k)));
        }
        flash('Базовые настройки бланка сохранены — применятся к новым партиям. Существующие партии не меняются.');
        $this->redirect('/visas/manage');
    }

    /** Сохранить параметры экспорта партии: дата письма, въезд/пребывание, подписант. */
    public function saveParams(string $batchId): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        Database::run(
            'UPDATE visa_batches SET letter_date=?, entry_date=?, stay_date=?, signer=? WHERE id=?',
            [
                trim((string) $this->input('letter_date')),
                trim((string) $this->input('entry_date')),
                trim((string) $this->input('stay_date')),
                trim((string) $this->input('signer')),
                (int) $batchId,
            ]
        );
        flash('Параметры бланка партии сохранены — будут подставлены при выгрузке.');
        $this->redirect('/visas/manage');
    }

    /** Данные строки → плейсхолдеры {…} официального бланка. */
    private static function rowPlaceholders(array $r): array
    {
        return [
            'Исходящий №'            => (string) $r['out_no'],
            'Фамилия (рус)'          => (string) $r['surname_ru'],
            'Фамилия (лат)'          => (string) $r['surname_lat'],
            'Имена (рус)'            => (string) $r['names_ru'],
            'Имена (лат)'            => (string) $r['names_lat'],
            'Гражданство (подданство)' => (string) $r['citizenship'],
            'Государство проживания' => (string) $r['residence'],
            'Дата рождения'          => (string) $r['birth_date'],
            'Место рождения'         => (string) $r['birth_place'],
            'Пол'                    => (string) $r['sex'],
            'Номер документа, удостоверяющего личность' => (string) $r['passport_no'],
            'Дата выдачи (день, месяц, год)'      => (string) $r['issue_date'],
            'Действителен до (день, месяц, год)'  => (string) $r['expiry_date'],
            'Адрес места работы, тел, факс'       => (string) ($r['ai_address'] ?: $r['work_address']),
            // в бланке опечатка «Пунты» — поддерживаем оба написания
            'Пунты (города) посещения в России'   => (string) $r['visit_places'],
            'Пункты (города) посещения в России'  => (string) $r['visit_places'],
            'Место получения визы'   => (string) $r['visa_place'],
        ];
    }

    /** Параметры партии → замены фиксированного текста бланка (даты, подписант). */
    private static function batchLiterals(?array $batch): array
    {
        if (!$batch) { return []; }
        return [
            '02.05.25'    => (string) ($batch['letter_date'] ?? ''),
            '15.02.25'    => (string) ($batch['entry_date'] ?? ''),
            '15.05.26'    => (string) ($batch['stay_date'] ?? ''),
            'В.В. СУЩИК'  => (string) ($batch['signer'] ?? ''),
        ];
    }

    /** ZIP: каждая строка партии — отдельный DOCX на официальном бланке МИД. */
    public function exportZip(string $batchId): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        $rows = Database::all('SELECT * FROM visa_rows WHERE batch_id = ? ORDER BY id', [$batchId]);
        if (!$rows) { flash('В партии нет строк.', 'error'); $this->redirect('/visas/manage'); }
        $batch = Database::one('SELECT * FROM visa_batches WHERE id = ?', [$batchId]);
        @set_time_limit(0);
        $this->streamZip($rows, $batch, 'visas-batch-' . (int) $batchId . '.zip');
    }

    /**
     * Заполнить бланк МИД по каждой строке.
     * @return array<int,array{name:string,bin:string}> уникальные имена файлов + содержимое DOCX
     */
    private function buildDocxFiles(array $rows, ?array $batch): array
    {
        $tpl = self::templatePath();
        $useTpl = is_file($tpl);
        $literals = self::batchLiterals($batch);
        $out = []; $used = [];
        foreach ($rows as $r) {
            $base = preg_replace('/[^A-Za-z0-9А-Яа-яЁё _-]/u', '', trim(($r['out_no'] ?: $r['id']) . '_' . $r['surname_lat']));
            if ($base === '') { $base = 'visa_' . $r['id']; }
            $name = $base . '.docx';
            $n = 1;
            while (isset($used[$name])) { $name = $base . '_' . (++$n) . '.docx'; }
            $used[$name] = true;
            $bin = $useTpl
                ? \App\Services\DocxTemplate::fill($tpl, self::rowPlaceholders($r), $literals)
                : \App\Services\DocxWriter::visaCard($r, self::FIELDS); // запасной вариант, если бланк удалён
            $out[] = ['name' => $name, 'bin' => $bin];
        }
        return $out;
    }

    /** Собрать ZIP из строк (каждая — DOCX на бланке) и отдать в браузер. */
    private function streamZip(array $rows, ?array $batch, string $zipName): void
    {
        $files = $this->buildDocxFiles($rows, $batch);
        $tmp = tempnam(sys_get_temp_dir(), 'vz');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $f) { $zip->addFromString($f['name'], $f['bin']); }
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    /**
     * PDF на всю выборку, вид как в Word. Движок — Word или LibreOffice (что есть на сервере).
     * Один общий PDF; если на сервере нечем склеить (LibreOffice без pdfunite/gs/qpdf) —
     * ZIP с отдельными PDF. Если движка нет вовсе — ошибка (HTML-печать убрана как нерабочая).
     */
    private function streamPdf(array $rows, ?array $batch, string $pdfName): void
    {
        $conv = \App\Services\OfficeConverter::class;
        $back = '/visas/manage';
        if (!$conv::available()) {
            flash('Для PDF «как в Word» на сервере нужен Microsoft Word или LibreOffice (libreoffice-writer). Скачайте DOCX и сохраните его в PDF из Word.', 'error');
            $this->redirect($back);
        }
        // Основной путь: собрать ОДИН многостраничный DOCX (каждое ходатайство — отдельная
        // страница на том же бланке) и сконвертировать ОДИНОЧНОЙ конвертацией. Это даёт
        // корректный PDF (склейка через Word InsertFile ломала разметку бланка).
        $tpl = self::templatePath();
        $pdf = null;
        if (is_file($tpl)) {
            try {
                $combined = \App\Services\DocxTemplate::fillCombined(
                    $tpl, array_map(fn($r) => self::rowPlaceholders($r), $rows), self::batchLiterals($batch));
                $pdf = $conv::docxToPdf([$combined]);
            } catch (\Throwable $e) { $pdf = null; }
        }
        if ($pdf !== null) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $pdfName . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }

        // Запасной путь: каждое ходатайство — свой корректный PDF, упаковать в ZIP.
        $files = $this->buildDocxFiles($rows, $batch);
        $pdfs = $conv::docxToPdfEach(array_map(fn($f) => $f['bin'], $files));
        if ($pdfs !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'vp');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            foreach ($pdfs as $i => $bin) {
                $zip->addFromString(preg_replace('/\.docx$/u', '.pdf', $files[$i]['name']), $bin);
            }
            $zip->close();
            $data = file_get_contents($tmp);
            @unlink($tmp);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . preg_replace('/\.pdf$/u', '-pdf.zip', $pdfName) . '"');
            header('Content-Length: ' . strlen($data));
            echo $data;
            exit;
        }
        flash('Не удалось сконвертировать в PDF (движок: ' . ($conv::engine() ?: 'нет') . '). Скачайте DOCX и сохраните его в PDF из Word.', 'error');
        $this->redirect($back);
    }

    /** PDF всей партии силами Word (каждое ходатайство — с новой страницы), вид как в Word. */
    public function exportPdf(string $batchId): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { $this->redirect('/'); }
        $rows = Database::all('SELECT * FROM visa_rows WHERE batch_id = ? ORDER BY id', [$batchId]);
        if (!$rows) { flash('В партии нет строк.', 'error'); $this->redirect('/visas/manage'); }
        $batch = Database::one('SELECT * FROM visa_batches WHERE id = ?', [$batchId]);
        @set_time_limit(0);
        $this->streamPdf($rows, $batch, 'visas-batch-' . (int) $batchId . '.pdf');
    }

    /** Отчёт по визам: всего/проверено/нераспределено, по специалистам и по партиям. */
    public function report(): void
    {
        Auth::requireLogin();
        if (!$this->isVisaManager(Auth::user())) { $this->redirect('/'); }
        $overall = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                    SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned
               FROM visa_rows");
        $byEmployee = Database::all(
            "SELECT u.full_name,
                    COUNT(r.id) AS assigned,
                    SUM(CASE WHEN r.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                    COALESCE(SUM(r.rework_count),0) AS reworks
               FROM users u JOIN visa_rows r ON r.assigned_to = u.id
              GROUP BY u.id, u.full_name ORDER BY u.full_name");
        $byBatch = Database::all(
            "SELECT b.id, b.name,
                    COUNT(r.id) AS total,
                    SUM(CASE WHEN r.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                    SUM(CASE WHEN r.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned
               FROM visa_batches b LEFT JOIN visa_rows r ON r.batch_id = b.id
              GROUP BY b.id, b.name ORDER BY b.id DESC");
        foreach ($byBatch as &$bb) {
            $cs = Database::all("SELECT DISTINCT citizenship FROM visa_rows WHERE batch_id=? AND citizenship<>'' ORDER BY citizenship", [$bb['id']]);
            $bb['country'] = implode(', ', array_map(fn($r) => $r['citizenship'], $cs));
        }
        unset($bb);
        $this->view('visas/report', [
            'title' => 'Отчёт по визам',
            'overall' => $overall, 'byEmployee' => $byEmployee, 'byBatch' => $byBatch,
        ]);
    }

    /** Динамика за период: статусы по странам на начало/конец + новые загрузки + внесённые указания. */
    public function periodReport(): void
    {
        Auth::requireLogin();
        if (!$this->isVisaManager(Auth::user())) { $this->redirect('/'); }
        [$from, $to, $country, $data] = $this->periodData();
        $this->view('visas/period_report', array_merge($data, [
            'title' => 'Визы: динамика за период',
            'from' => $from, 'to' => $to, 'country' => $country,
            'statuses' => self::STATUS_LABELS,
            'countries' => array_map(fn($r) => $r['c'], Database::all("SELECT DISTINCT UPPER(TRIM(citizenship)) AS c FROM visa_rows WHERE citizenship<>'' ORDER BY c")),
        ]));
    }

    /** Расчёт данных динамики (используется отчётом и экспортом). */
    private function periodData(): array
    {
        $from = (string) $this->input('from', date('Y-m-01'));
        $to   = (string) $this->input('to', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = date('Y-m-d'); }
        $country = mb_strtoupper(trim((string) $this->input('country', '')), 'UTF-8');

        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $cWhere = '';
        if ($country !== '') { $cWhere = " WHERE UPPER(TRIM(r.citizenship)) = ?"; $params[] = $country; }
        $rows = Database::all(
            "SELECT UPPER(TRIM(r.citizenship)) AS country,
                    (SELECT e.status FROM visa_status_events e WHERE e.row_id=r.id AND e.changed_at < ?  ORDER BY e.changed_at DESC, e.id DESC LIMIT 1) AS st_start,
                    (SELECT e.status FROM visa_status_events e WHERE e.row_id=r.id AND e.changed_at <= ? ORDER BY e.changed_at DESC, e.id DESC LIMIT 1) AS st_end
               FROM visa_rows r" . $cWhere, $params);

        $matrix = []; // country => status => ['start'=>n,'end'=>n]
        foreach ($rows as $r) {
            $c = $r['country'] !== '' ? $r['country'] : '—';
            foreach (['start' => $r['st_start'], 'end' => $r['st_end']] as $k => $st) {
                if ($st === null || $st === '') { continue; }
                $matrix[$c][$st][$k] = ($matrix[$c][$st][$k] ?? 0) + 1;
            }
        }
        ksort($matrix);
        $newLoaded   = (int) Database::scalar("SELECT COUNT(*) FROM visa_rows WHERE substr(created_at,1,10) BETWEEN ? AND ?", [$from, $to]);
        $instructions = (int) Database::scalar("SELECT COUNT(*) FROM visa_opis WHERE instructed_at IS NOT NULL AND substr(instructed_at,1,10) BETWEEN ? AND ?", [$from, $to]);
        return [$from, $to, $country, ['matrix' => $matrix, 'newLoaded' => $newLoaded, 'instructions' => $instructions]];
    }

    public function periodReportExport(): void
    {
        Auth::requireLogin();
        if (!$this->isVisaManager(Auth::user())) { $this->redirect('/'); }
        [$from, $to, $country, $data] = $this->periodData();
        $rows = [];
        foreach ($data['matrix'] as $c => $byStatus) {
            foreach (self::STATUS_LABELS as $st => $label) {
                $s = (int) ($byStatus[$st]['start'] ?? 0);
                $e = (int) ($byStatus[$st]['end'] ?? 0);
                if ($s === 0 && $e === 0) { continue; }
                $rows[] = [$c, $label, $s, $e, $e - $s];
            }
        }
        $rows[] = ['—', 'Новых загружено за период', '', $data['newLoaded'], ''];
        $rows[] = ['—', 'Визовых указаний внесено за период', '', $data['instructions'], ''];
        \App\Services\Xlsx::download("visas-dynamics-{$from}_{$to}.xlsx", [[
            'name'    => 'Динамика',
            'headers' => ['Страна', 'Статус', 'На начало', 'На конец', 'Δ'],
            'rows'    => $rows,
        ]]);
    }

    /** Рейтинг специалистов по визам за период (проверено; качество — по доработкам). */
    public function rating(): void
    {
        Auth::requireLogin();
        if (!Auth::has('visa_worker', 'visa_manager') && !Auth::isAdmin()) { $this->redirect('/'); }
        $period = (string) $this->input('period', date('Y-m'));
        $rows = Database::all(
            "SELECT u.id, u.full_name,
                    SUM(CASE WHEN r.checked_at IS NOT NULL AND substr(r.checked_at,1,7)=? THEN 1 ELSE 0 END) AS checked,
                    COALESCE(SUM(r.rework_count),0) AS reworks
               FROM users u JOIN visa_rows r ON r.assigned_to = u.id
              GROUP BY u.id, u.full_name
              ORDER BY checked DESC, reworks ASC, u.full_name",
            [$period]);
        $rank = 0;
        foreach ($rows as &$r) {
            $r['checked'] = (int) $r['checked'];
            $r['reworks'] = (int) $r['reworks'];
            $r['rank'] = ++$rank;
            $r['quality'] = $r['checked'] > 0 ? round(max(0, $r['checked'] - $r['reworks']) / $r['checked'] * 100) : null;
        }
        unset($r);
        $this->view('visas/rating', [
            'title' => 'Рейтинг по визам',
            'ranking' => $rows, 'period' => $period, 'meId' => Auth::id(),
        ]);
    }

    // ================= СВОДНЫЙ ОТЧЁТ ПО СТАТУСАМ =================

    /** Нормализация страны (свободный текст citizenship): UPPER + trim. */
    private static function normCountry(string $c): string
    {
        return mb_strtoupper(trim($c), 'UTF-8');
    }

    /** Собрать строки сводного отчёта по визовым анкетам с учётом фильтров (status/batch/country/q). */
    private function statusRows(array $f, int $limit = 0): array
    {
        $where = '1=1'; $params = [];
        if (!empty($f['status']) && isset(self::STATUS_LABELS[$f['status']])) { $where .= ' AND r.status = ?'; $params[] = $f['status']; }
        if (!empty($f['batch_id'])) { $where .= ' AND r.batch_id = ?'; $params[] = (int) $f['batch_id']; }
        if (!empty($f['q'])) {
            $q = mb_strtolower(trim((string) $f['q']), 'UTF-8');
            $where .= ' AND (LOWER(r.out_no) LIKE ? OR LOWER(r.surname_lat) LIKE ? OR LOWER(r.surname_ru) LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'])) { $where .= ' AND substr(r.created_at,1,10) >= ?'; $params[] = $f['from']; }
        if (!empty($f['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['to']))   { $where .= ' AND substr(r.created_at,1,10) <= ?'; $params[] = $f['to']; }
        if (!empty($f['checker'])) { $where .= ' AND r.assigned_to = ?'; $params[] = (int) $f['checker']; }
        $rows = Database::all(
            "SELECT r.id, r.out_no, r.citizenship, r.surname_lat, r.names_lat, r.surname_ru, r.status,
                    r.recheck, r.rework_count, r.checked_at, r.created_at, r.mid_refused_at, r.mid_refuse_note,
                    b.name AS batch_name, u_up.full_name AS uploader, u_as.full_name AS checker,
                    o.id AS opis_id, o.instruction_no, o.instruction_date, o.status AS opis_status
               FROM visa_rows r
               LEFT JOIN visa_batches b ON b.id = r.batch_id
               LEFT JOIN users u_up ON u_up.id = b.uploaded_by
               LEFT JOIN users u_as ON u_as.id = r.assigned_to
               LEFT JOIN visa_opis o ON o.id = r.opis_id
              WHERE $where
              ORDER BY r.id DESC", $params);
        // Страна — свободный текст: нормализуем и фильтруем в PHP (единообразно с досками).
        $country = self::normCountry((string) ($f['country'] ?? ''));
        if ($country !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($country) {
                $c = self::normCountry((string) $r['citizenship']); if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
                return $c === $country;
            }));
        }
        if ($limit > 0 && count($rows) > $limit) { $rows = array_slice($rows, 0, $limit); }
        return $rows;
    }

    /** Текущие фильтры отчёта из запроса. */
    private function statusFilters(): array
    {
        return [
            'status'   => (string) $this->input('status', ''),
            'batch_id' => (string) $this->input('batch_id', ''),
            'country'  => (string) $this->input('country', ''),
            'q'        => (string) $this->input('q', ''),
            'from'     => (string) $this->input('from', ''),
            'to'       => (string) $this->input('to', ''),
            'checker'  => (string) $this->input('checker', ''),
        ];
    }

    /** Сводная таблица всех визовых анкет со статусом (для отслеживания) + фильтры + выгрузка. */
    public function statusReport(): void
    {
        Auth::requireLogin();
        if (!$this->isVisaManager(Auth::user())) { $this->redirect('/'); }
        $f = $this->statusFilters();
        $all = $this->input('all') === '1';
        $page = max(1, (int) $this->input('page', 1));
        $perPage = 50;
        $rows = $this->statusRows($f, 0);     // все по фильтрам (страна фильтруется в PHP внутри)
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        if (!$all) { $page = min($page, $pages); $rows = array_slice($rows, ($page - 1) * $perPage, $perPage); }

        $checkers = Database::all("SELECT DISTINCT u.id, u.full_name FROM users u JOIN visa_rows r ON r.assigned_to=u.id ORDER BY u.full_name");
        $batches = Database::all('SELECT id, name FROM visa_batches ORDER BY id DESC');
        $countries = [];
        foreach (Database::all('SELECT citizenship FROM visa_rows') as $r) {
            $c = self::normCountry((string) $r['citizenship']); if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
            $countries[$c] = ($countries[$c] ?? 0) + 1;
        }
        ksort($countries, SORT_LOCALE_STRING);

        $this->view('visas/status_report', [
            'title' => 'Сводный отчёт по визовым анкетам',
            'rows' => $rows,
            'statusLabels' => self::STATUS_LABELS,
            'batches' => $batches,
            'countries' => $countries,
            'filters' => $f,
            'checkers' => $checkers,
            'page' => $page, 'pages' => $pages, 'total' => $total, 'all' => $all, 'perPage' => $perPage,
        ]);
    }

    /** Выгрузка сводного отчёта по статусам в Excel (с учётом текущих фильтров). */
    public function statusReportExport(): void
    {
        Auth::requireLogin();
        if (!$this->isVisaManager(Auth::user())) { $this->redirect('/'); }
        $rows = $this->statusRows($this->statusFilters(), 20000);
        $headers = ['Исх. №', 'Страна', 'Фамилия (лат)', 'Имена (лат)', 'Фамилия (рус)', 'Статус', 'Повторная/доработка',
                    'Партия (загрузка)', 'Кто загрузил', 'Кто проверяет', 'Опись', 'Указание №', 'Дата указания',
                    'Проверено', 'Отказ МИД', 'Создано', 'Комментарий доработки'];
        $data = [];
        foreach ($rows as $r) {
            $recheck = ((int) $r['recheck'] || (int) $r['rework_count'] > 0) ? 'да' : '';
            $data[] = [
                (string) $r['out_no'], (string) $r['citizenship'],
                (string) $r['surname_lat'], (string) $r['names_lat'], (string) $r['surname_ru'],
                self::STATUS_LABELS[$r['status']] ?? (string) $r['status'],
                $recheck,
                (string) ($r['batch_name'] ?? ''), (string) ($r['uploader'] ?? ''), (string) ($r['checker'] ?? ''),
                $r['opis_id'] ? ('#' . (int) $r['opis_id']) : '',
                (string) ($r['instruction_no'] ?? ''), (string) ($r['instruction_date'] ?? ''),
                substr((string) $r['checked_at'], 0, 16), substr((string) $r['mid_refused_at'], 0, 16),
                substr((string) $r['created_at'], 0, 16), (string) ($r['mid_refuse_note'] ?? ''),
            ];
        }
        Xlsx::download('visa-anketas-status-' . date('Y-m-d') . '.xlsx',
            [['name' => 'Статусы виз. анкет', 'headers' => $headers, 'rows' => $data]]);
    }
}
