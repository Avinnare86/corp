<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\ListParser;
use App\Services\RegRangeParser;
use App\Services\Tariff;
use App\Services\NotificationService;

class ManagerController extends Controller
{
    /** Доска распределения. */
    public function index(): void
    {
        Auth::requireRole('anketa_manager', 'admin');

        $lists = Database::all(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM assignment_items a WHERE a.list_id=l.id) AS total,
                    (SELECT COUNT(*) FROM assignment_items a WHERE a.list_id=l.id AND a.assigned_to IS NULL) AS unassigned,
                    (SELECT COUNT(*) FROM assignment_items a WHERE a.list_id=l.id AND a.checked_at IS NOT NULL) AS checked
               FROM assignment_lists l ORDER BY l.id DESC"
        );

        // Нераспределённые — по странам.
        $unassignedByCountry = Database::all(
            "SELECT ai.country_code, c.name, COUNT(*) AS cnt
               FROM assignment_items ai
               LEFT JOIN countries c ON c.code = ai.country_code
              WHERE ai.assigned_to IS NULL
              GROUP BY ai.country_code, c.name
              ORDER BY cnt DESC"
        );
        $totalUnassigned = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE assigned_to IS NULL');

        // Сотрудники с правом проверки анкет (роль anketa_worker) — только им можно распределять.
        $employees = Database::all(
            "SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM assignment_items a WHERE a.assigned_to=u.id) AS assigned,
                    (SELECT COUNT(*) FROM assignment_items a WHERE a.assigned_to=u.id AND a.checked_at IS NOT NULL) AS checked
               FROM users u
               JOIN user_roles r ON r.user_id = u.id AND r.role_slug = 'anketa_worker'
              WHERE u.is_active = 1
              ORDER BY u.full_name"
        );
        foreach ($employees as &$e) { $e['remaining'] = (int) $e['assigned'] - (int) $e['checked']; }
        unset($e);

        // Подсказка «кого догружать» — минимальный остаток.
        $minRemaining = null;
        foreach ($employees as $e) { if ($minRemaining === null || $e['remaining'] < $minRemaining) { $minRemaining = $e['remaining']; } }

        $allCountries = Database::all(
            "SELECT ai.country_code, c.name FROM assignment_items ai
             LEFT JOIN countries c ON c.code=ai.country_code
             GROUP BY ai.country_code, c.name ORDER BY ai.country_code"
        );

