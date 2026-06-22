<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\VisaDocs;
use App\Services\VisaReworkService;
use App\Services\DocxWriter;
use App\Services\DocxTemplate;
use App\Services\Xlsx;
use App\Services\NotificationService;

/**
 * Формирование описей и гарантийных писем ПО СТРАНАМ (кросс-партийно, по готовым анкетам),
 * визовые указания МИД, возврат на доработку после отказа.
 *
 * Конвейер статусов visa_rows: loaded → assigned → checked → in_opis → instructed; rework — доработка после отказа МИД.
 */
class VisaOpisController extends Controller
{
    private function isVisaManager(array $me): bool
    {
        // Доступ к описям/указаниям/доработке: виз-менеджер или роль «Учёт виз и передача в МИД».
        return Auth::isAdmin() || Auth::has('visa_manager', 'visa_mid');
    }

    private function guard(): array
    {
        Auth::requireLogin();
        $me = Auth::user();
        if (!$this->isVisaManager($me)) { flash('Раздел учёта виз и передачи в МИД.', 'error'); $this->redirect('/'); }
        return $me;
    }

    /** Нормализация названия страны (как при группировке проверенных): UPPER + trim. */
    private static function norm(string $country): string
    {
        return mb_strtoupper(trim($country), 'UTF-8');
    }

    /** Специалисты проекта «визы» (для доски доработки). */
    private static function specialists(): array
    {
        $sp = Database::all(
            "SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM visa_rows r WHERE r.assigned_to=u.id AND r.status IN ('rework','rework_pass')) AS rework
               FROM users u
              WHERE (u.role='employee' OR u.role='admin') AND u.is_active=1
                AND (u.role='admin'
                     OR u.id IN (SELECT user_id FROM user_roles WHERE role_slug='visa_worker'))
              ORDER BY u.full_name");
        return $sp;
    }

    /** Для бейджа меню: сколько строк «на доработке» (после отказа МИД). */
    public static function reworkCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM visa_rows WHERE status IN ('rework','rework_pass')");
    }

    // ============ Формирование описей по странам ============

    /** Доска формирования: выбор страны + кандидаты (проверенные, ещё не в описи). */
    public function board(): void
    {
        $this->guard();
        // Кандидаты — проверенные строки, ещё не включённые ни в одну опись.
        $rows = Database::all("SELECT citizenship FROM visa_rows WHERE status='checked' AND opis_id IS NULL");
        $countries = [];
        foreach ($rows as $r) {
            $c = self::norm((string) $r['citizenship']);
            if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
            $countries[$c] = ($countries[$c] ?? 0) + 1;
        }
        ksort($countries, SORT_LOCALE_STRING);

        $this->view('visas/opis_board', [
            'title' => 'Формирование описей по странам',
            'countries' => $countries,
            'signerName' => VisaDocs::defaultSignerName(),
            'signerPosition' => VisaDocs::defaultSignerPosition(),
            'csrf' => Auth::csrf(),
        ]);
    }

