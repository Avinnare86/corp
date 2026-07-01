<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Auth;
use App\Controllers\VisaController;
use App\Controllers\TabelController;

/**
 * Управление данными для администратора: удаление любой записи и откат статуса на шаг назад.
 *
 * В схеме НЕТ внешних ключей (FK), поэтому все каскады и откаты выполняются вручную и
 * оборачиваются в транзакцию. Откат «опасных» записей (подписанный табель, опись с уже
 * внесённым указанием и вычетами, утверждённая служебка) выполняется с авто-отменой
 * последствий: снимаются вычеты visa_deductions, пересчитывается месячный табель, очищаются
 * подписи. Каждое действие логируется автологом POST-запроса.
 */
class AdminDataService
{
    /** Сущности для выбора в интерфейсе: ключ → подпись. */
    public static function entities(): array
    {
        return [
            'visa_row'        => 'Визовые анкеты',
            'assignment_item' => 'Обычные анкеты (досье)',
            'visa_batch'      => 'Загрузки виз (партии)',
            'assignment_list' => 'Загрузки анкет (списки)',
            'visa_opis'       => 'Описи / визовые указания',
            'tabel'           => 'Табеля (эл. табель)',
            'shift_grafik'    => 'Графики сменности (2/2)',
            'memo'            => 'Служебки о стимуле',
            'trip'            => 'Командировки (заявки)',
            'vacation_schedule' => 'Графики отпусков',
            'vacation_memo'   => 'Служебки на отпуск (по отделам)',
            'vacation_campaign' => 'Кампании по отпускам',
            'vacation_request' => 'Заявки на отпуск',
            'vacation_change_request' => 'Заявки на изменение графика',
            'vacation_notice' => 'Уведомления об отпуске',
            'order'           => 'Поручения',
            'document'        => 'Документы СЭД',
            'nomenclature_case' => 'Дела номенклатуры',
            'inspection'      => 'Вердикты контроля (квота)',
            'sample_batch'    => 'Выборки на контроль',
            'tariff_coeff'    => 'Дневные коэффициенты тарифа',
            'visa_deduction'  => 'Визовые вычеты (ЗП)',
            'stimulus_override' => 'Корректировки стимула',
            'allowance_grant' => 'Надбавки-гранты',
            'fixed_extra'     => 'Фикс-подработки (ЗП)',
            'position_assignment' => 'Переводы (должность/отдел)',
            'acting_assignment' => 'Замещения (И.о./ВРИО)',
            'user'            => 'Сотрудники',
        ];
    }

    /** Человекочитаемые статусы документа СЭД (для списка). */
    private const DOC_STATUS = [
        'draft' => 'Черновик', 'on_approval' => 'На маршруте', 'revision' => 'На доработке',
        'approved' => 'Согласован', 'registered' => 'Зарегистрирован',
    ];

    public static function label(string $entity): string
    {
        return self::entities()[$entity] ?? $entity;
    }

    // ============ Списки записей для таблицы ============

