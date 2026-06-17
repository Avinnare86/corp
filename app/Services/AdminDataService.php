<?php
namespace App\Services;

use App\Core\Database;
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
            'memo'            => 'Служебки о стимуле',
            'document'        => 'Документы СЭД',
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
        switch ($entity) {
            case 'visa_row':
                $row = Database::one('SELECT * FROM visa_rows WHERE id=?', [$id]);
                if (!$row) { return self::err('Запись не найдена.'); }
                $pdo->beginTransaction();
                self::reverseVisaCredit($row);
                Database::run('DELETE FROM visa_deductions WHERE row_id=?', [$id]);
                Database::run('DELETE FROM visa_rows WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Визовая анкета удалена (вычеты сняты, сделка скорректирована).');

            case 'visa_batch':
                $batch = Database::one('SELECT * FROM visa_batches WHERE id=?', [$id]);
                if (!$batch) { return self::err('Партия не найдена.'); }
                $rows = Database::all('SELECT * FROM visa_rows WHERE batch_id=?', [$id]);
                $pdo->beginTransaction();
                foreach ($rows as $r) { self::reverseVisaCredit($r); }
                Database::run('DELETE FROM visa_deductions WHERE row_id IN (SELECT id FROM visa_rows WHERE batch_id=?)', [$id]);
                Database::run('DELETE FROM visa_rows WHERE batch_id=?', [$id]);
                Database::run('DELETE FROM visa_batches WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Партия и её ' . count($rows) . ' анкет удалены (вычеты сняты, сделка скорректирована).');

            case 'assignment_item':
                if (!Database::scalar('SELECT 1 FROM assignment_items WHERE id=?', [$id])) { return self::err('Анкета не найдена.'); }
                $pdo->beginTransaction();
                Database::run('DELETE FROM item_comments WHERE item_id=?', [$id]);
                Database::run('DELETE FROM inspections WHERE dossier_id=?', [$id]);
                Database::run('DELETE FROM assignment_items WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Анкета удалена (замечания и связанная проверка-выборка сняты).');

            case 'assignment_list':
                if (!Database::scalar('SELECT 1 FROM assignment_lists WHERE id=?', [$id])) { return self::err('Список не найден.'); }
                $cnt = (int) Database::scalar('SELECT COUNT(*) FROM assignment_items WHERE list_id=?', [$id]);
                $pdo->beginTransaction();
                Database::run('DELETE FROM item_comments WHERE item_id IN (SELECT id FROM assignment_items WHERE list_id=?)', [$id]);
                Database::run('DELETE FROM inspections WHERE dossier_id IN (SELECT id FROM assignment_items WHERE list_id=?)', [$id]);
                Database::run('DELETE FROM assignment_items WHERE list_id=?', [$id]);
                Database::run('DELETE FROM assignment_lists WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Список и его ' . $cnt . ' анкет удалены (замечания и проверки-выборки сняты).');

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
                Database::run('DELETE FROM tabels WHERE id=?', [$id]);
                $pdo->commit();
                if ($wasSigned) { TabelController::syncMonth($month); }
                return self::ok('Табель удалён' . ($wasSigned ? ' (месячный табель пересчитан).' : '.'));

            case 'memo':
                if (!Database::scalar('SELECT 1 FROM stimulus_memos WHERE id=?', [$id])) { return self::err('Служебка не найдена.'); }
                $pdo->beginTransaction();
                Database::run('DELETE FROM stimulus_memo_lines WHERE memo_id=?', [$id]);
                Database::run('DELETE FROM stimulus_memos WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Служебка о стимуле удалена (строки выплат сняты — ЗП пересчитается).');

            case 'document':
                if (!Database::scalar('SELECT 1 FROM documents WHERE id=?', [$id])) { return self::err('Документ не найден.'); }
                $pdo->beginTransaction();
                Database::run('DELETE FROM orders WHERE doc_id=?', [$id]);            // каскад поручений/резолюций (по решению)
                self::runSafe('DELETE FROM doc_readers WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_approvers WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_files WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_history WHERE document_id=?', [$id]);
                self::runSafe('DELETE FROM doc_number_reservations WHERE doc_id=?', [$id]); // освободить бронь
                Database::run('DELETE FROM documents WHERE id=?', [$id]);
                $pdo->commit();
                return self::ok('Документ СЭД удалён (маршрут, файлы-метаданные, история и поручения по нему сняты; файлы на диске не удаляются).');

            case 'user':
                return self::deleteUser($id, $adminId);
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
                    Database::run('DELETE FROM item_comments WHERE item_id=?', [$id]);
                    Database::run('DELETE FROM inspections WHERE dossier_id=?', [$id]);
                    Database::run('UPDATE assignment_items SET checked_at=NULL, comment_id=NULL, comment_text=NULL WHERE id=?', [$id]);
                    $pdo->commit();
                    return self::ok('Проверка анкеты снята (связанная проверка-выборка удалена) — анкета снова в работе.');
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
                TabelController::syncMonth(substr((string) $t['period'], 0, 7));
                return self::ok('Подпись снята — табель снова черновик. Месячный табель пересчитан.');

            case 'memo':
                return self::revertMemo($id);

            case 'document':
                return self::revertDocument($id);

            case 'user':
                $u = Database::one('SELECT id, is_active FROM users WHERE id=?', [$id]);
                if (!$u) { return self::err('Сотрудник не найден.'); }
                $new = (int) $u['is_active'] ? 0 : 1;
                Database::run('UPDATE users SET is_active=? WHERE id=?', [$new, $id]);
                return self::ok($new ? 'Сотрудник активирован.' : 'Сотрудник деактивирован.');

            case 'visa_batch':
            case 'assignment_list':
                return self::err('У загрузки нет статуса для отката — используйте удаление.');
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
        switch ($m['status']) {
            case 'approved':
                if ($kind === 'mgmt') {
                    Database::run("UPDATE stimulus_memos SET status='draft', director_id=NULL, director_sign_type=NULL, director_signed_at=NULL, director_sign_hash=NULL WHERE id=?", [$id]);
                    return self::ok('Утверждение директора снято — служебка снова черновик.');
                }
                Database::run("UPDATE stimulus_memos SET status='deputy_signed', director_id=NULL, director_sign_type=NULL, director_signed_at=NULL, director_sign_hash=NULL WHERE id=?", [$id]);
                return self::ok('Утверждение директора снято — служебка возвращена на этап «утверждена замом».');
            case 'deputy_signed':
                Database::run("UPDATE stimulus_memos SET status='head_signed', deputy_id=NULL, deputy_sign_type=NULL, deputy_signed_at=NULL, deputy_sign_hash=NULL WHERE id=?", [$id]);
                return self::ok('Подпись зама снята — служебка на этапе «подписана начальником».');
            case 'head_signed':
                Database::run("UPDATE stimulus_memos SET status='draft', head_id=NULL, head_sign_type=NULL, head_signed_at=NULL, head_sign_hash=NULL WHERE id=?", [$id]);
                return self::ok('Подпись начальника снята — служебка снова черновик.');
            case 'rejected':
            case 'revision':
                Database::run("UPDATE stimulus_memos SET status='draft', reject_reason=NULL WHERE id=?", [$id]);
                return self::ok('Служебка возвращена в черновик.');
        }
        return self::err('Служебка уже в статусе «черновик».');
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

    /** DELETE/UPDATE по необязательной таблице — молча игнорируем отсутствие таблицы. */
    private static function runSafe(string $sql, array $params): void
    {
        try { Database::run($sql, $params); } catch (\Throwable $e) { /* таблицы может не быть */ }
    }

    private static function ok(string $msg): array { return ['ok' => true, 'message' => $msg]; }
    private static function err(string $msg): array { return ['ok' => false, 'message' => $msg]; }
}