    /** AJAX: кандидаты выбранной страны (проверенные, не в описи). */
    public function items(): void
    {
        $me = Auth::user();
        if (!$me || !$this->isVisaManager($me)) { $this->json(['items' => [], 'total' => 0]); }
        $country = self::norm((string) $this->input('country'));
        $q = mb_strtolower(trim((string) $this->input('q')), 'UTF-8');

        $rows = Database::all(
            "SELECT id, out_no, surname_lat, surname_ru, names_lat, citizenship, batch_id
               FROM visa_rows WHERE status='checked' AND opis_id IS NULL ORDER BY surname_lat, surname_ru, id");
        $out = [];
        foreach ($rows as $r) {
            $c = self::norm((string) $r['citizenship']); if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
            if ($c !== $country) { continue; }
            if ($q !== '') {
                $hay = mb_strtolower((string) $r['out_no'] . ' ' . $r['surname_lat'] . ' ' . $r['surname_ru'], 'UTF-8');
                if (mb_strpos($hay, $q) === false) { continue; }
            }
            $out[] = ['id' => (int) $r['id'], 'out_no' => $r['out_no'], 'surname_lat' => $r['surname_lat'], 'citizenship' => $r['citizenship']];
        }
        $this->json(['items' => $out, 'total' => count($out), 'shown' => count($out)]);
    }

    /** Сформировать опись из выбранных строк выбранной страны. */
    public function create(): void
    {
        $me = $this->guard();
        Auth::verifyCsrf();
        $country = self::norm((string) $this->input('country'));
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        $signerName = trim((string) $this->input('signer_name')) ?: VisaDocs::defaultSignerName();
        $signerPosition = trim((string) $this->input('signer_position')) ?: VisaDocs::defaultSignerPosition();
        if ($country === '' || !$ids) { flash('Выберите страну и хотя бы одну анкету.', 'error'); $this->redirect('/visas/opis'); }

        // Берём только реально доступные строки этой страны (защита от гонок/двойного включения).
        $place = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all("SELECT * FROM visa_rows WHERE id IN ($place) AND status='checked' AND opis_id IS NULL", $ids);
        $valid = [];
        foreach ($rows as $r) {
            $c = self::norm((string) $r['citizenship']); if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
            if ($c === $country) { $valid[] = (int) $r['id']; }
        }
        if (!$valid) { flash('Выбранные анкеты недоступны для формирования (возможно, уже в описи).', 'error'); $this->redirect('/visas/opis'); }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $opisId = Database::insert(
            'INSERT INTO visa_opis (country, signer_name, signer_position, status, created_by) VALUES (?,?,?,?,?)',
            [$country, $signerName, $signerPosition, 'formed', (int) $me['id']]);
        $vplace = implode(',', array_fill(0, count($valid), '?'));
        Database::run("UPDATE visa_rows SET opis_id=?, status='in_opis' WHERE id IN ($vplace) AND status='checked' AND opis_id IS NULL",
            array_merge([$opisId], $valid));
        foreach ($valid as $rid) { \App\Controllers\VisaController::logStatus((int) $rid, 'in_opis'); }
        $pdo->commit();

        flash('Опись сформирована: ' . $country . ' — ' . count($valid) . ' чел. Скачайте документы и после ответа МИД внесите визовое указание.');
        $this->redirect('/visas/opis/' . (int) $opisId);
    }

    // ============ Список описей и карточка ============

    /** Список всех описей с их статусом и указанием. */
    public function index(): void
    {
        $this->guard();
        $list = Database::all(
            "SELECT o.*, (SELECT COUNT(*) FROM visa_rows r WHERE r.opis_id=o.id) AS people
               FROM visa_opis o ORDER BY o.id DESC");
        $this->view('visas/opis_list', ['title' => 'Описи и визовые указания', 'list' => $list]);
    }

    /** Карточка описи: строки, подписант, документы, форма указания/отказов. */
    public function show(string $id): void
    {
        $this->guard();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis) { flash('Опись не найдена.', 'error'); $this->redirect('/visas/opis/list'); }
        $rows = Database::all('SELECT * FROM visa_rows WHERE opis_id=? ORDER BY surname_lat, surname_ru, id', [(int) $id]);
        $editorName = '';
        if (!empty($opis['instruction_edited_by'])) {
            $editorName = (string) Database::scalar('SELECT full_name FROM users WHERE id=?', [(int) $opis['instruction_edited_by']]);
        }
        $this->view('visas/opis_show', [
            'title' => 'Опись — ' . $opis['country'],
            'opis' => $opis,
            'rows' => $rows,
            'price' => VisaReworkService::checkPrice(),
            'editorName' => $editorName,
            'csrf' => Auth::csrf(),
        ]);
    }

    /** ZIP документов описи (ОПИСЬ / ОПИСЬ-СП / ГП + список) с зафиксированным подписантом. */
    public function docs(string $id): void
    {
        $this->guard();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis) { flash('Опись не найдена.', 'error'); $this->redirect('/visas/opis/list'); }
        $rows = Database::all('SELECT * FROM visa_rows WHERE opis_id=? ORDER BY surname_lat, surname_ru, id', [(int) $id]);
        if (!$rows) { flash('В описи нет строк.', 'error'); $this->redirect('/visas/opis/' . (int) $id); }
        @set_time_limit(0);
        $zip = VisaDocs::bundleForCountry((string) $opis['country'], $rows, (string) $opis['signer_name'], (string) $opis['signer_position']);
        $base = VisaDocs::opisFileBase((string) $opis['country']);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="opis-' . (int) $id . '-' . $base . '.zip"');
        header('Content-Length: ' . strlen($zip));
        echo $zip; exit;
    }