    /**
     * Список записей сущности с поиском.
     * Каждая строка: id, title, sub, status, can_delete, can_revert, revert_hint.
     */
    public static function listRows(string $entity, string $q): array
    {
        $q = trim($q);
        $like = '%' . mb_strtolower($q, 'UTF-8') . '%';
        switch ($entity) {
            case 'visa_row':
                $w = $q !== '' ? 'WHERE LOWER(r.out_no) LIKE ? OR LOWER(r.surname_lat) LIKE ? OR LOWER(r.surname_ru) LIKE ?' : '';
                $p = $q !== '' ? [$like, $like, $like] : [];
                $rows = Database::all(
                    "SELECT r.id, r.out_no, r.surname_lat, r.names_lat, r.citizenship, r.status, u.full_name AS checker
                       FROM visa_rows r LEFT JOIN users u ON u.id=r.assigned_to $w ORDER BY r.id DESC LIMIT 200", $p);
                return array_map(function ($r) {
                    $st = (string) $r['status'];
                    return [
                        'id' => (int) $r['id'],
                        'title' => ($r['out_no'] ?: '#' . $r['id']) . ' · ' . trim($r['surname_lat'] . ' ' . $r['names_lat']),
                        'sub' => trim(($r['citizenship'] ?: '—') . ' · ' . ($r['checker'] ?: 'без исполнителя')),
                        'status' => VisaController::STATUS_LABELS[$st] ?? $st,
                        'can_delete' => true,
                        'can_revert' => in_array($st, ['assigned', 'checked', 'in_opis'], true),
                        'revert_hint' => self::visaRowRevertHint($st),
                    ];
                }, $rows);

            case 'assignment_item':
                $w = $q !== '' ? 'WHERE LOWER(ai.reg_number) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT ai.id, ai.reg_number, ai.country_code, ai.assigned_to, ai.checked_at, ai.recheck, u.full_name AS checker
                       FROM assignment_items ai LEFT JOIN users u ON u.id=ai.assigned_to $w ORDER BY ai.id DESC LIMIT 200", $p);
                return array_map(function ($r) {
                    $checked = !empty($r['checked_at']);
                    $assigned = !empty($r['assigned_to']);
                    $status = $checked ? 'Проверено' : ($assigned ? 'В работе' : 'Не распределено');
                    if ((int) $r['recheck']) { $status .= ' · повторная'; }
                    return [
                        'id' => (int) $r['id'],
                        'title' => $r['reg_number'],
                        'sub' => trim(($r['country_code'] ?: '—') . ' · ' . ($r['checker'] ?: 'без исполнителя')),
                        'status' => $status,
                        'can_delete' => true,
                        'can_revert' => $checked || $assigned,
                        'revert_hint' => $checked ? 'снять проверку → в работу' : ($assigned ? 'вернуть в пул' : ''),
                    ];
                }, $rows);

            case 'visa_batch':
                $w = $q !== '' ? 'WHERE LOWER(b.name) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT b.id, b.name, (SELECT COUNT(*) FROM visa_rows r WHERE r.batch_id=b.id) AS cnt
                       FROM visa_batches b $w ORDER BY b.id DESC LIMIT 200", $p);
                return array_map(fn($r) => [
                    'id' => (int) $r['id'], 'title' => $r['name'], 'sub' => 'анкет: ' . (int) $r['cnt'],
                    'status' => '—', 'can_delete' => true, 'can_revert' => false, 'revert_hint' => '',
                ], $rows);

            case 'assignment_list':
                $w = $q !== '' ? 'WHERE LOWER(l.name) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT l.id, l.name, (SELECT COUNT(*) FROM assignment_items ai WHERE ai.list_id=l.id) AS cnt
                       FROM assignment_lists l $w ORDER BY l.id DESC LIMIT 200", $p);
                return array_map(fn($r) => [
                    'id' => (int) $r['id'], 'title' => $r['name'], 'sub' => 'анкет: ' . (int) $r['cnt'],
                    'status' => '—', 'can_delete' => true, 'can_revert' => false, 'revert_hint' => '',
                ], $rows);

            case 'visa_opis':
                $w = $q !== '' ? 'WHERE LOWER(o.country) LIKE ? OR LOWER(o.instruction_no) LIKE ?' : '';
                $p = $q !== '' ? [$like, $like] : [];
                $rows = Database::all(
                    "SELECT o.*, (SELECT COUNT(*) FROM visa_rows r WHERE r.opis_id=o.id) AS people
                       FROM visa_opis o $w ORDER BY o.id DESC LIMIT 200", $p);
                return array_map(function ($r) {
                    $instructed = $r['status'] === 'instructed';
                    return [
                        'id' => (int) $r['id'],
                        'title' => '#' . (int) $r['id'] . ' · ' . $r['country'],
                        'sub' => 'чел.: ' . (int) $r['people'] . ($instructed ? ' · указание № ' . ($r['instruction_no'] ?: '—') : ''),
                        'status' => $instructed ? 'Указание внесено' : 'Сформирована',
                        'can_delete' => true,
                        'can_revert' => $instructed,
                        'revert_hint' => $instructed ? 'отменить указание → снова «сформирована»' : '',
                    ];
                }, $rows);

            case 'tabel':
                $w = $q !== '' ? 'WHERE LOWER(t.period) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT t.id, t.period, t.revision, t.status, d.name AS dept
                       FROM tabels t LEFT JOIN departments d ON d.id=t.department_id $w ORDER BY t.id DESC LIMIT 200", $p);
                return array_map(function ($r) {
                    $signed = $r['status'] === 'signed';
                    return [
                        'id' => (int) $r['id'],
                        'title' => $r['period'] . ' · рев.' . (int) $r['revision'],
                        'sub' => $r['dept'] ?: 'все подразделения',
                        'status' => $signed ? 'Подписан' : 'Черновик',
                        'can_delete' => true,
                        'can_revert' => $signed,
                        'revert_hint' => $signed ? 'снять подпись → черновик (пересчёт табеля)' : '',
                    ];
                }, $rows);

            case 'memo':
                $w = $q !== '' ? 'WHERE LOWER(m.number) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT m.id, m.number, m.status, m.kind, u.full_name AS author
                       FROM stimulus_memos m LEFT JOIN users u ON u.id=m.author_id $w ORDER BY m.id DESC LIMIT 200", $p);
                $labels = \App\Controllers\StimulusController::STATUS;
                return array_map(function ($r) use ($labels) {
                    return [
                        'id' => (int) $r['id'],
                        'title' => ($r['number'] ?: '#' . $r['id']),
                        'sub' => $r['author'] ?: '—',
                        'status' => $labels[$r['status']] ?? $r['status'],
                        'can_delete' => true,
                        'can_revert' => !in_array($r['status'], ['draft'], true),
                        'revert_hint' => 'откатить статус на шаг назад',
                    ];
                }, $rows);

            case 'document':
                $w = $q !== '' ? 'WHERE LOWER(d.title) LIKE ? OR LOWER(d.reg_number) LIKE ?' : '';
                $p = $q !== '' ? [$like, $like] : [];
                $rows = Database::all(
                    "SELECT d.id, d.title, d.reg_number, d.status, t.name AS type_name
                       FROM documents d LEFT JOIN doc_types t ON t.id=d.type_id $w ORDER BY d.id DESC LIMIT 200", $p);
                return array_map(function ($r) {
                    $st = (string) $r['status'];
                    return [
                        'id' => (int) $r['id'],
                        'title' => ($r['reg_number'] ?: '#' . $r['id']) . ' · ' . $r['title'],
                        'sub' => (string) ($r['type_name'] ?? ''),
                        'status' => self::DOC_STATUS[$st] ?? $st,
                        'can_delete' => true,
                        'can_revert' => in_array($st, ['approved', 'registered', 'on_approval', 'revision'], true),
                        'revert_hint' => 'откат статуса на шаг назад (снять регистрацию/последнюю визу)',
                    ];
                }, $rows);

            case 'shift_grafik':
                $w = $q !== '' ? 'WHERE LOWER(g.period) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT g.id, g.period, g.revision, g.signer_name, d.name AS dept, g.archived_at
                       FROM shift_grafiks g LEFT JOIN departments d ON d.id=g.department_id $w ORDER BY g.id DESC LIMIT 200", $p);
                return array_map(fn($r) => [
                    'id' => (int) $r['id'],
                    'title' => $r['period'] . ' · рев.' . (int) $r['revision'],
                    'sub' => ($r['dept'] ?: '—') . ' · ' . ($r['signer_name'] ?: ''),
                    'status' => $r['archived_at'] ? 'В архиве' : 'Подписан',
                    'can_delete' => true, 'can_revert' => false,
                    'revert_hint' => 'график сменности — снимок на момент подписи; для отмены удалите ревизию',
                ], $rows);

            case 'trip':
                $w = $q !== '' ? 'WHERE LOWER(t.number) LIKE ? OR LOWER(t.destination) LIKE ?' : '';
                $p = $q !== '' ? [$like, $like] : [];
                $rows = Database::all(
                    "SELECT t.id, t.number, t.destination, t.status, t.fact_at, u.full_name AS emp
                       FROM trip_requests t LEFT JOIN users u ON u.id=t.employee_id $w ORDER BY t.id DESC LIMIT 200", $p);
                $tl = \App\Services\TripService::STATUS;
                return array_map(function ($r) use ($tl) {
                    $st = (string) $r['status'];
                    $hint = !empty($r['fact_at']) ? 'снять факт → план' : ($st === 'approved' ? 'отменить утверждение (вернуть бюджет)' : ($st === 'on_approval' ? 'отозвать подачу → черновик' : ''));
                    return [
                        'id' => (int) $r['id'],
                        'title' => ($r['number'] ?: '#' . $r['id']) . ' · ' . $r['destination'],
                        'sub' => (string) ($r['emp'] ?? ''),
                        'status' => ($tl[$st] ?? $st) . (!empty($r['fact_at']) ? ' · факт' : ''),
                        'can_delete' => true,
                        'can_revert' => !empty($r['fact_at']) || in_array($st, ['approved', 'on_approval'], true),
                        'revert_hint' => $hint,
                    ];
                }, $rows);

            case 'vacation_schedule':
                $w = $q !== '' ? 'WHERE CAST(s.year AS CHAR) LIKE ?' : '';
                if (Database::driver() !== 'mysql') { $w = $q !== '' ? "WHERE CAST(s.year AS TEXT) LIKE ?" : ''; }
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT s.id, s.year, s.revision, s.status, d.name AS dept, s.archived_at
                       FROM vacation_schedules s LEFT JOIN departments d ON d.id=s.department_id $w ORDER BY s.id DESC LIMIT 200", $p);
                return array_map(function ($r) {
                    $signed = $r['status'] === 'signed';
                    return [
                        'id' => (int) $r['id'],
                        'title' => $r['year'] . ' · ' . ((int) $r['revision'] === 0 ? 'основной' : 'корр.' . (int) $r['revision']),
                        'sub' => ($r['dept'] ?: 'организация') . ($r['archived_at'] ? ' · архив' : ''),
                        'status' => $signed ? 'Подписан' : 'Черновик',
                        'can_delete' => true,
                        'can_revert' => $signed,
                        'revert_hint' => $signed ? 'снять подпись → черновик (погасить в журнале)' : '',
                    ];
                }, $rows);

            case 'vacation_memo':
                $rows = Database::all(
                    "SELECT m.id, m.year, m.status, d.name AS dept FROM vacation_memos m
                       LEFT JOIN departments d ON d.id=m.department_id ORDER BY m.id DESC LIMIT 200");
                $vmLabels = ['draft' => 'Черновик', 'head_signed' => 'Подписана начальником',
                    'deputy_signed' => 'Утверждена замом', 'approved' => 'Утверждена директором'];
                return array_map(function ($r) use ($vmLabels) {
                    $signed = $r['status'] !== 'draft';
                    return [
                        'id' => (int) $r['id'],
                        'title' => 'Служебка ' . $r['year'] . ' · ' . ($r['dept'] ?: '—'),
                        'sub' => $vmLabels[$r['status']] ?? $r['status'],
                        'status' => $vmLabels[$r['status']] ?? $r['status'],
                        'can_delete' => true,
                        'can_revert' => $signed,
                        'revert_hint' => $signed ? 'снять последнюю подпись (шаг назад) + погасить в журнале' : '',
                    ];
                }, $rows);

            case 'vacation_campaign':
                return array_map(function ($r) {
                    $st = (string) $r['stage'];
                    return ['id' => (int) $r['id'], 'title' => 'Кампания ' . $r['year'],
                        'sub' => \App\Services\VacationCampaignService::STAGES[$st] ?? $st,
                        'status' => \App\Services\VacationCampaignService::STAGES[$st] ?? $st,
                        'can_delete' => true, 'can_revert' => $st !== 'balances',
                        'revert_hint' => 'вернуть кампанию на предыдущий этап'];
                }, Database::all('SELECT id, year, stage FROM vacation_campaigns ORDER BY year DESC LIMIT 200'));

            case 'vacation_request':
                $rows = Database::all(
                    "SELECT v.id, v.year, v.start_date, v.end_date, v.status, u.full_name FROM vacation_requests v
                       JOIN users u ON u.id=v.employee_id ORDER BY v.id DESC LIMIT 200");
                return array_map(function ($r) {
                    $st = (string) $r['status'];
                    $term = $st !== 'on_head';
                    return ['id' => (int) $r['id'], 'title' => $r['full_name'] . ' · ' . date('d.m.Y', strtotime($r['start_date'])) . '–' . date('d.m.Y', strtotime($r['end_date'])),
                        'sub' => 'отпуск ' . $r['year'], 'status' => \App\Controllers\VacationController::STATUS[$st] ?? $st,
                        'can_delete' => true, 'can_revert' => $term, 'revert_hint' => 'вернуть заявку на шаг назад по маршруту'];
                }, $rows);

            case 'vacation_change_request':
                $rows = Database::all(
                    "SELECT r.*, u.full_name FROM vacation_change_requests r JOIN users u ON u.id=r.employee_id ORDER BY r.id DESC LIMIT 200");
                $vcrKind = ['add' => 'добавить период', 'remove' => 'убрать период', 'carry_next_year' => 'перенос на след. год'];
                $vcrStatus = ['pending' => 'На рассмотрении', 'approved' => 'Одобрена', 'rejected' => 'Отклонена'];
                return array_map(function ($r) use ($vcrKind, $vcrStatus) {
                    $period = $r['kind'] === 'carry_next_year'
                        ? ((int) $r['days'] . ' дн.')
                        : (date('d.m.Y', strtotime($r['start_date'])) . '–' . date('d.m.Y', strtotime($r['end_date'])));
                    return ['id' => (int) $r['id'],
                        'title' => $r['full_name'] . ' · ' . ($vcrKind[$r['kind']] ?? $r['kind']) . ' · ' . $period,
                        'sub' => 'отпуск ' . $r['year'],
                        'status' => $vcrStatus[$r['status']] ?? $r['status'],
                        'can_delete' => true, 'can_revert' => $r['status'] === 'approved',
                        'revert_hint' => 'отменить одобрение — вернуть заявку в «на рассмотрении» и отменить её эффект (снять запись/перенос)'];
                }, $rows);

            case 'vacation_notice':
                $rows = Database::all(
                    "SELECT n.*, u.full_name FROM vacation_notices n JOIN users u ON u.id=n.employee_id ORDER BY n.id DESC LIMIT 200");
                $vnStatus = ['draft' => 'Черновик (не подписано)', 'signed' => 'Подписано', 'sent' => 'Подписано и направлено'];
                return array_map(function ($r) use ($vnStatus) {
                    $done = $r['status'] !== 'draft';
                    return ['id' => (int) $r['id'],
                        'title' => $r['full_name'] . ' · ' . date('d.m.Y', strtotime($r['start_date'])) . '–' . date('d.m.Y', strtotime($r['end_date'])),
                        'sub' => 'уведомление ' . $r['year'] . ' · ' . (int) $r['days'] . ' дн.',
                        'status' => $vnStatus[$r['status']] ?? $r['status'],
                        'can_delete' => true, 'can_revert' => $done,
                        'revert_hint' => 'снять подпись и отметку о рассылке → черновик (подпись погашена в журнале)'];
                }, $rows);

            case 'nomenclature_case':
                return array_map(function ($r) {
                    $docs = (int) Database::scalar('SELECT COUNT(*) FROM documents WHERE case_id=?', [(int) $r['id']]);
                    return ['id' => (int) $r['id'], 'title' => $r['index_code'] . ' · ' . $r['title'],
                        'sub' => 'дел внутри: ' . $docs . ($r['year'] ? ' · ' . $r['year'] : ''),
                        'status' => ['open' => 'Открыто', 'closed' => 'Закрыто', 'archived' => 'В архиве'][$r['status']] ?? $r['status'],
                        'can_delete' => true, 'can_revert' => $r['status'] !== 'open', 'revert_hint' => 'открыть дело заново'];
                }, Database::all('SELECT id, index_code, title, year, status FROM nomenclature_cases ORDER BY id DESC LIMIT 200'));

            case 'inspection':
                $rows = Database::all(
                    "SELECT i.id, i.is_correct, i.penalty_amount, i.reviewed_at, u.full_name, a.reg_number
                       FROM inspections i JOIN users u ON u.id=i.employee_id
                       LEFT JOIN assignment_items a ON a.id=i.dossier_id
                      WHERE i.reviewed_at IS NOT NULL ORDER BY i.id DESC LIMIT 200");
                return array_map(function ($r) {
                    $brak = (int) $r['is_correct'] === 0;
                    return ['id' => (int) $r['id'], 'title' => ($r['reg_number'] ?: 'анкета') . ' · ' . $r['full_name'],
                        'sub' => $brak ? ('брак, штраф ' . (int) $r['penalty_amount'] . ' ₽') : 'корректно',
                        'status' => $brak ? 'Брак' : 'OK', 'can_delete' => true, 'can_revert' => true,
                        'revert_hint' => 'снять вердикт (штраф и повторную проверку отменить)'];
                }, $rows);

            case 'sample_batch':
                $rows = Database::all('SELECT id, work_date, finished_at FROM sample_batches ORDER BY work_date DESC LIMIT 200');
                return array_map(function ($r) {
                    $total = (int) Database::scalar('SELECT COUNT(*) FROM inspections WHERE batch_id=?', [(int) $r['id']]);
                    $done = (int) Database::scalar('SELECT COUNT(*) FROM inspections WHERE batch_id=? AND reviewed_at IS NOT NULL', [(int) $r['id']]);
                    return ['id' => (int) $r['id'], 'title' => 'Выборка ' . date('d.m.Y', strtotime($r['work_date'])),
                        'sub' => 'анкет: ' . $total . ', проверено: ' . $done, 'status' => $r['finished_at'] ? 'Завершена' : 'В работе',
                        'can_delete' => true, 'can_revert' => (bool) $r['finished_at'], 'revert_hint' => 'снять завершение — снова в работе'];
                }, $rows);

            case 'tariff_coeff':
                $rows = Database::all('SELECT work_date, coefficient, set_by FROM tariff_day_coeff ORDER BY work_date DESC LIMIT 200');
                return array_map(function ($r) {
                    $coef = (float) $r['coefficient'];
                    return ['id' => (int) str_replace('-', '', (string) $r['work_date']),
                        'title' => 'Коэф. ×' . rtrim(rtrim(number_format($coef, 2, '.', ''), '0'), '.') . ' за ' . date('d.m.Y', strtotime($r['work_date'])),
                        'sub' => $r['set_by'] ? ('задал #' . (int) $r['set_by']) : '', 'status' => $coef == 1.0 ? 'базовый' : 'повышенный',
                        'can_delete' => true, 'can_revert' => $coef != 1.0, 'revert_hint' => 'вернуть коэффициент к 1.0'];
                }, $rows);

            case 'visa_deduction':
                $rows = Database::all(
                    "SELECT vd.id, vd.amount, vd.period, vd.reason, u.full_name FROM visa_deductions vd
                       JOIN users u ON u.id=vd.employee_id ORDER BY vd.id DESC LIMIT 200");
                return array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['full_name'] . ' · ' . (int) $r['amount'] . ' ₽',
                    'sub' => 'период ' . $r['period'] . ($r['reason'] ? ' · ' . mb_substr((string) $r['reason'], 0, 40) : ''),
                    'status' => (int) $r['amount'] > 0 ? 'вычет' : 'нулевой', 'can_delete' => true,
                    'can_revert' => (int) $r['amount'] > 0, 'revert_hint' => 'обнулить вычет (оставить запись)'], $rows);

            case 'stimulus_override':
                $rows = Database::all(
                    "SELECT o.id, o.new_amount, o.created_at, u.full_name FROM stimulus_overrides o
                       JOIN stimulus_memo_lines l ON l.id=o.memo_line_id JOIN users u ON u.id=l.user_id ORDER BY o.id DESC LIMIT 200");
                return array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['full_name'] . ' → ' . (int) $r['new_amount'] . ' ₽',
                    'sub' => 'корректировка от ' . substr((string) $r['created_at'], 0, 10), 'status' => 'корректировка',
                    'can_delete' => true, 'can_revert' => false, 'revert_hint' => ''], $rows);

            case 'allowance_grant':
                $rows = Database::all(
                    "SELECT g.id, g.amount, g.period_from, g.period_to, g.status, u.full_name FROM allowance_grants g
                       JOIN users u ON u.id=g.user_id ORDER BY g.id DESC LIMIT 200");
                return array_map(function ($r) {
                    $memos = (int) Database::scalar('SELECT COUNT(*) FROM stimulus_memos WHERE grant_id=?', [(int) $r['id']]);
                    return ['id' => (int) $r['id'], 'title' => $r['full_name'] . ' · ' . (int) $r['amount'] . ' ₽/мес',
                        'sub' => $r['period_from'] . '…' . $r['period_to'] . ' · служебок: ' . $memos,
                        'status' => $r['status'] === 'active' ? 'действует' : 'отменён', 'can_delete' => true,
                        'can_revert' => $r['status'] === 'active', 'revert_hint' => 'отменить грант (статус canceled)'];
                }, $rows);

            case 'fixed_extra':
                $rows = Database::all(
                    "SELECT f.id, f.name, f.monthly_amount, f.is_active, u.full_name FROM employee_fixed_extras f
                       JOIN users u ON u.id=f.employee_id ORDER BY f.id DESC LIMIT 200");
                return array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['full_name'] . ' · ' . $r['name'] . ' · ' . (int) $r['monthly_amount'] . ' ₽',
                    'sub' => '', 'status' => (int) $r['is_active'] ? 'активна' : 'выключена', 'can_delete' => true,
                    'can_revert' => true, 'revert_hint' => 'вкл/выкл подработку'], $rows);

            case 'position_assignment':
                $rows = Database::all(
                    "SELECT p.id, p.position_title, p.started_on, p.ended_on, u.full_name FROM position_assignments p
                       JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 200");
                return array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['full_name'] . ' → ' . ($r['position_title'] ?: 'должность'),
                    'sub' => 'с ' . $r['started_on'] . ($r['ended_on'] ? ' по ' . $r['ended_on'] : ' (текущее)'),
                    'status' => $r['ended_on'] ? 'история' : 'текущее', 'can_delete' => false, 'can_revert' => !$r['ended_on'],
                    'revert_hint' => 'отменить перевод — вернуть прежние должность/отдел/оклад'], $rows);

            case 'acting_assignment':
                $rows = Database::all(
                    "SELECT a.id, a.kind, a.date_from, a.date_to, a.status, ab.full_name AS absent, ac.full_name AS acting
                       FROM acting_assignments a JOIN users ab ON ab.id=a.absent_id JOIN users ac ON ac.id=a.acting_id
                      ORDER BY a.id DESC LIMIT 200");
                return array_map(fn($r) => ['id' => (int) $r['id'], 'title' => ($r['kind'] === 'vrio' ? 'ВРИО' : 'И.о.') . ': ' . $r['acting'] . ' за ' . $r['absent'],
                    'sub' => $r['date_from'] . '…' . $r['date_to'], 'status' => $r['status'] === 'active' ? 'активно' : 'отменено',
                    'can_delete' => true, 'can_revert' => true, 'revert_hint' => 'переключить активно/отменено'], $rows);

            case 'order':
                $w = $q !== '' ? 'WHERE LOWER(o.title) LIKE ?' : '';
                $p = $q !== '' ? [$like] : [];
                $rows = Database::all(
                    "SELECT o.id, o.title, o.status, u.full_name AS assignee
                       FROM orders o LEFT JOIN users u ON u.id=o.assignee_id $w ORDER BY o.id DESC LIMIT 200", $p);
                $ol = ['new' => 'Новое', 'work' => 'В работе', 'review' => 'На проверке', 'done' => 'Выполнено', 'canceled' => 'Отменено'];
                return array_map(function ($r) use ($ol) {
                    $st = (string) $r['status'];
                    return [
                        'id' => (int) $r['id'],
                        'title' => '#' . (int) $r['id'] . ' · ' . mb_substr((string) $r['title'], 0, 60),
                        'sub' => (string) ($r['assignee'] ?? ''),
                        'status' => $ol[$st] ?? $st,
                        'can_delete' => true,
                        'can_revert' => $st !== 'new',
                        'revert_hint' => 'откат статуса на шаг назад',
                    ];
                }, $rows);

            case 'user':
                $w = $q !== '' ? 'WHERE LOWER(full_name) LIKE ? OR LOWER(login) LIKE ?' : '';
                $p = $q !== '' ? [$like, $like] : [];
                $rows = Database::all(
                    "SELECT id, full_name, login, role, is_active FROM users $w ORDER BY is_active DESC, full_name LIMIT 200", $p);
                return array_map(fn($r) => [
                    'id' => (int) $r['id'],
                    'title' => $r['full_name'] . ' · ' . $r['login'],
                    'sub' => 'роль: ' . $r['role'],
                    'status' => ((int) $r['is_active'] ? 'Активен' : 'Деактивирован'),
                    'can_delete' => true,
                    'can_revert' => true,
                    'revert_hint' => ((int) $r['is_active'] ? 'деактивировать' : 'активировать'),
                ], $rows);
        }
        return [];
    }

    private static function visaRowRevertHint(string $st): string
    {
        return [
            'assigned' => 'вернуть в нераспределённые',
            'checked'  => 'снять проверку → в работу',
            'in_opis'  => 'убрать из описи → проверена',
        ][$st] ?? '';
    }

    // ============ Удаление ============

    public static function delete(string $entity, int $id, int $adminId): array
    {
        $pdo = Database::pdo();
        try {
        switch ($entity) {
            case 'visa_row':
                $row = Database::one('SELECT * FROM visa_rows WHERE id=?', [$id]);
                if (!$row) { return self::err('Запись не найдена.'); }
                $pdo->beginTransaction();
                self::reverseVisaCredit($row);
                Database::run('DELETE FROM visa_deductions WHERE row_id=?', [$id]);
                Database::run('DELETE FROM visa_rows WHERE id=?', [$id]);
                self::pruneEmptyOpis($row['opis_id'] ? [(int) $row['opis_id']] : []);
                $pdo->commit();
                return self::ok('Визовая анкета удалена (вычеты сняты, сделка скорректирована).');

            case 'visa_batch':
                $batch = Database::one('SELECT * FROM visa_batches WHERE id=?', [$id]);
                if (!$batch) { return self::err('Партия не найдена.'); }
                $rows = Database::all('SELECT * FROM visa_rows WHERE batch_id=?', [$id]);
                $opisIds = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r['opis_id'] ?? 0), $rows))));
                $pdo->beginTransaction();
                foreach ($rows as $r) { self::reverseVisaCredit($r); }
                Database::run('DELETE FROM visa_deductions WHERE row_id IN (SELECT id FROM visa_rows WHERE batch_id=?)', [$id]);
                Database::run('DELETE FROM visa_rows WHERE batch_id=?', [$id]);
                Database::run('DELETE FROM visa_batches WHERE id=?', [$id]);
                self::pruneEmptyOpis($opisIds);
                $pdo->commit();
                return self::ok('Партия и её ' . count($rows) . ' анкет удалены (вычеты сняты, сделка скорректирована; пустые описи закрыты).');

            case 'assignment_item':
                if (!Database::scalar('SELECT 1 FROM assignment_items WHERE id=?', [$id])) { return self::err('Анкета не найдена.'); }
                $pdo->beginTransaction();
                Database::run('DELETE FROM item_comments WHERE item_id=?', [$id]);
                Database::run('DELETE FROM inspections WHERE dossier_id=?', [$id]);
                self::purgeRecheckChildren([$id]); // снять порождённую повторную анкету (брак)
                self::runSafe('UPDATE assignment_items SET source_item_id=NULL WHERE source_item_id=?', [$id]); // обрезать висячую ссылку у уже проверенной копии
                Database::run('DELETE FROM assignment_items WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Анкета удалена (замечания, проверка-выборка и повторная проверка сняты).');

            case 'assignment_list':
                if (!Database::scalar('SELECT 1 FROM assignment_lists WHERE id=?', [$id])) { return self::err('Список не найден.'); }
                $cnt = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE list_id=?', [$id]);
                $itemIds = array_map(fn($r) => (int) $r['id'], Database::all('SELECT id FROM assignment_items WHERE list_id=?', [$id]));
                $pdo->beginTransaction();
                if ($itemIds) { self::purgeRecheckChildren($itemIds); }
                Database::run('DELETE FROM item_comments WHERE item_id IN (SELECT id FROM assignment_items WHERE list_id=?)', [$id]);
                Database::run('DELETE FROM inspections WHERE dossier_id IN (SELECT id FROM assignment_items WHERE list_id=?)', [$id]);
                if ($itemIds) {
                    $ph = implode(',', array_fill(0, count($itemIds), '?'));
                    self::runSafe("UPDATE assignment_items SET source_item_id=NULL WHERE source_item_id IN ($ph)", $itemIds);
                }
                Database::run('DELETE FROM assignment_items WHERE list_id=?', [$id]);
                Database::run('DELETE FROM assignment_lists WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Список и его ' . $cnt . ' анкет удалены (замечания, проверки-выборки и повторные проверки сняты).');

            case 'visa_opis':
                $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [$id]);
                if (!$opis) { return self::err('Опись не найдена.'); }
                $pdo->beginTransaction();
                // Если указание уже внесено — сначала откатить его (восстановить строки, снять вычеты).
                if ($opis['status'] === 'instructed') { self::revertOpisToFormed($id); }
                Database::run("UPDATE visa_rows SET opis_id=NULL, status='checked' WHERE opis_id=?", [$id]);
                Database::run('DELETE FROM visa_opis WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Опись удалена; её анкеты возвращены в кандидаты на формирование.');

            case 'tabel':
                $t = Database::one('SELECT * FROM tabels WHERE id=?', [$id]);
                if (!$t) { return self::err('Табель не найден.'); }
                $wasSigned = $t['status'] === 'signed';
                $month = substr((string) $t['period'], 0, 7);
                $pdo->beginTransaction();
                Database::run('DELETE FROM tabel_rows WHERE tabel_id=?', [$id]);
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['tabel', $id]);
                Database::run('DELETE FROM tabels WHERE id=?', [$id]);
                $pdo->commit();
                if ($wasSigned) { TabelController::syncMonth($month); }
                return self::ok('Табель удалён' . ($wasSigned ? ' (месячный табель пересчитан).' : '.'));

            case 'memo':
                if (!Database::scalar('SELECT 1 FROM stimulus_memos WHERE id=?', [$id])) { return self::err('Служебка не найдена.'); }
                $pdo->beginTransaction();
                Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
                self::runSafe('DELETE FROM stimulus_overrides WHERE memo_line_id IN (SELECT id FROM stimulus_memo_lines WHERE memo_id=?)', [$id]);
                self::runSafe('DELETE FROM stimulus_stamps WHERE memo_id=?', [$id]);
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['stimulus_memo', $id]);
                Database::run('DELETE FROM stimulus_memos WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Служебка о стимуле удалена (строки выплат, штампы и подписи сняты — ЗП пересчитается).');

            case 'document':
                if (!Database::scalar('SELECT 1 FROM documents WHERE id=?', [$id])) { return self::err('Документ не найден.'); }
                $pdo->beginTransaction();
                // каскад поручений по документу — рекурсивно с дочерними и их журналами
                foreach (Database::all('SELECT id FROM orders WHERE doc_id=?', [$id]) as $o) { self::deleteOrderCascade((int) $o['id']); }
                self::runSafe('DELETE FROM doc_readers WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_approvers WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_files WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_history WHERE document_id=?', [$id]);
                self::runSafe('UPDATE doc_number_reservations SET doc_id=NULL WHERE doc_id=?', [$id]); // освободить бронь, а не уничтожить
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['document', $id]);
                Database::run('DELETE FROM documents WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Документ СЭД удалён (маршрут, файлы-метаданные, история и поручения по нему сняты каскадно; бронь номера освобождена; файлы на диске не удаляются).');