        $this->view('manager/board', [
            'title' => 'Распределение проверки',
            'lists' => $lists,
            'unassignedByCountry' => $unassignedByCountry,
            'totalUnassigned' => $totalUnassigned,
            'employees' => $employees,
            'minRemaining' => $minRemaining,
            'allCountries' => $allCountries,
            'arrivalLines' => Database::all("SELECT id, code, name FROM arrival_lines WHERE is_active=1 ORDER BY code"),
            'arrivalDetails' => Database::all("SELECT id, text FROM arrival_details WHERE is_active=1 ORDER BY text"),
            'csrf' => \App\Core\Auth::csrf(),
        ]);
    }

    /** Отчёт по прогрессу проверки. */
    public function report(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        $from = (string) $this->input('from', '');
        $to   = (string) $this->input('to', '');
        [$overall, $byEmployee, $byList] = $this->reportData($from, $to);
        $this->view('manager/report', [
            'title' => 'Отчёт по проверке',
            'overall' => $overall,
            'byEmployee' => $byEmployee,
            'byList' => $byList,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Данные отчёта по проверке. Фильтр по дате — на дату поступления анкеты (created_at),
     * чтобы все показатели (всего/проверено/остаток/%) относились к одной когорте — анкетам,
     * загруженным/назначенным за период. Пустые from/to = за всё время.
     * @return array{0:array,1:array,2:array} [overall, byEmployee, byList]
     */
    private function reportData(string $from, string $to): array
    {
        $dCond = ''; $dp = [];
        if ($from !== '') { $dCond .= ' AND ai.created_at >= ?'; $dp[] = $from . ' 00:00:00'; }
        if ($to !== '')   { $dCond .= ' AND ai.created_at <= ?'; $dp[] = $to . ' 23:59:59'; }

        $overall = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN ai.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                    SUM(CASE WHEN ai.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned
               FROM assignment_items ai WHERE 1=1" . $dCond,
            $dp
        );
        $byEmployee = Database::all(
            "SELECT u.full_name,
                    COUNT(ai.id) AS assigned,
                    SUM(CASE WHEN ai.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked
               FROM users u
               JOIN assignment_items ai ON ai.assigned_to = u.id
              WHERE u.role IN ('employee','admin')" . $dCond . "
              GROUP BY u.id, u.full_name
              ORDER BY u.full_name",
            $dp
        );
        // Фильтр по дате — в ON, чтобы списки без анкет за период оставались в отчёте (с нулями).
        $byList = Database::all(
            "SELECT l.name,
                    COUNT(ai.id) AS total,
                    SUM(CASE WHEN ai.checked_at IS NOT NULL THEN 1 ELSE 0 END) AS checked,
                    SUM(CASE WHEN ai.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned
               FROM assignment_lists l
               LEFT JOIN assignment_items ai ON ai.list_id = l.id" . $dCond . "
              GROUP BY l.id, l.name ORDER BY l.id DESC",
            $dp
        );
        return [$overall, $byEmployee, $byList];
    }

    /** Выгрузка отчёта в Excel (по сотрудникам + по спискам), с учётом фильтра по дате. */
    public function reportExport(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        $from = (string) $this->input('from', '');
        $to   = (string) $this->input('to', '');
        [, $byEmployee, $byList] = $this->reportData($from, $to);
        $empRows = [];
        foreach ($byEmployee as $e) {
            $a=(int)$e['assigned']; $c=(int)$e['checked'];
            $empRows[] = [$e['full_name'], $a, $c, $a-$c, $a>0?round($c/$a*100).'%':'0%'];
        }
        $listRows = [];
        foreach ($byList as $l) {
            $t=(int)$l['total']; $c=(int)$l['checked'];
            $listRows[] = [$l['name'], $t, $c, (int)$l['unassigned'], $t>0?round($c/$t*100).'%':'0%'];
        }
        $suffix = ($from !== '' || $to !== '') ? (($from ?: '…') . '_' . ($to ?: '…')) : date('Y-m-d');
        \App\Services\Xlsx::download('report-' . $suffix . '.xlsx', [
            ['name'=>'По сотрудникам','headers'=>['Сотрудник','Назначено','Проверено','Остаток','Прогресс'],'rows'=>$empRows],
            ['name'=>'По спискам','headers'=>['Список','Всего','Проверено','Не распределено','Прогресс'],'rows'=>$listRows],
        ]);
    }

    /**
     * Строки отчёта «качество проверки» в разрезе специалистов: ошибки, выявленные при проверке
     * (анкеты с доработками), и ошибки, выявленные при последующем контроле (inspections.is_correct=0),
     * с фильтром по периоду (месяц checked_at) и по стране (country_code).
     */
    private function qualityRows(string $period, string $country, string $from = '', string $to = ''): array
    {
        $where = 'ai.checked_at IS NOT NULL AND ai.assigned_to IS NOT NULL';
        $params = [];
        // Диапазон дат (с/по) имеет приоритет над выбором месяца.
        if ($from !== '' || $to !== '') {
            if ($from !== '') { $where .= ' AND ai.checked_at >= ?'; $params[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $where .= ' AND ai.checked_at <= ?'; $params[] = $to . ' 23:59:59'; }
        } elseif ($period !== '') { $where .= ' AND substr(ai.checked_at,1,7)=?'; $params[] = $period; }
        if ($country !== '') { $where .= ' AND ai.country_code=?'; $params[] = $country; }
        $rows = Database::all(
            "SELECT ai.assigned_to AS uid, u.full_name,
                    COUNT(*) AS checked,
                    SUM(CASE WHEN ai.comment_id IS NOT NULL
                                OR EXISTS(SELECT 1 FROM item_comments ic WHERE ic.item_id=ai.id)
                             THEN 1 ELSE 0 END) AS check_err,
                    SUM(CASE WHEN insp.iid IS NOT NULL THEN 1 ELSE 0 END) AS inspected,
                    SUM(CASE WHEN insp.err = 1 THEN 1 ELSE 0 END) AS ctrl_err
               FROM assignment_items ai
               JOIN users u ON u.id = ai.assigned_to
               LEFT JOIN (
                    SELECT dossier_id, MAX(id) AS iid, MAX(CASE WHEN is_correct=0 THEN 1 ELSE 0 END) AS err
                      FROM inspections WHERE is_correct IS NOT NULL GROUP BY dossier_id
               ) insp ON insp.dossier_id = ai.id
              WHERE $where
              GROUP BY ai.assigned_to, u.full_name
              HAVING COUNT(*) > 0
              ORDER BY u.full_name", $params);
        foreach ($rows as &$r) {
            $checked = (int) $r['checked']; $insp = (int) $r['inspected'];
            $r['check_err'] = (int) $r['check_err']; $r['ctrl_err'] = (int) $r['ctrl_err'];
            $r['inspected'] = $insp;
            $r['check_pct'] = $checked > 0 ? round($r['check_err'] / $checked * 100, 1) : 0.0;
            $r['ctrl_pct']  = $insp > 0 ? round($r['ctrl_err'] / $insp * 100, 1) : null;
        }
        unset($r);
        return $rows;
    }

    /** Отчёт о проверке в разрезе специалистов (ошибки при проверке и при контроле) с фильтрами. */
    public function qualityReport(): void
    {
        Auth::requireRole('anketa_manager', 'controller', 'director', 'deputy_director', 'admin');
        $period  = (string) $this->input('period', '');
        $country = (string) $this->input('country', '');
        $from = (string) $this->input('from', '');
        $to = (string) $this->input('to', '');
        $rows = $this->qualityRows($period, $country, $from, $to);
        $tot = ['checked' => 0, 'check_err' => 0, 'inspected' => 0, 'ctrl_err' => 0];
        foreach ($rows as $r) { foreach (['checked', 'check_err', 'inspected', 'ctrl_err'] as $k) { $tot[$k] += (int) $r[$k]; } }
        $tot['check_pct'] = $tot['checked'] > 0 ? round($tot['check_err'] / $tot['checked'] * 100, 1) : 0.0;
        $tot['ctrl_pct']  = $tot['inspected'] > 0 ? round($tot['ctrl_err'] / $tot['inspected'] * 100, 1) : null;
        $this->view('manager/quality', [
            'title'     => 'Отчёт о проверке (в разрезе специалистов)',
            'rows'      => $rows,
            'tot'       => $tot,
            'period'    => $period,
            'country'   => $country,
            'from'      => $from,
            'to'        => $to,
            'periods'   => array_column(Database::all("SELECT DISTINCT substr(checked_at,1,7) AS p FROM assignment_items WHERE checked_at IS NOT NULL ORDER BY p DESC"), 'p'),
            'countries' => Database::all("SELECT ai.country_code AS code, c.name FROM assignment_items ai LEFT JOIN countries c ON c.code=ai.country_code WHERE ai.country_code IS NOT NULL AND ai.country_code<>'' GROUP BY ai.country_code, c.name ORDER BY c.name, ai.country_code"),
            'countryName' => $country !== '' ? (string) Database::scalar('SELECT name FROM countries WHERE code=?', [$country]) : '',
        ]);
    }

    /**
     * Выгрузка отчёта о проверке в Excel (с учётом фильтров периода/дат/страны).
     * Лист 1 «Сводка» — как на странице; лист 2 «Детально по анкетам» — каждая
     * проверенная анкета: специалист, рег.номер, страна, дата, первичная/повтор,
     * результат проверки и замечание, результат контроля (тип ошибки, комментарий,
     * снижение, повтор №), дата контроля.
     */
    public function qualityReportExport(): void
    {
        Auth::requireRole('anketa_manager', 'controller', 'director', 'deputy_director', 'admin');
        $period  = (string) $this->input('period', '');
        $country = (string) $this->input('country', '');
        $from = (string) $this->input('from', '');
        $to = (string) $this->input('to', '');

        // --- Лист 1: сводка по специалистам ---
        $summary = [];
        foreach ($this->qualityRows($period, $country, $from, $to) as $r) {
            $summary[] = [
                $r['full_name'], (int) $r['checked'], (int) $r['check_err'], $r['check_pct'] . '%',
                (int) $r['inspected'], (int) $r['ctrl_err'], $r['ctrl_pct'] === null ? '—' : $r['ctrl_pct'] . '%',
            ];
        }

        // --- Лист 2: детально по каждой анкете ---
        $detail = [];
        foreach ($this->qualityDetailRows($period, $country, $from, $to) as $d) {
            $ctrl = $d['insp_id'] === null ? 'Не контролировалась'
                  : ($d['ctrl_correct'] === null ? 'На контроле (не завершён)'
                  : ((int) $d['ctrl_correct'] === 1 ? 'Корректно' : 'Ошибка'));
            $detail[] = [
                $d['full_name'],
                $d['reg_number'],
                $d['country_code'],
                $d['country_name'] ?: $d['country_code'],
                $d['checked_day'],
                ((int) $d['is_recheck'] === 1) ? 'Повтор' : 'Первичная',
                ((int) $d['has_dorabotka'] === 1) ? 'Доработка' : 'Без замечаний',
                (string) ($d['comment_text'] ?? ''),
                $ctrl,
                (string) ($d['ctrl_error'] ?? ''),
                (string) ($d['controller_comment'] ?? ''),
                ($d['penalty_amount'] !== null && (float) $d['penalty_amount'] != 0.0) ? (float) $d['penalty_amount'] : '',
                ((int) ($d['occurrence'] ?? 0) > 1) ? (int) $d['occurrence'] : '',
                (string) ($d['ctrl_day'] ?? ''),
            ];
        }

        \App\Services\Xlsx::download('proverka-' . ($country ?: 'all') . '-' . ($period ?: 'all') . '.xlsx', [
            [
                'name'    => 'Сводка',
                'headers' => ['Специалист', 'Проверено', 'Ошибок при проверке', '% ошибок (проверка)', 'Проконтролировано', 'Ошибок при контроле', '% ошибок (контроль)'],
                'rows'    => $summary,
            ],
            [
                'name'    => 'Детально по анкетам',
                'headers' => ['Специалист', 'Рег. номер', 'Код страны', 'Страна', 'Дата проверки', 'Вид проверки',
                              'Результат проверки', 'Замечание специалиста', 'Контроль', 'Тип ошибки (контроль)',
                              'Комментарий контролёра', 'Снижение, ₽', 'Повтор № (контроль)', 'Дата контроля'],
                'rows'    => $detail,
            ],
        ]);
    }

    /**
     * Детально по каждой проверенной анкете для выгрузки (те же фильтры, что и сводка).
     * Одна инспекция на досье (inspections.dossier_id UNIQUE) — прямой LEFT JOIN.
     */
    private function qualityDetailRows(string $period, string $country, string $from = '', string $to = ''): array
    {
        $where = 'ai.checked_at IS NOT NULL AND ai.assigned_to IS NOT NULL';
        $params = [];
        if ($from !== '' || $to !== '') {
            if ($from !== '') { $where .= ' AND ai.checked_at >= ?'; $params[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $where .= ' AND ai.checked_at <= ?'; $params[] = $to . ' 23:59:59'; }
        } elseif ($period !== '') { $where .= ' AND substr(ai.checked_at,1,7)=?'; $params[] = $period; }
        if ($country !== '') { $where .= ' AND ai.country_code=?'; $params[] = $country; }
        return Database::all(
            "SELECT u.full_name, ai.reg_number, ai.country_code, c.name AS country_name,
                    substr(ai.checked_at,1,10) AS checked_day,
                    CASE WHEN COALESCE(ai.recheck,0)=1 THEN 1 ELSE 0 END AS is_recheck,
                    CASE WHEN ai.comment_id IS NOT NULL
                            OR EXISTS(SELECT 1 FROM item_comments ic WHERE ic.item_id=ai.id)
                         THEN 1 ELSE 0 END AS has_dorabotka,
                    ai.comment_text,
                    i.id AS insp_id, i.is_correct AS ctrl_correct, i.penalty_amount, i.occurrence,
                    i.controller_comment, substr(i.reviewed_at,1,10) AS ctrl_day,
                    et.name AS ctrl_error
               FROM assignment_items ai
               JOIN users u ON u.id = ai.assigned_to
               LEFT JOIN countries c ON c.code = ai.country_code
               LEFT JOIN inspections i ON i.dossier_id = ai.id
               LEFT JOIN error_types et ON et.id = i.error_type_id
              WHERE $where
              ORDER BY u.full_name, ai.checked_at DESC", $params);
    }

    /** Раскрытие специалиста в отчёте о проверке: его проверенные анкеты (с доработкой и вердиктом контроля). Partial. */
    public function qualityDossiers(): void
    {
        Auth::requireRole('anketa_manager', 'controller', 'director', 'deputy_director', 'admin');
        $uid = (int) $this->input('uid', 0);
        if (!$uid) { echo ''; return; }
        $period = (string) $this->input('period', '');
        $country = (string) $this->input('country', '');
        $from = (string) $this->input('from', '');
        $to = (string) $this->input('to', '');
        $where = 'ai.assigned_to = ? AND ai.checked_at IS NOT NULL';
        $p = [$uid];
        if ($from !== '' || $to !== '') {
            if ($from !== '') { $where .= ' AND ai.checked_at >= ?'; $p[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $where .= ' AND ai.checked_at <= ?'; $p[] = $to . ' 23:59:59'; }
        } elseif ($period !== '') { $where .= ' AND substr(ai.checked_at,1,7)=?'; $p[] = $period; }
        if ($country !== '') { $where .= ' AND ai.country_code=?'; $p[] = $country; }
        $rows = Database::all(
            "SELECT ai.reg_number, ai.country_code, substr(ai.checked_at,1,10) AS checked_day, ai.comment_text,
                    CASE WHEN ai.comment_id IS NOT NULL OR EXISTS(SELECT 1 FROM item_comments ic WHERE ic.item_id=ai.id) THEN 1 ELSE 0 END AS has_dorabotka,
                    (SELECT i.is_correct FROM inspections i WHERE i.dossier_id=ai.id AND i.is_correct IS NOT NULL ORDER BY i.id DESC LIMIT 1) AS ctrl_correct,
                    (SELECT et.name FROM inspections i LEFT JOIN error_types et ON et.id=i.error_type_id WHERE i.dossier_id=ai.id AND i.is_correct=0 ORDER BY i.id DESC LIMIT 1) AS ctrl_error
               FROM assignment_items ai
              WHERE $where
              ORDER BY ai.checked_at DESC
              LIMIT 1000", $p);
        $this->view('manager/_quality_dossiers', ['rows' => $rows], false);
    }

    /** Загрузка списка (docx/xlsx/csv/txt). */
    public function upload(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        Auth::verifyCsrf();

        if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            flash('Выберите файл со списком.', 'error');
            $this->redirect('/manager');
        }
        $orig = $_FILES['file']['name'];
        $name = trim((string) $this->input('name')) ?: pathinfo($orig, PATHINFO_FILENAME);

        // Структурный разбор: рег. номера + детализированная линия прибытия (ДЛП) по секциям файла.
        $rows = ListParser::extractStructured($_FILES['file']['tmp_name'], $orig);
        if (!$rows) {
            flash('В файле не найдено рег. номеров формата КОД-НОМЕР/ГОД.', 'error');
            $this->redirect('/manager');
        }

        @set_time_limit(0);
        $listId = Database::insert('INSERT INTO assignment_lists (name, uploaded_by) VALUES (?,?)', [$name, Auth::id()]);
        $added = 0; $dup = 0; $withLine = 0;

        // Транзакция: один fsync вместо тысячи — критично для больших списков (1000+ строк).
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // ЛП для загрузок «детализированного Плана приема» — всегда ПП (План приема).
            $ppId = ArrivalController::resolveLine('ПП', 'План приема');
            $detailCache = [];   // текст ДЛП → id (чтобы не плодить запросы/дубли)
            $check = $pdo->prepare('SELECT 1 FROM assignment_items WHERE reg_number = ?');
            $ins = $pdo->prepare('INSERT INTO assignment_items (list_id, reg_number, country_code, arrival_line_id, arrival_detail_id) VALUES (?,?,?,?,?)');
            foreach ($rows as $row) {
                $reg = strtoupper(trim($row['reg']));
                $code = explode('-', $reg)[0] ?? '';
                $check->execute([$reg]);
                if ($check->fetchColumn()) { $dup++; continue; }
                $lineCode = trim((string) ($row['line'] ?? ''));
                $detail = trim((string) ($row['detail'] ?? ''));
                $lineId = null; $detailId = null;
                if ($lineCode !== '') { $lineId = $ppId; $withLine++; }   // линия (ПП) — даже без ДЛП
                if ($detail !== '') {
                    if (!array_key_exists($detail, $detailCache)) { $detailCache[$detail] = ArrivalController::resolveDetail($detail); }
                    $detailId = $detailCache[$detail];
                }
                $ins->execute([$listId, $reg, $code, $lineId, $detailId]);
                $added++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($this->isXhr()) { $this->json(['ok' => false, 'message' => 'Ошибка при загрузке: ' . $e->getMessage()]); }
            flash('Ошибка при загрузке списка.', 'error');
            $this->redirect('/manager');
        }
        $msg = "Список «{$name}» загружен: добавлено {$added}" . ($dup ? ", пропущено дубликатов {$dup}" : '')
            . ($withLine ? ", с линией прибытия (ПП) {$withLine}" : '') . '.';
        if ($this->isXhr()) {
            $this->json(['ok' => true, 'added' => $added, 'dup' => $dup, 'message' => $msg]);
        }
        flash($msg);
        $this->redirect('/manager');
    }

    /** Ручной ввод анкет: одиночные номера, перечисление и диапазоны (КОД-НОМЕР/ГОД). */
    public function manualAdd(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        Auth::verifyCsrf();

        $parsed = RegRangeParser::parse((string) $this->input('regs'));
        if (!$parsed['regs']) {
            $hint = $parsed['bad'] ? ' Нераспознано: ' . implode(', ', array_slice($parsed['bad'], 0, 10)) : '';
            flash('Не распознано ни одного рег. номера. Формат: КОД-НОМЕР/ГОД; диапазон через тире.' . $hint, 'error');
            $this->redirect('/manager');
        }
        $name = trim((string) $this->input('name')) ?: ('Ручной ввод ' . date('d.m.Y H:i'));

        // Линия прибытия — одна на весь пакет: выбор из справочника или новая (добавится в справочник).
        $lineId = $this->input('line_id') ? (int) $this->input('line_id') : null;
        $newLine = trim((string) $this->input('line_code'));
        if (!$lineId && $newLine !== '') { $lineId = ArrivalController::resolveLine($newLine); }
        $detailId = $this->input('detail_id') ? (int) $this->input('detail_id') : null;
        $newDetail = trim((string) $this->input('detail_text'));
        if (!$detailId && $newDetail !== '') { $detailId = ArrivalController::resolveDetail($newDetail); }

        @set_time_limit(0);
        $listId = Database::insert('INSERT INTO assignment_lists (name, uploaded_by) VALUES (?,?)', [$name, Auth::id()]);
        $added = 0; $dup = 0;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT 1 FROM assignment_items WHERE reg_number = ?');
            $ins = $pdo->prepare('INSERT INTO assignment_items (list_id, reg_number, country_code, arrival_line_id, arrival_detail_id) VALUES (?,?,?,?,?)');
            foreach ($parsed['regs'] as $reg) {
                $check->execute([$reg]);
                if ($check->fetchColumn()) { $dup++; continue; }
                $ins->execute([$listId, $reg, RegRangeParser::countryCode($reg), $lineId, $detailId]);
                $added++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            flash('Ошибка при добавлении анкет.', 'error');
            $this->redirect('/manager');
        }
        // Пустой список (всё дубликаты/нераспознано) не оставляем.
        if (!$added) { Database::run('DELETE FROM assignment_lists WHERE id = ?', [$listId]); }

        if (!$added) {
            flash('Новых анкет не добавлено: ' . ($dup ? "все {$dup} уже есть в системе (дубликаты)." : 'номера не распознаны.'), 'error');
        } else {
            $msg = "Список «{$name}» создан вручную: добавлено {$added}"
                . ($dup ? ", пропущено дубликатов {$dup}" : '')
                . ($parsed['bad'] ? ', нераспознано ' . count($parsed['bad']) : '') . '.';
            flash($msg);
        }
        $this->redirect('/manager');
    }

    private function isXhr(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
    }

    /** AJAX: непроверенные досье источника (корзина или сотрудник) с фильтрами. */
    public function items(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        $owner = (string) $this->input('owner', 'pool');
        $listId = $this->input('list_id');
        $country = trim((string) $this->input('country'));
        $q = trim((string) $this->input('q'));
        $limit = min(1000, max(10, (int) $this->input('limit', 300)));

        $where = 'checked_at IS NULL';
        $params = [];
        if ($owner === 'pool') {
            $where .= ' AND assigned_to IS NULL';
        } else {
            $where .= ' AND assigned_to = ?';
            $params[] = (int) $owner;
        }
        if ($listId)        { $where .= ' AND list_id = ?';      $params[] = (int) $listId; }
        if ($country !== '') { $where .= ' AND country_code = ?'; $params[] = $country; }
        if ($q !== '')      { $where .= ' AND reg_number LIKE ?'; $params[] = '%' . strtoupper($q) . '%'; }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM assignment_items WHERE $where", $params);
        $rows = Database::all(
            "SELECT ai.id, ai.reg_number, ai.country_code, ai.recheck, ai.excluded_user, al.code AS aline, ad.text AS adet
               FROM assignment_items ai
               LEFT JOIN arrival_lines al ON al.id = ai.arrival_line_id
               LEFT JOIN arrival_details ad ON ad.id = ai.arrival_detail_id
              WHERE $where ORDER BY ai.recheck DESC, ai.country_code, ai.id LIMIT $limit",
            $params
        );
        foreach ($rows as &$r) { $r['arrival'] = arrival_label($r['aline'] ?? null, $r['adet'] ?? null); }
        unset($r);
        $this->json(['items' => $rows, 'total' => $total, 'shown' => count($rows)]);
    }

    /** AJAX: перенести выбранные досье к назначению (pool или сотрудник). */
    public function move(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        Auth::verifyCsrf();
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        $to = (string) $this->input('to', 'pool');
        if (!$ids) {
            $this->json(['ok' => false, 'moved' => 0, 'message' => 'Ничего не выбрано']);
        }
        $assignTo = $to === 'pool' ? null : (int) $to;

        // повторные проверки нельзя отдавать допустившему брак специалисту
        $skipped = 0;
        if ($assignTo) {
            $place0 = implode(',', array_fill(0, count($ids), '?'));
            $bad = array_map(fn($r) => (int) $r['id'], Database::all(
                "SELECT id FROM assignment_items WHERE id IN ($place0) AND excluded_user = ?",
                array_merge($ids, [$assignTo])));
            $skipped = count($bad);
            $ids = array_values(array_diff($ids, $bad));
            if (!$ids) { $this->json(['ok' => false, 'moved' => 0, 'message' => 'Повторную проверку нельзя назначать этому специалисту (он допустил брак)']); }
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$assignTo], $ids);
        $stmt = Database::run(
            "UPDATE assignment_items SET assigned_to = ? WHERE id IN ($place) AND checked_at IS NULL",
            $params
        );
        $moved = $stmt->rowCount();
        if ($skipped) { /* часть пропущена из-за исключённого специалиста */ }
        if ($assignTo && $moved > 0) {
            NotificationService::create($assignTo, 'Назначены новые досье', "Вам назначено {$moved} досье на проверку.");
        }
        $this->json(['ok' => true, 'moved' => $moved]);
    }

    /** Распределение: одному или поровну между выбранными. */
    public function distribute(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        Auth::verifyCsrf();

        $listId  = $this->input('list_id') ? (int) $this->input('list_id') : null;
        $country = trim((string) $this->input('country'));
        $mode    = $this->input('mode', 'one');

        $where = 'assigned_to IS NULL';
        $params = [];
        if ($listId)  { $where .= ' AND list_id = ?';      $params[] = $listId; }
        if ($country !== '') { $where .= ' AND country_code = ?'; $params[] = $country; }

        $ids = array_map(fn($r) => (int) $r['id'],
            Database::all("SELECT id FROM assignment_items WHERE $where ORDER BY id", $params));
        if (!$ids) {
            flash('Нет нераспределённых досье по выбранному фильтру.', 'error');
            $this->redirect('/manager');
        }

        if ($mode === 'one') {
            $emp = (int) $this->input('employee_id');
            if (!$emp) { flash('Выберите сотрудника.', 'error'); $this->redirect('/manager'); }
            $counts = $this->assignMany($ids, [$emp]);
            flash('Назначено ' . count($ids) . ' досье одному сотруднику.');
        } else {
            $members = array_map('intval', $_POST['members'] ?? []);
            $members = array_values(array_filter($members));
            if (!$members) { flash('Выберите пул сотрудников для деления.', 'error'); $this->redirect('/manager'); }
            $counts = $this->assignMany($ids, $members);
            flash('Распределено поровну: ' . count($ids) . ' досье между ' . count($members) . ' сотр.');
        }
        // Уведомления назначенным сотрудникам.
        foreach ($counts as $emp => $cnt) {
            if ($cnt > 0) {
                NotificationService::create((int) $emp, 'Назначены новые досье', "Вам назначено {$cnt} досье на проверку.");
            }
        }
        $this->redirect('/manager');
    }

    /** Round-robin назначение списка id по сотрудникам (повторные — не виновнику). Возвращает [emp_id => кол-во]. */
    private function assignMany(array $ids, array $employees): array
    {
        $n = count($employees);
        $counts = array_fill_keys($employees, 0);
        $excluded = [];
        foreach (Database::all('SELECT id, excluded_user FROM assignment_items WHERE excluded_user IS NOT NULL AND id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')') as $r) {
            $excluded[(int) $r['id']] = (int) $r['excluded_user'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE assignment_items SET assigned_to = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $emp = $employees[$i % $n];
            if (isset($excluded[$id]) && $excluded[$id] === (int) $emp) {
                $emp = $n > 1 ? $employees[($i + 1) % $n] : null; // сдвигаем на следующего
                if ($emp === null) { continue; } // единственный получатель — виновник: оставить в пуле
            }
            $stmt->execute([$emp, $id]);
            $counts[$emp]++;
        }
        $pdo->commit();
        return $counts;
    }

    /** Снять распределение по сотруднику (вернуть непроверенные в пул). */
    public function recall(): void
    {
        Auth::requireRole('anketa_manager', 'admin');
        Auth::verifyCsrf();
        $emp = (int) $this->input('employee_id');
        Database::run('UPDATE assignment_items SET assigned_to = NULL WHERE assigned_to = ? AND checked_at IS NULL', [$emp]);
        flash('Непроверенные досье сотрудника возвращены в пул.');
        $this->redirect('/manager');
    }
}