    /** Внести визовое указание (№/дата) и отметить отказанные МИД строки → доработка. */
    public function instruction(string $id): void
    {
        $me = $this->guard();
        Auth::verifyCsrf();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis) { flash('Опись не найдена.', 'error'); $this->redirect('/visas/opis/list'); }

        $no = trim((string) $this->input('instruction_no'));
        $date = trim((string) $this->input('instruction_date'));
        $refused = array_values(array_filter(array_map('intval', $_POST['refused'] ?? [])));
        $deduct = $_POST['deduct'] ?? []; // deduct[rowId] = '1' (снять стоимость) | '0'
        $note = trim((string) $this->input('refuse_note'));
        $price = VisaReworkService::checkPrice();

        $rows = Database::all('SELECT * FROM visa_rows WHERE opis_id=?', [(int) $id]);
        $byId = []; foreach ($rows as $r) { $byId[(int) $r['id']] = $r; }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        // Отклонённые строки → доработка + вычет.
        $refusedSet = [];
        foreach ($refused as $rid) {
            if (!isset($byId[$rid])) { continue; }
            $refusedSet[$rid] = true;
            $amount = ((string) ($deduct[$rid] ?? '0') === '1') ? $price : 0.0;
            VisaReworkService::refuse($byId[$rid], (int) $id, $amount, (int) $me['id'], $note !== '' ? $note : 'Отказ МИД');
            \App\Controllers\VisaController::logStatus($rid, 'rework');
        }
        // Остальные строки описи → «указание получено».
        $keep = [];
        foreach ($rows as $r) { if (empty($refusedSet[(int) $r['id']])) { $keep[] = (int) $r['id']; } }
        if ($keep) {
            $kp = implode(',', array_fill(0, count($keep), '?'));
            Database::run("UPDATE visa_rows SET status='instructed' WHERE id IN ($kp)", $keep);
            foreach ($keep as $rid) { \App\Controllers\VisaController::logStatus((int) $rid, 'instructed'); }
        }
        Database::run(
            "UPDATE visa_opis SET instruction_no=?, instruction_date=?, status='instructed', instructed_at=? WHERE id=?",
            [$no, $date, date('Y-m-d H:i:s'), (int) $id]);
        $pdo->commit();