            case 'shift_grafik':
                if (!Database::scalar('SELECT 1 FROM shift_grafiks WHERE id=?', [$id])) { return self::err('График сменности не найден.'); }
                $pdo->beginTransaction();
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['shift_grafik', $id]);
                Database::run('DELETE FROM shift_grafiks WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('График сменности (ревизия) удалён. Актуальной становится предыдущая ревизия, если есть.');

            case 'trip':
                if (!Database::scalar('SELECT 1 FROM trip_requests WHERE id=?', [$id])) { return self::err('Заявка на командировку не найдена.'); }
                $pdo->beginTransaction();
                // удалить физические файлы вложений с диска, затем записи
                $dir = __DIR__ . '/../../storage/uploads/trips/';
                foreach (Database::all('SELECT stored_name FROM trip_attachments WHERE trip_id=?', [$id]) as $att) {
                    $p = $dir . basename((string) $att['stored_name']);
                    if ($att['stored_name'] && is_file($p)) { @unlink($p); }
                }
                Database::run('DELETE FROM trip_segments WHERE trip_id=?', [$id]);
                Database::run('DELETE FROM trip_extra_expenses WHERE trip_id=?', [$id]);
                Database::run('DELETE FROM trip_attachments WHERE trip_id=?', [$id]);
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['trip_request', $id]);
                Database::run('DELETE FROM trip_requests WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Заявка на командировку удалена (сегменты, доп.расходы, файлы вложений и подписи сняты; бюджет освобождён).');

            case 'vacation_schedule':
                if (!Database::scalar('SELECT 1 FROM vacation_schedules WHERE id=?', [$id])) { return self::err('График отпусков не найден.'); }
                $pdo->beginTransaction();
                Database::run('DELETE FROM vacation_schedule_rows WHERE schedule_id=?', [$id]);
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['vacation_schedule', $id]);
                Database::run('DELETE FROM vacation_schedules WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('График отпусков удалён (периоды и подписи сняты).');

            case 'vacation_memo':
                $vm = Database::one('SELECT * FROM vacation_memos WHERE id=?', [$id]);
                if (!$vm) { return self::err('Служебка на отпуск не найдена.'); }
                $pdo->beginTransaction();
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['vacation_memo', $id]);
                Database::run('DELETE FROM vacation_memos WHERE id=?', [$id]);
                // сформированные из служебки ЧЕРНОВИКИ графика отпусков того же года/отдела — снять каскадом
                $drafts = Database::all("SELECT id FROM vacation_schedules WHERE year=? AND department_id=? AND status='draft' AND archived_at IS NULL",
                    [(int) $vm['year'], (int) $vm['department_id']]);
                foreach ($drafts as $ds) {
                    Database::run('DELETE FROM vacation_schedule_rows WHERE schedule_id=?', [(int) $ds['id']]);
                    Database::run('DELETE FROM vacation_schedules WHERE id=?', [(int) $ds['id']]);
                }
                $signed = (int) Database::scalar("SELECT COUNT(*) FROM vacation_schedules WHERE year=? AND department_id=? AND status='signed' AND archived_at IS NULL",
                    [(int) $vm['year'], (int) $vm['department_id']]);
                $pdo->commit();
                return self::ok('Служебка на отпуск удалена (подписи сняты)' . ($drafts ? '; черновик графика снят' : '')
                    . ($signed ? '. ВНИМАНИЕ: есть подписанный график этого отдела — удалите его отдельно при необходимости.' : '. Записанные периоды сотрудников сохранены.'));

            case 'order':
                $ord = Database::one('SELECT id, doc_id FROM orders WHERE id=?', [$id]);
                if (!$ord) { return self::err('Поручение не найдено.'); }
                $pdo->beginTransaction();
                $n = self::deleteOrderCascade($id); // рекурсивно: само + дочерние (parent_id) с журналами
                $pdo->commit();
                if (!empty($ord['doc_id'])) { try { \App\Services\OrderService::syncDocControl((int) $ord['doc_id']); } catch (\Throwable $e) {} }
                return self::ok('Поручение удалено (' . $n . ' шт. с учётом дочерних: события, отчёты, соисполнители сняты).');

            case 'vacation_campaign':
                $c = Database::one('SELECT * FROM vacation_campaigns WHERE id=?', [$id]);
                if (!$c) { return self::err('Кампания не найдена.'); }
                $pdo->beginTransaction();
                $picks = (int) Database::scalar('SELECT COUNT(*) FROM vacation_picks WHERE year=?', [(int) $c['year']]);
                Database::run('DELETE FROM vacation_picks WHERE year=?', [(int) $c['year']]);
                Database::run('DELETE FROM vacation_campaigns WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Кампания ' . $c['year'] . ' удалена; самозаписи года сняты (' . $picks . '). Подписанные служебки и сформированные графики удаляются отдельно.');

            case 'vacation_request':
                if (!Database::scalar('SELECT 1 FROM vacation_requests WHERE id=?', [$id])) { return self::err('Заявка на отпуск не найдена.'); }
                Database::run('DELETE FROM vacation_requests WHERE id=?', [$id]);
                return self::ok('Заявка на отпуск удалена. Если код «О» уже попал в сформированный табель — он не пересчитывается автоматически.');

            case 'vacation_change_request':
                $vcr = Database::one('SELECT * FROM vacation_change_requests WHERE id=?', [$id]);
                if (!$vcr) { return self::err('Заявка не найдена.'); }
                Database::run('DELETE FROM vacation_change_requests WHERE id=?', [$id]);
                return self::ok('Заявка удалена' . ($vcr['status'] === 'approved'
                    ? ' (это только запись о заявке — применённая правка графика/переноса дней сохраняется; чтобы отменить её эффект, используйте «Откатить» до удаления)'
                    : '') . '.');

            case 'vacation_notice':
                if (!Database::scalar('SELECT 1 FROM vacation_notices WHERE id=?', [$id])) { return self::err('Уведомление об отпуске не найдено.'); }
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['vacation_notice', $id]);
                Database::run('DELETE FROM vacation_notices WHERE id=?', [$id]);
                return self::ok('Уведомление об отпуске удалено (подпись снята). Полученное сотрудником уведомление в кабинете сохраняется.');

            case 'nomenclature_case':
                if (!Database::scalar('SELECT 1 FROM nomenclature_cases WHERE id=?', [$id])) { return self::err('Дело не найдено.'); }
                $pdo->beginTransaction();
                $filed = (int) Database::scalar('SELECT COUNT(*) FROM documents WHERE case_id=?', [$id]);
                self::runSafe('UPDATE documents SET case_id=NULL, filed_at=NULL, filed_by=NULL WHERE case_id=?', [$id]);
                Database::run('DELETE FROM nomenclature_cases WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Дело номенклатуры удалено' . ($filed ? ' (документы изъяты из дела: ' . $filed . ').' : '.'));

            case 'inspection':
                $ins = Database::one('SELECT * FROM inspections WHERE id=?', [$id]);
                if (!$ins) { return self::err('Вердикт контроля не найден.'); }
                $pdo->beginTransaction();
                [$rc, $blocked] = self::purgeRecheckChildren([(int) $ins['dossier_id']]);
                if ($blocked) { $pdo->rollBack(); return self::err('Нельзя снять вердикт: порождённая повторная анкета уже проконтролирована — сначала откатите её.'); }
                self::runSafe('DELETE FROM item_comments WHERE item_id=?', [(int) $ins['dossier_id']]);
                self::runSafe("UPDATE assignment_items SET checked_at=NULL, comment_id=NULL, comment_text=NULL WHERE id=?", [(int) $ins['dossier_id']]);
                Database::run('DELETE FROM inspections WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Вердикт контроля снят; штраф и повторная проверка отменены' . ($rc ? ' (снято повторных анкет: ' . $rc . ')' : '') . '.');

            case 'sample_batch':
                $sb = Database::one('SELECT * FROM sample_batches WHERE id=?', [$id]);
                if (!$sb) { return self::err('Выборка не найдена.'); }
                $pdo->beginTransaction();
                $dossiers = array_map(fn($r) => (int) $r['dossier_id'], Database::all('SELECT dossier_id FROM inspections WHERE batch_id=?', [$id]));
                [$rc] = self::purgeRecheckChildren($dossiers);
                foreach ($dossiers as $did) {
                    self::runSafe('DELETE FROM item_comments WHERE item_id=?', [$did]);
                    self::runSafe('UPDATE assignment_items SET checked_at=NULL, comment_id=NULL, comment_text=NULL WHERE id=?', [$did]);
                }
                Database::run('DELETE FROM inspections WHERE batch_id=?', [$id]);
                Database::run('DELETE FROM sample_batches WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Выборка на контроль удалена: ' . count($dossiers) . ' проверок снято, анкеты возвращены в работу, штрафы и повторные проверки (' . $rc . ') отменены.');

            case 'tariff_coeff':
                $date = preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $id, $mm) ? "$mm[1]-$mm[2]-$mm[3]" : '';
                if ($date === '' || !Database::scalar('SELECT 1 FROM tariff_day_coeff WHERE work_date=?', [$date])) { return self::err('Запись коэффициента не найдена.'); }
                Database::run('DELETE FROM tariff_day_coeff WHERE work_date=?', [$date]);
                return self::ok('Дневной коэффициент за ' . date('d.m.Y', strtotime($date)) . ' удалён (тариф дня вернулся к базовому ×1.0; суммы за день пересчитаются).');

            case 'visa_deduction':
                if (!Database::scalar('SELECT 1 FROM visa_deductions WHERE id=?', [$id])) { return self::err('Вычет не найден.'); }
                Database::run('DELETE FROM visa_deductions WHERE id=?', [$id]);
                return self::ok('Визовый вычет удалён — ЗП проверяющего пересчитается.');

            case 'stimulus_override':
                if (!Database::scalar('SELECT 1 FROM stimulus_overrides WHERE id=?', [$id])) { return self::err('Корректировка не найдена.'); }
                Database::run('DELETE FROM stimulus_overrides WHERE id=?', [$id]);
                return self::ok('Корректировка стимула снята — действует предыдущая (исходная) сумма, ЗП пересчитается.');

            case 'allowance_grant':
                $g = Database::one('SELECT * FROM allowance_grants WHERE id=?', [$id]);
                if (!$g) { return self::err('Грант надбавки не найден.'); }
                $pdo->beginTransaction();
                $memoIds = array_map(fn($r) => (int) $r['id'], Database::all('SELECT id FROM stimulus_memos WHERE grant_id=?', [$id]));
                foreach ($memoIds as $mid) { self::deleteStimMemoCascade($mid); }
                Database::run('DELETE FROM allowance_grants WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Грант надбавки удалён' . ($memoIds ? ' вместе с ' . count($memoIds) . ' служебками (выплаты/штампы/подписи сняты)' : '') . ' — ЗП-«пол» пересчитается.');

            case 'fixed_extra':
                $fe = Database::one('SELECT * FROM employee_fixed_extras WHERE id=?', [$id]);
                if (!$fe) { return self::err('Фикс-подработка не найдена.'); }
                Database::run('DELETE FROM employee_fixed_extras WHERE id=?', [$id]);
                Audit::log('fixed_extra.delete', 'Удалена фикс-подработка #' . $id, ['employee_id' => (int) $fe['employee_id'], 'name' => $fe['name'], 'amount' => (int) $fe['monthly_amount']]);
                return self::ok('Фикс-подработка удалена — ЗП пересчитается.');

            case 'acting_assignment':
                if (!Database::scalar('SELECT 1 FROM acting_assignments WHERE id=?', [$id])) { return self::err('Замещение не найдено.'); }
                Database::run('DELETE FROM acting_assignments WHERE id=?', [$id]);
                return self::ok('Назначение И.о./ВРИО удалено.');

            case 'position_assignment':
                return self::err('Перевод откатывается, а не удаляется: используйте «↩ Откатить» у текущего (последнего) перевода сотрудника.');

            case 'user':
                return self::deleteUser($id, $adminId);
        }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            return self::err('Не удалось удалить: ' . $e->getMessage());
        }
        return self::err('Неизвестная сущность.');
    }

    /** Удаление сотрудника: разрешаем только при отсутствии исторических записей (иначе — деактивация). */
    private static function deleteUser(int $id, int $adminId): array
    {
        $u = Database::one('SELECT * FROM users WHERE id=?', [$id]);
        if (!$u) { return self::err('Сотрудник не найден.'); }
        if ($id === $adminId) { return self::err('Нельзя удалить собственную учётную запись.'); }
        if ($u['role'] === 'admin') {
            $admins = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1");
            if ($admins <= 1) { return self::err('Нельзя удалить единственного администратора.'); }
        }
        // Историю ЗП/подписей не сносим: при наличии — только деактивация (откат → активность).
        $blocks = [
            'проверки-выборки'   => (int) Database::scalar('SELECT COUNT(*) FROM inspections WHERE employee_id=? OR controller_id=?', [$id, $id]),
            'сделка'             => (int) Database::scalar('SELECT COUNT(*) FROM piecework WHERE employee_id=?', [$id]),
            'визовые вычеты'     => (int) Database::scalar('SELECT COUNT(*) FROM visa_deductions WHERE employee_id=? OR decided_by=?', [$id, $id]),
            'строки служебок'    => (int) Database::scalar('SELECT COUNT(*) FROM stimulus_memo_lines WHERE user_id=?', [$id]),
            'подписанные табели' => (int) Database::scalar("SELECT COUNT(*) FROM tabels WHERE signer_id=?", [$id]),
        ];
        $has = array_filter($blocks);
        if ($has) {
            $list = [];
            foreach ($has as $k => $n) { $list[] = "$k: $n"; }
            Database::run('UPDATE users SET is_active=0 WHERE id=?', [$id]);
            return self::ok('У сотрудника есть исторические записи (' . implode(', ', $list)
                . ') — для сохранности ЗП и подписей он ДЕАКТИВИРОВАН, а не удалён. Полное удаление недоступно.');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        self::runSafe('DELETE FROM user_roles WHERE user_id=?', [$id]);
        self::runSafe('DELETE FROM user_projects WHERE user_id=?', [$id]);
        self::runSafe('DELETE FROM user_certificates WHERE user_id=?', [$id]);
        // Снять незавершённые назначения, чтобы не осталось висячих ссылок.
        self::runSafe('UPDATE visa_rows SET assigned_to=NULL WHERE assigned_to=?', [$id]);
        self::runSafe('UPDATE visa_rows SET excluded_user=NULL WHERE excluded_user=?', [$id]);
        self::runSafe('UPDATE assignment_items SET assigned_to=NULL WHERE assigned_to=?', [$id]);
        self::runSafe('UPDATE assignment_items SET excluded_user=NULL WHERE excluded_user=?', [$id]);
        Database::run('DELETE FROM users WHERE id=?', [$id]);
        $pdo->commit();
        return self::ok('Сотрудник без исторических записей удалён (роли/назначения сняты).');
    }

    // ============ Откат статуса на шаг назад ============

    public static function revert(string $entity, int $id): array
    {
        $pdo = Database::pdo();
        try {
        switch ($entity) {
            case 'visa_row':
                $r = Database::one('SELECT * FROM visa_rows WHERE id=?', [$id]);
                if (!$r) { return self::err('Запись не найдена.'); }
                switch ($r['status']) {
                    case 'assigned':
                        Database::run("UPDATE visa_rows SET assigned_to=NULL, status='loaded' WHERE id=?", [$id]);
                        return self::ok('Анкета возвращена в нераспределённые.');
                    case 'checked':
                        $pdo->beginTransaction();
                        self::reverseVisaCredit($r);
                        Database::run("UPDATE visa_rows SET checked_at=NULL, credited_at=NULL, status='assigned' WHERE id=?", [$id]);
                        $pdo->commit();
                        return self::ok('Проверка снята — анкета снова в работе у специалиста (сделка скорректирована).');
                    case 'in_opis':
                        Database::run("UPDATE visa_rows SET opis_id=NULL, status='checked' WHERE id=?", [$id]);
                        return self::ok('Анкета убрана из описи и возвращена в кандидаты на формирование.');
                    default:
                        return self::err('Откат для статуса «' . (VisaController::STATUS_LABELS[$r['status']] ?? $r['status'])
                            . '» делается на уровне описи (для «указание получено» — откатите опись) или на доске доработки.');
                }
                // no break

            case 'assignment_item':
                $r = Database::one('SELECT * FROM assignment_items WHERE id=?', [$id]);
                if (!$r) { return self::err('Анкета не найдена.'); }
                if (!empty($r['checked_at'])) {
                    $pdo->beginTransaction();
                    [$rc, $blocked] = self::purgeRecheckChildren([$id]);
                    if ($blocked) { $pdo->rollBack(); return self::err('Нельзя снять проверку: порождённая повторная анкета уже проконтролирована — сначала откатите/удалите её.'); }
                    Database::run('DELETE FROM item_comments WHERE item_id=?', [$id]);
                    Database::run('DELETE FROM inspections WHERE dossier_id=?', [$id]);
                    Database::run('UPDATE assignment_items SET checked_at=NULL, comment_id=NULL, comment_text=NULL WHERE id=?', [$id]);
                    $pdo->commit();
                    return self::ok('Проверка анкеты снята; штраф контроля и повторная проверка отменены' . ($rc ? " (повторных анкет: $rc)" : '') . ' — анкета снова в работе.');
                }
                if (!empty($r['assigned_to'])) {
                    Database::run('UPDATE assignment_items SET assigned_to=NULL WHERE id=?', [$id]);
                    return self::ok('Анкета возвращена в пул (нераспределённые).');
                }
                return self::err('Анкета уже в пуле — откатывать нечего.');

            case 'visa_opis':
                $opis = Database::one('SELECT * FROM visa_opis WHERE id=?', [$id]);
                if (!$opis) { return self::err('Опись не найдена.'); }
                if ($opis['status'] !== 'instructed') { return self::err('Опись ещё не в статусе «указание внесено» — откатывать нечего.'); }
                $pdo->beginTransaction();
                $restored = self::revertOpisToFormed($id);
                $pdo->commit();
                return self::ok('Указание отменено: опись снова «сформирована», ' . $restored
                    . ' отклонённых строк восстановлены, вычеты по описи сняты.');

            case 'tabel':
                $t = Database::one('SELECT * FROM tabels WHERE id=?', [$id]);
                if (!$t) { return self::err('Табель не найден.'); }
                if ($t['status'] !== 'signed') { return self::err('Табель не подписан — откатывать нечего.'); }
                Database::run('UPDATE tabels SET status=?, signer_id=NULL, sign_type=NULL, signed_at=NULL, sign_hash=NULL, cert_serial=NULL WHERE id=?',
                    ['draft', $id]);
                \App\Services\SignService::revokeLast('tabel', $id, (int) Auth::id());
                TabelController::syncMonth(substr((string) $t['period'], 0, 7));
                return self::ok('Подпись снята — табель снова черновик. Месячный табель пересчитан, подпись погашена в журнале.');

            case 'memo':
                return self::revertMemo($id);

            case 'document':
                return self::revertDocument($id);

            case 'trip':
                $t = Database::one('SELECT * FROM trip_requests WHERE id=?', [$id]);
                if (!$t) { return self::err('Заявка на командировку не найдена.'); }
                if (!empty($t['fact_at'])) {
                    Database::run('UPDATE trip_requests SET fact_per_diem=NULL, fact_lodging=NULL, fact_travel=NULL, fact_other=NULL, fact_at=NULL, fact_by=NULL WHERE id=?', [$id]);
                    return self::ok('Факт снят — в бюджете командировок снова учитывается план.');
                }
                if ($t['status'] === 'approved') {
                    Database::run("UPDATE trip_requests SET status='revision', director_id=NULL, director_sign_name='', director_sign_position='', director_sign_type=NULL, director_signed_at=NULL, director_sign_hash=NULL, director_cert=NULL WHERE id=?", [$id]);
                    \App\Services\SignService::revokeLast('trip_request', $id, (int) Auth::id());
                    return self::ok('Утверждение отменено — заявка на доработке, бюджет освобождён, подпись директора погашена.');
                }
                if ($t['status'] === 'on_approval') {
                    Database::run("UPDATE trip_requests SET status='draft', submitted_at=NULL, author_sign_type=NULL, author_signed_at=NULL, author_sign_hash=NULL, author_cert=NULL WHERE id=?", [$id]);
                    \App\Services\SignService::revokeLast('trip_request', $id, (int) Auth::id());
                    return self::ok('Подача отозвана — заявка снова черновик, подпись автора погашена.');
                }
                return self::err('Заявка в черновике/на доработке — откатывать нечего.');

            case 'vacation_schedule':
                $s = Database::one('SELECT * FROM vacation_schedules WHERE id=?', [$id]);
                if (!$s) { return self::err('График отпусков не найден.'); }
                if ($s['status'] !== 'signed') { return self::err('График не подписан — откатывать нечего.'); }
                Database::run("UPDATE vacation_schedules SET status='draft', signer_id=NULL, signer_name='', signer_position='', sign_type=NULL, signed_at=NULL, sign_hash='', cert_serial='', snapshot=NULL WHERE id=?", [$id]);
                \App\Services\SignService::revoke('vacation_schedule', $id, (int) Auth::id());
                return self::ok('Подпись графика отпусков снята — снова черновик (подпись погашена в журнале).');

            case 'vacation_notice':
                $vn = Database::one('SELECT * FROM vacation_notices WHERE id=?', [$id]);
                if (!$vn) { return self::err('Уведомление об отпуске не найдено.'); }
                if ($vn['status'] === 'draft') { return self::err('Уведомление не подписано — откатывать нечего.'); }
                Database::run("UPDATE vacation_notices SET status='draft', signed_by=NULL, signed_at=NULL, sign_type=NULL, sign_hash='', cert_serial='', notified_at=NULL WHERE id=?", [$id]);
                \App\Services\SignService::revoke('vacation_notice', $id, (int) Auth::id());
                return self::ok('Подпись уведомления об отпуске снята — снова черновик, отметка о рассылке сброшена (подпись погашена в журнале).');

            case 'vacation_memo':
                $m = Database::one('SELECT * FROM vacation_memos WHERE id=?', [$id]);
                if (!$m) { return self::err('Служебка на отпуск не найдена.'); }
                // пошаговый откат маршрута: approved → deputy_signed → head_signed → draft
                if ($m['status'] === 'approved') {
                    Database::run("UPDATE vacation_memos SET status='deputy_signed', director_id=NULL, director_signed_at=NULL, director_sign_type=NULL, director_sign_hash=NULL WHERE id=?", [$id]);
                    \App\Services\SignService::revokeLast('vacation_memo', $id, (int) Auth::id());
                    return self::ok('Утверждение директора отменено — служебка ожидает директора (подпись погашена).');
                }
                if ($m['status'] === 'deputy_signed') {
                    Database::run("UPDATE vacation_memos SET status='head_signed', deputy_id=NULL, deputy_signed_at=NULL, deputy_sign_type=NULL, deputy_sign_hash=NULL WHERE id=?", [$id]);
                    \App\Services\SignService::revokeLast('vacation_memo', $id, (int) Auth::id());
                    return self::ok('Утверждение зама отменено — служебка ожидает зама (подпись погашена).');
                }
                if ($m['status'] === 'head_signed') {
                    Database::run("UPDATE vacation_memos SET status='draft', head_id=NULL, head_signed_at=NULL, head_sign_type=NULL, head_sign_hash=NULL WHERE id=?", [$id]);
                    \App\Services\SignService::revokeLast('vacation_memo', $id, (int) Auth::id());
                    return self::ok('Подпись начальника снята — служебка на доработке (подпись погашена).');
                }
                return self::err('Служебка в черновике — откатывать нечего.');

            case 'order':
                $o = Database::one('SELECT * FROM orders WHERE id=?', [$id]);
                if (!$o) { return self::err('Поручение не найдено.'); }
                $map = ['done' => 'review', 'review' => 'work', 'work' => 'new', 'canceled' => 'new'];
                $st = (string) $o['status'];
                if (!isset($map[$st])) { return self::err('Поручение в статусе «Новое» — откатывать нечего.'); }
                $new = $map[$st];
                // при возврате из «исполнено» — снять дату завершения и результат контроля
                $extra = $st === 'done' ? ', done_at=NULL, control_result=NULL' : '';
                Database::run('UPDATE orders SET status=?' . $extra . ' WHERE id=?', [$new, $id]);
                \App\Services\OrderService::event($id, 'reverted', 'статус: ' . $st . ' → ' . $new . ' (откат админом)');
                if (!empty($o['doc_id'])) { try { \App\Services\OrderService::syncDocControl((int) $o['doc_id']); } catch (\Throwable $e) {} }
                return self::ok('Поручение откатано на шаг назад: ' . $st . ' → ' . $new . ($st === 'done' ? ' (результат контроля сброшен).' : '.'));

            case 'user':
                $u = Database::one('SELECT id, is_active FROM users WHERE id=?', [$id]);
                if (!$u) { return self::err('Сотрудник не найден.'); }
                $new = (int) $u['is_active'] ? 0 : 1;
                Database::run('UPDATE users SET is_active=? WHERE id=?', [$new, $id]);
                return self::ok($new ? 'Сотрудник активирован.' : 'Сотрудник деактивирован.');

            case 'vacation_campaign':
                $c = Database::one('SELECT * FROM vacation_campaigns WHERE id=?', [$id]);
                if (!$c) { return self::err('Кампания не найдена.'); }
                $order = ['balances', 'blackouts', 'booking', 'signing', 'closed'];
                $ci = array_search((string) $c['stage'], $order, true);
                if ($ci === false || $ci === 0) { return self::err('Кампания на первом этапе — откатывать нечего.'); }
                $prev = $order[$ci - 1];
                $extra = $prev === 'balances' ? ', balances_approved_at=NULL, balances_approved_by=NULL' : '';
                Database::run('UPDATE vacation_campaigns SET stage=?' . $extra . ' WHERE id=?', [$prev, $id]);
                return self::ok('Кампания ' . $c['year'] . ' возвращена на этап «' . (\App\Services\VacationCampaignService::STAGES[$prev] ?? $prev) . '».');

            case 'vacation_request':
                $v = Database::one('SELECT * FROM vacation_requests WHERE id=?', [$id]);
                if (!$v) { return self::err('Заявка на отпуск не найдена.'); }
                $st = (string) $v['status'];
                if ($st === 'approved') {
                    if (($v['kind'] ?? '') === 'change' && !empty($v['replaces_id'])) {
                        Database::run("UPDATE vacation_requests SET status='approved' WHERE id=? AND status='replaced'", [(int) $v['replaces_id']]);
                    }
                    Database::run("UPDATE vacation_requests SET status='on_approve', decided_at=NULL, notified_at=NULL WHERE id=?", [$id]);
                    return self::ok('Утверждение отпуска снято — заявка снова на утверждении.');
                }
                if ($st === 'on_approve') {
                    Database::run("UPDATE vacation_requests SET status='on_head' WHERE id=?", [$id]);
                    return self::ok('Заявка возвращена руководителю отдела.');
                }
                if (in_array($st, ['rejected', 'auto_rejected'], true)) {
                    Database::run("UPDATE vacation_requests SET status='on_head', comment=NULL, decided_at=NULL WHERE id=?", [$id]);
                    return self::ok('Отклонение снято — заявка снова у руководителя.');
                }
                return self::err('Заявка в начальном статусе — откатывать нечего.');

            case 'vacation_change_request':
                $vcr = Database::one('SELECT * FROM vacation_change_requests WHERE id=?', [$id]);
                if (!$vcr) { return self::err('Заявка не найдена.'); }
                if ($vcr['status'] !== 'approved') { return self::err('Заявка не одобрена — откатывать нечего.'); }
                $year = (int) $vcr['year'];
                $empId = (int) $vcr['employee_id'];
                $empDept = (int) (Database::scalar('SELECT department_id FROM users WHERE id=?', [$empId]) ?: 0);
                if ($vcr['kind'] === 'add') {
                    if ($vcr['pick_id']) {
                        \App\Services\VacationScheduleService::syncRowForPick($year, $empDept, $empId, (string) $vcr['start_date'], (string) $vcr['end_date'], (int) $vcr['days'], 'remove');
                        Database::run('DELETE FROM vacation_picks WHERE id=?', [(int) $vcr['pick_id']]);
                    }
                } elseif ($vcr['kind'] === 'remove') {
                    $newPickId = Database::insert(
                        'INSERT INTO vacation_picks (year, employee_id, start_date, end_date, days, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                        [$year, $empId, $vcr['start_date'], $vcr['end_date'], (int) $vcr['days'], 'откат заявки #' . $id, (int) Auth::id(), date('Y-m-d H:i:s')]);
                    \App\Services\VacationScheduleService::syncRowForPick($year, $empDept, $empId, (string) $vcr['start_date'], (string) $vcr['end_date'], (int) $vcr['days'], 'add');
                    Database::run('UPDATE vacation_change_requests SET pick_id=? WHERE id=?', [$newPickId, $id]);
                } elseif ($vcr['kind'] === 'carry_next_year') {
                    \App\Services\VacationScheduleService::addCarriedOut($empId, $year, -(int) $vcr['days']);
                }
                Database::run("UPDATE vacation_change_requests SET status='pending', decided_by=NULL, decided_at=NULL WHERE id=?", [$id]);
                return self::ok('Одобрение заявки отменено — эффект правки снят, заявка снова «на рассмотрении».');

            case 'nomenclature_case':
                $nc = Database::one('SELECT * FROM nomenclature_cases WHERE id=?', [$id]);
                if (!$nc) { return self::err('Дело не найдено.'); }
                if ($nc['status'] === 'archived') {
                    Database::run("UPDATE nomenclature_cases SET status='closed' WHERE id=?", [$id]);
                    return self::ok('Дело возвращено из архива в статус «закрыто».');
                }
                if ($nc['status'] === 'closed') {
                    Database::run("UPDATE nomenclature_cases SET status='open', closed_on=NULL, destroy_after=NULL WHERE id=?", [$id]);
                    return self::ok('Дело открыто заново.');
                }
                return self::err('Дело уже открыто — откатывать нечего.');

            case 'inspection':
                $ins = Database::one('SELECT * FROM inspections WHERE id=?', [$id]);
                if (!$ins) { return self::err('Вердикт контроля не найден.'); }
                if ($ins['reviewed_at'] === null && $ins['is_correct'] === null) { return self::err('Вердикт ещё не вынесен — откатывать нечего.'); }
                $pdo->beginTransaction();
                [$rc, $blocked] = self::purgeRecheckChildren([(int) $ins['dossier_id']]);
                if ($blocked) { $pdo->rollBack(); return self::err('Нельзя снять вердикт: повторная анкета уже проконтролирована.'); }
                self::runSafe("UPDATE inspections SET is_correct=NULL, error_type_id=NULL, penalty_amount=0, occurrence=0, reviewed_at=NULL, controller_comment=NULL WHERE id=?", [$id]);
                $pdo->commit();
                return self::ok('Вердикт контроля снят (штраф и повторная проверка отменены' . ($rc ? ", повторных анкет: $rc" : '') . ') — анкета ждёт контроля заново.');

            case 'sample_batch':
                $sb = Database::one('SELECT * FROM sample_batches WHERE id=?', [$id]);
                if (!$sb) { return self::err('Выборка не найдена.'); }
                if (!$sb['finished_at']) { return self::err('Выборка не завершена — откатывать нечего.'); }
                Database::run('UPDATE sample_batches SET finished_at=NULL WHERE id=?', [$id]);
                return self::ok('Завершение выборки снято — снова в работе.');

            case 'tariff_coeff':
                $date = preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $id, $mm) ? "$mm[1]-$mm[2]-$mm[3]" : '';
                if ($date === '' || !Database::scalar('SELECT 1 FROM tariff_day_coeff WHERE work_date=?', [$date])) { return self::err('Запись коэффициента не найдена.'); }
                Database::run('UPDATE tariff_day_coeff SET coefficient=1 WHERE work_date=?', [$date]);
                return self::ok('Коэффициент за ' . date('d.m.Y', strtotime($date)) . ' возвращён к ×1.0 (суммы за день пересчитаются).');

            case 'visa_deduction':
                $vd = Database::one('SELECT * FROM visa_deductions WHERE id=?', [$id]);
                if (!$vd) { return self::err('Вычет не найден.'); }
                if ((int) $vd['amount'] === 0) { return self::err('Вычет уже нулевой.'); }
                Database::run('UPDATE visa_deductions SET amount=0 WHERE id=?', [$id]);
                return self::ok('Визовый вычет обнулён (запись сохранена для аудита) — ЗП пересчитается.');

            case 'allowance_grant':
                $g = Database::one('SELECT * FROM allowance_grants WHERE id=?', [$id]);
                if (!$g) { return self::err('Грант не найден.'); }
                if ($g['status'] !== 'active') { return self::err('Грант уже отменён.'); }
                \App\Services\AllowanceService::cancel($id);
                return self::ok('Грант надбавки отменён (статус «canceled») — перестаёт влиять на ЗП-«пол».');

            case 'fixed_extra':
                $fe = Database::one('SELECT * FROM employee_fixed_extras WHERE id=?', [$id]);
                if (!$fe) { return self::err('Фикс-подработка не найдена.'); }
                $newA = (int) $fe['is_active'] ? 0 : 1;
                Database::run('UPDATE employee_fixed_extras SET is_active=? WHERE id=?', [$newA, $id]);
                return self::ok($newA ? 'Фикс-подработка включена.' : 'Фикс-подработка выключена (не учитывается в ЗП).');

            case 'acting_assignment':
                $a = Database::one('SELECT * FROM acting_assignments WHERE id=?', [$id]);
                if (!$a) { return self::err('Замещение не найдено.'); }
                $newS = $a['status'] === 'active' ? 'canceled' : 'active';
                Database::run('UPDATE acting_assignments SET status=? WHERE id=?', [$newS, $id]);
                return self::ok($newS === 'canceled' ? 'Замещение отменено.' : 'Замещение снова активно.');

            case 'position_assignment':
                return self::revertTransfer($id);

            case 'visa_batch':
            case 'assignment_list':
            case 'shift_grafik':
                return self::err('У этой записи нет статуса для отката — используйте удаление (для графика сменности — удалить ревизию).');
        }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            return self::err('Не удалось откатить: ' . $e->getMessage());
        }
        return self::err('Неизвестная сущность.');
    }

    /**
     * Откат описи instructed → formed: строки instructed → in_opis; отклонённые этим указанием
     * (по visa_deductions.opis_id) и ещё не тронутые строки rework возвращаются в опись; вычеты
     * описи снимаются; поля указания очищаются. Возвращает число восстановленных строк.
     * ВЫЗЫВАТЬ внутри транзакции.
     */
    private static function revertOpisToFormed(int $opisId): int
    {
        Database::run("UPDATE visa_rows SET status='in_opis' WHERE opis_id=? AND status='instructed'", [$opisId]);
        $restored = 0;
        $now = date('Y-m-d H:i:s');
        $rowIds = array_map(fn($r) => (int) $r['row_id'],
            Database::all('SELECT DISTINCT row_id FROM visa_deductions WHERE opis_id=?', [$opisId]));
        foreach ($rowIds as $rid) {
            $r = Database::one('SELECT * FROM visa_rows WHERE id=?', [$rid]);
            // Восстанавливаем только если строка всё ещё «на доработке» и не взята в работу заново.
            if ($r && $r['status'] === 'rework' && empty($r['assigned_to'])) {
                Database::run(
                    "UPDATE visa_rows
                        SET status='in_opis', opis_id=?, assigned_to=excluded_user, excluded_user=NULL,
                            recheck=0, source_row_id=NULL, mid_refused_at=NULL, mid_refuse_note=NULL, rework_note=NULL,
                            checked_at=COALESCE(checked_at, ?), credited_at=COALESCE(credited_at, ?)
                      WHERE id=?",
                    [$opisId, $now, $now, $rid]);
                $restored++;
            }
        }
        Database::run('DELETE FROM visa_deductions WHERE opis_id=?', [$opisId]);
        Database::run("UPDATE visa_opis SET status='formed', instruction_no='', instruction_date='', instructed_at=NULL, instruction_edited_by=NULL, instruction_edited_at=NULL WHERE id=?", [$opisId]);
        return $restored;
    }

    /** Откат служебки на шаг назад с очисткой подписи соответствующего этапа. */
    private static function revertMemo(int $id): array
    {
        $m = Database::one('SELECT * FROM stimulus_memos WHERE id=?', [$id]);
        if (!$m) { return self::err('Служебка не найдена.'); }
        $kind = $m['kind'] ?? 'staff';
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        // Снять последнюю действующую подпись в журнале для откатываемого этапа.
        if (in_array($m['status'], ['approved', 'deputy_signed', 'head_signed'], true)) {
            \App\Services\SignService::revokeLast('stimulus_memo', $id, (int) Auth::id());
        }
        // Гибкие штампы ЭП проставляются целиком на этапе «утверждено» — при уходе с него
        // снимаем их, иначе PDF продолжает показывать полностью подписанный документ.
        if ($m['status'] === 'approved') {
            self::runSafe('DELETE FROM stimulus_stamps WHERE memo_id=?', [$id]);
        }
        $res = null;
        switch ($m['status']) {
            case 'approved':
                if ($kind === 'mgmt') {
                    Database::run("UPDATE stimulus_memos SET status='draft', director_id=NULL, director_sign_type=NULL, director_signed_at=NULL, director_sign_hash=NULL, number=NULL WHERE id=?", [$id]);
                    $res = self::ok('Утверждение снято — служебка снова черновик (штампы ЭП сняты, номер освобождён).');
                } else {
                    Database::run("UPDATE stimulus_memos SET status='deputy_signed', director_id=NULL, director_sign_type=NULL, director_signed_at=NULL, director_sign_hash=NULL WHERE id=?", [$id]);
                    $res = self::ok('Утверждение директора снято — служебка на этапе «утверждена замом» (штампы ЭП сняты).');
                }
                break;
            case 'deputy_signed':
                Database::run("UPDATE stimulus_memos SET status='head_signed', deputy_id=NULL, deputy_sign_type=NULL, deputy_signed_at=NULL, deputy_sign_hash=NULL WHERE id=?", [$id]);
                $res = self::ok('Подпись зама снята — служебка на этапе «подписана начальником».');
                break;
            case 'head_signed':
                Database::run("UPDATE stimulus_memos SET status='draft', head_id=NULL, head_sign_type=NULL, head_signed_at=NULL, head_sign_hash=NULL, number=NULL WHERE id=?", [$id]);
                $res = self::ok('Подпись начальника снята — служебка снова черновик (номер освобождён).');
                break;
            case 'rejected':
            case 'revision':
                Database::run("UPDATE stimulus_memos SET status='draft', reject_reason=NULL, number=NULL WHERE id=?", [$id]);
                $res = self::ok('Служебка возвращена в черновик (номер освобождён).');
                break;
        }
        if ($res === null) { $pdo->rollBack(); return self::err('Служебка уже в статусе «черновик».'); }
        $pdo->commit();
        return $res;
    }

    /** Откат документа СЭД на шаг назад: registered/approved → on_approval → revision → draft. */
    private static function revertDocument(int $id): array
    {
        $d = Database::one('SELECT * FROM documents WHERE id=?', [$id]);
        if (!$d) { return self::err('Документ не найден.'); }
        $st = (string) $d['status'];
        $pdo = Database::pdo();
        if ($st === 'approved' || $st === 'registered') {
            $pdo->beginTransaction();
            // снять регистрацию + освободить бронь
            Database::run('UPDATE documents SET reg_number=NULL, reg_seq=NULL, reg_year=NULL, registered_at=NULL, registered_by=NULL, finished_at=NULL WHERE id=?', [$id]);
            Database::run('UPDATE doc_number_reservations SET doc_id=NULL WHERE doc_id=?', [$id]);
            // отменить последнюю визу/подпись и вернуть на её этап
            $last = Database::one("SELECT * FROM doc_approvers WHERE document_id=? AND status IN ('approved','acked','rejected','skipped') AND decided_at IS NOT NULL ORDER BY decided_at DESC, id DESC LIMIT 1", [$id]);
            if ($last) {
                Database::run("UPDATE doc_approvers SET status='pending', comment=NULL, decided_at=NULL, on_behalf_of=NULL, sign_type=NULL, sign_hash=NULL WHERE id=?", [(int) $last['id']]);
                Database::run("UPDATE documents SET status='on_approval', current_step=? WHERE id=?", [(int) $last['step_no'], $id]);
                // если откатываемый этап был «подписание» — погасить последнюю подпись в журнале
                if (($last['stage_type'] ?? '') === 'sign') { \App\Services\SignService::revokeLast('document', $id, (int) Auth::id()); }
            } else {
                Database::run("UPDATE documents SET status='on_approval' WHERE id=?", [$id]);
            }
            $pdo->commit();
            return self::ok('Документ возвращён на маршрут: снята регистрация и последняя виза.');
        }
        if ($st === 'on_approval') {
            Database::run("UPDATE documents SET status='revision' WHERE id=?", [$id]);
            return self::ok('Документ возвращён автору на доработку.');
        }
        if ($st === 'revision') {
            $pdo->beginTransaction();
            Database::run("UPDATE documents SET status='draft', sent_at=NULL, current_step=0 WHERE id=?", [$id]);
            Database::run("UPDATE doc_approvers SET status='pending', comment=NULL, decided_at=NULL, on_behalf_of=NULL, sign_type=NULL, sign_hash=NULL WHERE document_id=?", [$id]);
            $pdo->commit();
            return self::ok('Документ возвращён в черновик (маршрут сброшен).');
        }
        return self::err('Документ уже в черновике — откатывать нечего.');
    }

    // ============ helpers ============

    /** Снять зачтённую сделку «Виза — этап 2» при удалении/откате проверенной строки. */
    private static function reverseVisaCredit(array $row): void
    {
        if (empty($row['credited_at']) || empty($row['assigned_to'])) { return; }
        $opId = Database::scalar("SELECT id FROM operations WHERE name LIKE '%этап 2%' AND is_active=1 ORDER BY id LIMIT 1");
        if (!$opId) { return; }
        $day = substr((string) $row['credited_at'], 0, 10);
        $pw = Database::one('SELECT id, quantity FROM piecework WHERE employee_id=? AND operation_id=? AND work_date=?',
            [(int) $row['assigned_to'], (int) $opId, $day]);
        if (!$pw) { return; }
        if ((int) $pw['quantity'] > 1) { Database::run('UPDATE piecework SET quantity=quantity-1 WHERE id=?', [(int) $pw['id']]); }
        else { Database::run('DELETE FROM piecework WHERE id=?', [(int) $pw['id']]); }
    }

    /**
     * Удалить recheck-копии (повторная проверка после брака) для указанных оригиналов, если они
     * ещё не проконтролированы. Возвращает [удалено, заблокировано-проверенных]. ВЫЗЫВАТЬ в транзакции.
     */
    private static function purgeRecheckChildren(array $sourceIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $sourceIds)));
        if (!$ids) { return [0, 0]; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $children = Database::all("SELECT id FROM assignment_items WHERE recheck=1 AND source_item_id IN ($ph)", $ids);
        $deleted = 0; $blocked = 0;
        foreach ($children as $c) {
            $cid = (int) $c['id'];
            // Если повторную анкету уже проконтролировали (есть вердикт) — не сносим молча.
            if (Database::scalar('SELECT 1 FROM inspections WHERE dossier_id=? AND reviewed_at IS NOT NULL', [$cid])) { $blocked++; continue; }
            self::runSafe('DELETE FROM item_comments WHERE item_id=?', [$cid]);
            Database::run('DELETE FROM inspections WHERE dossier_id=?', [$cid]);
            Database::run('DELETE FROM assignment_items WHERE id=?', [$cid]);
            $deleted++;
        }
        return [$deleted, $blocked];
    }

    /** Рекурсивно удалить поручение со всеми дочерними (parent_id) и их журналами. Возвращает число удалённых. */
    private static function deleteOrderCascade(int $id): int
    {
        $stack = [$id]; $all = []; $guard = 0;
        while ($stack && $guard++ < 5000) {
            $cur = (int) array_pop($stack);
            if (isset($all[$cur])) { continue; }
            $all[$cur] = true;
            foreach (Database::all('SELECT id FROM orders WHERE parent_id=?', [$cur]) as $ch) { $stack[] = (int) $ch['id']; }
        }
        foreach (array_keys($all) as $oid) {
            self::runSafe('DELETE FROM order_events WHERE order_id=?', [$oid]);
            self::runSafe('DELETE FROM order_reports WHERE order_id=?', [$oid]);
            self::runSafe('DELETE FROM order_coexecutors WHERE order_id=?', [$oid]);
            self::runSafe('DELETE FROM order_due_log WHERE order_id=?', [$oid]);
            Database::run('DELETE FROM orders WHERE id=?', [$oid]);
        }
        return count($all);
    }

    /** Каскадное удаление служебки о стимуле (строки/корректировки/штампы/подписи). ВЫЗЫВАТЬ в транзакции. */
    private static function deleteStimMemoCascade(int $id): void
    {
        self::runSafe('DELETE FROM stimulus_overrides WHERE memo_line_id IN (SELECT id FROM stimulus_memo_lines WHERE memo_id=?)', [$id]);
        Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
        self::runSafe('DELETE FROM stimulus_stamps WHERE memo_id=?', [$id]);
        self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['stimulus_memo', $id]);
        Database::run('DELETE FROM stimulus_memos WHERE id=?', [$id]);
    }

    /** Откат перевода: вернуть прежнюю должность/отдел/оклад из предыдущего назначения и удалить текущее. */
    private static function revertTransfer(int $id): array
    {
        $a = Database::one('SELECT * FROM position_assignments WHERE id=?', [$id]);
        if (!$a) { return self::err('Назначение не найдено.'); }
        if ($a['ended_on'] !== null) { return self::err('Откатывается только текущий (последний) перевод сотрудника.'); }
        $uid = (int) $a['user_id'];
        $prev = Database::one('SELECT * FROM position_assignments WHERE user_id=? AND id<>? AND started_on<=? ORDER BY started_on DESC, id DESC LIMIT 1',
            [$uid, $id, (string) $a['started_on']]);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        if ($prev) {
            Database::run('UPDATE position_assignments SET ended_on=NULL WHERE id=?', [(int) $prev['id']]);
            Database::run('UPDATE users SET department_id=?, position_id=?, position=?, oklad=?, rate_volume=? WHERE id=?',
                [$prev['department_id'], $prev['position_id'], $prev['position_title'], $prev['oklad'], $prev['rate_volume'], $uid]);
        }
        Database::run('DELETE FROM position_assignments WHERE id=?', [$id]);
        $pdo->commit();
        Audit::log('TRANSFER_REVERT', 'Откат перевода сотрудника #' . $uid, ['assignment' => $id, 'restored_to' => $prev['id'] ?? null]);
        return self::ok($prev
            ? 'Перевод отменён — восстановлены прежние должность/отдел/оклад (ЗП пересчитается).'
            : 'Перевод (первичное назначение) удалён; карточка сотрудника не изменена — задайте должность вручную при необходимости.');
    }

    /** Удалить описи, оставшиеся без строк (после удаления партии/строки визы). ВЫЗЫВАТЬ в транзакции. */
    private static function pruneEmptyOpis(array $opisIds): void
    {
        foreach (array_unique(array_filter(array_map('intval', $opisIds))) as $oid) {
            if ((int) Database::scalar('SELECT COUNT(*) FROM visa_rows WHERE opis_id=?', [$oid]) === 0) {
                self::runSafe('DELETE FROM visa_deductions WHERE opis_id=?', [$oid]);
                self::runSafe('DELETE FROM document_signatures WHERE entity_type=? AND entity_id=?', ['visa_opis', $oid]);
                Database::run('DELETE FROM visa_opis WHERE id=?', [$oid]);
            }
        }
    }

    /** DELETE/UPDATE по необязательной таблице — молча игнорируем отсутствие таблицы. */
    private static function runSafe(string $sql, array $params): void
    {
        try { Database::run($sql, $params); } catch (\Throwable $e) { /* таблицы может не быть */ }
    }

    private static function ok(string $msg): array { return ['ok' => true, 'message' => $msg]; }
    private static function err(string $msg): array { return ['ok' => false, 'message' => $msg]; }
}