        flash('Указание сохранено' . ($refused ? '. На доработку отправлено строк: ' . count($refused) . '.' : '.'));
        $this->redirect('/visas/opis/' . (int) $id);
    }

    /** Редактировать № и дату УЖЕ внесённого визового указания (с логированием — кто/когда). */
    public function editInstruction(string $id): void
    {
        $me = $this->guard();
        Auth::verifyCsrf();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis) { flash('Опись не найдена.', 'error'); $this->redirect('/visas/opis/list'); }
        if ($opis['status'] !== 'instructed') { flash('Редактировать можно только уже внесённое указание.', 'error'); $this->redirect('/visas/opis/' . (int) $id); }

        $no = trim((string) $this->input('instruction_no'));
        $date = trim((string) $this->input('instruction_date'));
        // Старые значения — в журнал действий (кто/когда фиксирует автолог POST).
        \App\Services\Audit::log(
            'Визы: правка указания (было)',
            'Визы: правка визового указания (опись #' . (int) $id . ', ' . $opis['country'] . ')',
            ['opis_id' => (int) $id, 'country' => $opis['country'],
             'было_№' => $opis['instruction_no'], 'стало_№' => $no,
             'было_дата' => $opis['instruction_date'], 'стало_дата' => $date]);
        Database::run(
            'UPDATE visa_opis SET instruction_no=?, instruction_date=?, instruction_edited_by=?, instruction_edited_at=? WHERE id=?',
            [$no, $date, (int) $me['id'], date('Y-m-d H:i:s'), (int) $id]);
        flash('Визовое указание обновлено. Изменение и автор зафиксированы в журнале действий.');
        $this->redirect('/visas/opis/' . (int) $id);
    }

    /** Убрать человека из уже «указанной» описи → доработка (как отказ; комментарий обязателен). */
    public function refuseFromInstructed(string $id): void
    {
        $me = $this->guard();
        Auth::verifyCsrf();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis) { flash('Опись не найдена.', 'error'); $this->redirect('/visas/opis/list'); }
        if ($opis['status'] !== 'instructed') { flash('Это действие доступно только для описи с внесённым указанием. До указания — кнопка «убрать».', 'error'); $this->redirect('/visas/opis/' . (int) $id); }

        $rowId = (int) $this->input('row_id');
        $comment = trim((string) $this->input('comment'));
        if ($comment === '') { flash('Укажите причину удаления из визового указания.', 'error'); $this->redirect('/visas/opis/' . (int) $id); }
        $row = Database::one("SELECT * FROM visa_rows WHERE id=? AND opis_id=? AND status='instructed'", [$rowId, (int) $id]);
        if (!$row) { flash('Строка не найдена в этой описи (возможно, уже удалена).', 'error'); $this->redirect('/visas/opis/' . (int) $id); }
        $amount = ((string) $this->input('deduct') === '1') ? VisaReworkService::checkPrice() : 0.0;

        VisaReworkService::removeFromInstruction($row, (int) $id, $amount, (int) $me['id'], $comment);
        flash('Человек удалён из визового указания и направлен на доработку (повторно). Распределите его на доске «МИД: на доработке».');
        $this->redirect('/visas/opis/' . (int) $id);
    }

    /** Удалить строку из ещё не «указанной» описи (вернуть в кандидаты). */
    public function removeRow(string $id): void
    {
        $this->guard();
        Auth::verifyCsrf();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis || $opis['status'] !== 'formed') { flash('Менять состав можно только до внесения указания.', 'error'); $this->redirect('/visas/opis/' . (int) $id); }
        $rowId = (int) $this->input('row_id');
        Database::run("UPDATE visa_rows SET opis_id=NULL, status='checked' WHERE id=? AND opis_id=?", [$rowId, (int) $id]);
        flash('Анкета убрана из описи и возвращена в кандидаты.');
        $this->redirect('/visas/opis/' . (int) $id);
    }

    /** Удалить опись целиком (только до внесения указания); строки возвращаются в кандидаты. */
    public function destroy(string $id): void
    {
        $this->guard();
        Auth::verifyCsrf();
        $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [(int) $id]);
        if (!$opis) { $this->redirect('/visas/opis/list'); }
        if ($opis['status'] !== 'formed') { flash('Опись с внесённым указанием удалить нельзя.', 'error'); $this->redirect('/visas/opis/' . (int) $id); }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        Database::run("UPDATE visa_rows SET opis_id=NULL, status='checked' WHERE opis_id=?", [(int) $id]);
        Database::run('DELETE FROM visa_opis WHERE id=?', [(int) $id]);
        $pdo->commit();
        flash('Опись удалена, анкеты возвращены в кандидаты на формирование.');
        $this->redirect('/visas/opis/list');
    }

    // ============ Доработка после отказа МИД ============

    /** Доска распределения строк «на доработке» специалистам (повторная проверка). */
    public function reworkBoard(): void
    {
        $this->guard();
        // Доступные страны среди строк на доработке (для фильтра «передать по одной стране»).
        $countries = [];
        foreach (Database::all("SELECT citizenship FROM visa_rows WHERE status IN ('rework','rework_pass')") as $r) {
            $c = self::norm((string) $r['citizenship']); if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
            $countries[$c] = ($countries[$c] ?? 0) + 1;
        }
        ksort($countries, SORT_LOCALE_STRING);
        $this->view('visas/rework_board', [
            'title' => 'МИД: строки на доработке',
            'specialists' => self::specialists(),
            'countries' => $countries,
            'csrf' => Auth::csrf(),
        ]);
    }

    /** AJAX: строки «на доработке» (пул или у специалиста), опц. фильтр по стране. */
    public function reworkItems(): void
    {
        $me = Auth::user();
        if (!$me || !$this->isVisaManager($me)) { $this->json(['items' => [], 'total' => 0]); }
        $owner = (string) $this->input('owner', 'pool');
        $q = mb_strtolower(trim((string) $this->input('q')), 'UTF-8');
        $country = self::norm((string) $this->input('country'));

        $where = "status IN ('rework','rework_pass')";
        $params = [];
        if ($owner === 'pool') { $where .= ' AND assigned_to IS NULL'; }
        else { $where .= ' AND assigned_to = ?'; $params[] = (int) $owner; }
        if ($q !== '') { $where .= ' AND (LOWER(out_no) LIKE ? OR LOWER(surname_lat) LIKE ? OR LOWER(surname_ru) LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }

        // Гражданство — свободный текст; нормализуем и фильтруем по стране в PHP (как на доске описей),
        // чтобы поведение совпадало в SQLite и PostgreSQL. Фильтр до отсечения LIMIT.
        $rows = Database::all("SELECT id, out_no, surname_lat, citizenship, excluded_user, mid_refuse_note FROM visa_rows WHERE $where ORDER BY id", $params);
        if ($country !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($country) {
                $c = self::norm((string) $r['citizenship']); if ($c === '') { $c = 'БЕЗ СТРАНЫ'; }
                return $c === $country;
            }));
        }
        $total = count($rows);
        $shown = array_slice($rows, 0, 400);
        $this->json(['items' => $shown, 'total' => $total, 'shown' => count($shown)]);
    }

    /** AJAX: назначить строки на доработке специалисту (нельзя первичному проверяющему). */
    public function reworkMove(): void
    {
        $me = Auth::user();
        if (!$me || !$this->isVisaManager($me)) { $this->json(['ok' => false]); }
        Auth::verifyCsrf();
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        $to = (string) $this->input('to', 'pool');
        if (!$ids) { $this->json(['ok' => false, 'moved' => 0, 'message' => 'Ничего не выбрано']); }
        $assignTo = $to === 'pool' ? null : (int) $to;
        if ($assignTo !== null && !Database::scalar('SELECT 1 FROM users WHERE id=? AND is_active=1', [$assignTo])) {
            $this->json(['ok' => false, 'message' => 'Получатель не найден']);
        }

        // Повторную проверку нельзя назначать первичному проверяющему (excluded_user).
        $skipped = 0;
        if ($assignTo) {
            $place0 = implode(',', array_fill(0, count($ids), '?'));
            $bad = array_map(fn($r) => (int) $r['id'], Database::all(
                "SELECT id FROM visa_rows WHERE id IN ($place0) AND excluded_user = ?", array_merge($ids, [$assignTo])));
            $skipped = count($bad);
            $ids = array_values(array_diff($ids, $bad));
            if (!$ids) { $this->json(['ok' => false, 'moved' => 0, 'message' => 'Повторную проверку нельзя назначать первичному проверяющему (он допустил брак).']); }
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::run("UPDATE visa_rows SET assigned_to = ? WHERE id IN ($place) AND status IN ('rework','rework_pass')",
            array_merge([$assignTo], $ids));
        $moved = $stmt->rowCount();
        if ($assignTo && $moved) {
            NotificationService::create($assignTo, 'Назначена повторная проверка виз', "Вам назначено {$moved} строк на доработку после отказа МИД.");
        }
        $this->json(['ok' => true, 'moved' => $moved, 'skipped' => $skipped]);
    }
}
