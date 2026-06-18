<?php
/**
 * Создание схемы БД. Запуск:  php database/migrate.php
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$driver = Database::driver();
$pdo = Database::pdo();

// Различия синтаксиса между SQLite, MySQL и PostgreSQL.
if ($driver === 'mysql') {
    $ID = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $MONEY = 'DECIMAL(12,2)'; $NOW = 'CURRENT_TIMESTAMP'; $ENGINE = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
} elseif ($driver === 'pgsql') {
    $ID = 'SERIAL PRIMARY KEY';
    $MONEY = 'NUMERIC(12,2)'; $NOW = 'CURRENT_TIMESTAMP'; $ENGINE = '';
} else {
    $ID = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $MONEY = 'NUMERIC'; $NOW = "(datetime('now','localtime'))"; $ENGINE = '';
}
// PostgreSQL: нет типа DATETIME — заменяем на TIMESTAMP в любых DDL-строках.
$ddlFix = fn(string $s): string => $driver === 'pgsql' ? preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $s) : $s;

$tables = [];

$tables['users'] = "CREATE TABLE IF NOT EXISTS users (
    id $ID,
    full_name   VARCHAR(200) NOT NULL,
    login       VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role        VARCHAR(20)  NOT NULL DEFAULT 'employee',
    position    VARCHAR(150) DEFAULT '',
    oklad       $MONEY NOT NULL DEFAULT 0,
    rate_volume $MONEY NOT NULL DEFAULT 1,
    is_active   INT NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Сменный график колл-центра (2/2): план и факт по дням. Полумесяц/месяц = диапазон дат.
// plan_* — план (часы/смену, из них ночные); fact_* — факт; holiday/overtime — только факт.
$tables['shift_days'] = "CREATE TABLE IF NOT EXISTS shift_days (
    id $ID,
    employee_id    INT NOT NULL,
    work_date      DATE NOT NULL,
    plan_hours     $MONEY NOT NULL DEFAULT 0,
    plan_night     $MONEY NOT NULL DEFAULT 0,
    fact_hours     $MONEY NOT NULL DEFAULT 0,
    fact_night     $MONEY NOT NULL DEFAULT 0,
    holiday_hours  $MONEY NOT NULL DEFAULT 0,
    overtime_hours $MONEY NOT NULL DEFAULT 0,
    updated_at     TIMESTAMP DEFAULT $NOW,
    UNIQUE (employee_id, work_date)
) $ENGINE";

$tables['countries'] = "CREATE TABLE IF NOT EXISTS countries (
    code     VARCHAR(10) NOT NULL PRIMARY KEY,
    name     VARCHAR(150) NOT NULL,
    group_no INT NOT NULL DEFAULT 1
) $ENGINE";

$tables['price_groups'] = "CREATE TABLE IF NOT EXISTS price_groups (
    group_no INT NOT NULL PRIMARY KEY,
    title    VARCHAR(100) NOT NULL DEFAULT '',
    price    $MONEY NOT NULL DEFAULT 0
) $ENGINE";

$tables['timesheets'] = "CREATE TABLE IF NOT EXISTS timesheets (
    id $ID,
    employee_id INT NOT NULL,
    period      VARCHAR(7) NOT NULL,   -- YYYY-MM
    norm_days   INT NOT NULL DEFAULT 0,
    worked_days INT NOT NULL DEFAULT 0,
    UNIQUE (employee_id, period)
) $ENGINE";

$tables['error_types'] = "CREATE TABLE IF NOT EXISTS error_types (
    id $ID,
    name      VARCHAR(200) NOT NULL,
    penalty   $MONEY NOT NULL DEFAULT 0,
    is_active INT NOT NULL DEFAULT 1
) $ENGINE";

$tables['sample_batches'] = "CREATE TABLE IF NOT EXISTS sample_batches (
    id $ID,
    work_date     DATE NOT NULL UNIQUE,  -- проверяемый рабочий день
    controller_id INT,
    generated_at  TIMESTAMP DEFAULT $NOW,
    finished_at   TIMESTAMP NULL
) $ENGINE";

$tables['inspections'] = "CREATE TABLE IF NOT EXISTS inspections (
    id $ID,
    batch_id      INT NOT NULL,
    dossier_id    INT NOT NULL UNIQUE,
    employee_id   INT NOT NULL,
    controller_id INT,
    is_correct    INT NULL,           -- NULL = ещё не проверено
    error_type_id INT NULL,
    penalty_amount $MONEY NOT NULL DEFAULT 0,
    occurrence    INT NOT NULL DEFAULT 0, -- какое по счёту повторение типа у сотрудника
    reviewed_at   TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['notifications'] = "CREATE TABLE IF NOT EXISTS notifications (
    id $ID,
    employee_id INT NOT NULL,
    title    VARCHAR(255) NOT NULL,
    body     TEXT,
    is_read  INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['settings'] = "CREATE TABLE IF NOT EXISTS settings (
    skey  VARCHAR(100) NOT NULL PRIMARY KEY,
    sval  TEXT NOT NULL
) $ENGINE";

// ===== СЭД: оргструктура и документооборот =====
$tables['departments'] = "CREATE TABLE IF NOT EXISTS departments (
    id $ID,
    name    VARCHAR(200) NOT NULL,
    head_id INT NULL
) $ENGINE";

$tables['doc_types'] = "CREATE TABLE IF NOT EXISTS doc_types (
    id $ID,
    name   VARCHAR(150) NOT NULL,
    prefix VARCHAR(10)  NOT NULL DEFAULT 'Д'
) $ENGINE";

$tables['documents'] = "CREATE TABLE IF NOT EXISTS documents (
    id $ID,
    reg_number  VARCHAR(40) NULL,
    type_id     INT NOT NULL,
    title       VARCHAR(300) NOT NULL,
    body        TEXT,
    author_id   INT NOT NULL,
    department_id INT NULL,
    status      VARCHAR(20) NOT NULL DEFAULT 'draft',
    current_step INT NOT NULL DEFAULT 0,
    file_orig   VARCHAR(255) NULL,
    file_stored VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT $NOW,
    sent_at     TIMESTAMP NULL,
    finished_at TIMESTAMP NULL
) $ENGINE";

$tables['doc_approvers'] = "CREATE TABLE IF NOT EXISTS doc_approvers (
    id $ID,
    document_id INT NOT NULL,
    step_no     INT NOT NULL,                 -- номер ЭТАПА
    stage_type  VARCHAR(12) NOT NULL DEFAULT 'approve',  -- approve | sign | ack (ознакомление)
    parallel    INT NOT NULL DEFAULT 0,       -- 1 = участники этапа визируют параллельно
    user_id     INT NOT NULL,
    on_behalf_of INT NULL,                    -- виза поставлена заместителем за этого пользователя
    status      VARCHAR(20) NOT NULL DEFAULT 'pending',
    comment     TEXT NULL,
    decided_at  TIMESTAMP NULL
) $ENGINE";

// Версии вложений документа (+ извлечённый текст для полнотекстового поиска).
$tables['doc_files'] = "CREATE TABLE IF NOT EXISTS doc_files (
    id $ID,
    document_id INT NOT NULL,
    version     INT NOT NULL DEFAULT 1,
    orig_name   VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    size_bytes  INT NOT NULL DEFAULT 0,
    text_content TEXT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Явные читатели документов с грифом ДСП.
$tables['doc_readers'] = "CREATE TABLE IF NOT EXISTS doc_readers (
    id $ID,
    document_id INT NOT NULL,
    user_id     INT NOT NULL,
    UNIQUE (document_id, user_id)
) $ENGINE";

// Журналы регистрации (МосЭДО): собственная нумерация по году.
$tables['doc_journals'] = "CREATE TABLE IF NOT EXISTS doc_journals (
    id $ID,
    name       VARCHAR(150) NOT NULL,
    direction  VARCHAR(10) NOT NULL DEFAULT '',   -- ''|incoming|outgoing|internal
    prefix     VARCHAR(16) NOT NULL DEFAULT '',   -- префикс рег.№ (Вх/Исх/…)
    index_code VARCHAR(20) NOT NULL DEFAULT '',   -- индекс дела для формата 'index/seq-year'
    is_active  INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Бронь (резерв) регистрационных номеров.
$tables['doc_number_reservations'] = "CREATE TABLE IF NOT EXISTS doc_number_reservations (
    id $ID,
    journal_id  INT NOT NULL,
    reg_year    INT NOT NULL,
    reg_seq     INT NOT NULL,
    reg_number  VARCHAR(60) NOT NULL,
    reserved_by INT NULL,
    reserved_at TIMESTAMP DEFAULT $NOW,
    doc_id      INT NULL,                          -- занят документом (NULL = свободная бронь)
    note        VARCHAR(200) DEFAULT ''
) $ENGINE";

// Обращения граждан (59-ФЗ): срок 30 дней, продление, ответ.
$tables['appeals'] = "CREATE TABLE IF NOT EXISTS appeals (
    id $ID,
    number        VARCHAR(30) NULL,
    applicant     VARCHAR(300) NOT NULL,
    contact       VARCHAR(300) DEFAULT '',
    source        VARCHAR(30)  DEFAULT 'internet',  -- personal | mail | internet
    subject       VARCHAR(300) NOT NULL,
    body          TEXT,
    received_at   DATE NOT NULL,
    due_date      DATE NOT NULL,
    assignee_id   INT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'registered', -- registered|work|extended|answered
    answer        TEXT NULL,
    answered_at   TIMESTAMP NULL,
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['appeal_log'] = "CREATE TABLE IF NOT EXISTS appeal_log (
    id $ID,
    appeal_id  INT NOT NULL,
    event      VARCHAR(300) NOT NULL,
    user_name  VARCHAR(200) DEFAULT '',
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Отчёты по поручениям (промежуточные и итоговый).
$tables['order_reports'] = "CREATE TABLE IF NOT EXISTS order_reports (
    id $ID,
    order_id   INT NOT NULL,
    user_id    INT NOT NULL,
    kind       VARCHAR(10) NOT NULL DEFAULT 'interim',  -- interim | final
    text       TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Соисполнители поручений.
$tables['order_coexecutors'] = "CREATE TABLE IF NOT EXISTS order_coexecutors (
    id $ID,
    order_id INT NOT NULL,
    user_id  INT NOT NULL,
    UNIQUE (order_id, user_id)
) $ENGINE";

// История переносов сроков поручений.
$tables['order_due_log'] = "CREATE TABLE IF NOT EXISTS order_due_log (
    id $ID,
    order_id  INT NOT NULL,
    old_date  DATE NULL,
    new_date  DATE NULL,
    reason    VARCHAR(300) DEFAULT '',
    user_name VARCHAR(200) DEFAULT '',
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Лента событий поручения (создано/принято/отчёт/возврат/перенос/переадресация/контроль…).
$tables['order_events'] = "CREATE TABLE IF NOT EXISTS order_events (
    id $ID,
    order_id   INT NOT NULL,
    user_id    INT NULL,
    user_name  VARCHAR(200) DEFAULT '',
    event      VARCHAR(40) NOT NULL,
    detail     TEXT NULL,
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Шаблоны маршрутов согласования.
$tables['route_templates'] = "CREATE TABLE IF NOT EXISTS route_templates (
    id $ID,
    name VARCHAR(200) NOT NULL
) $ENGINE";

$tables['route_template_steps'] = "CREATE TABLE IF NOT EXISTS route_template_steps (
    id $ID,
    template_id INT NOT NULL,
    step_no     INT NOT NULL,
    stage_type  VARCHAR(12) NOT NULL DEFAULT 'approve',
    parallel    INT NOT NULL DEFAULT 0,
    user_id     INT NULL,                       -- конкретный сотрудник…
    role_slot   VARCHAR(20) NULL                -- …или роль: author_head | director | author
) $ENGINE";

$tables['doc_history'] = "CREATE TABLE IF NOT EXISTS doc_history (
    id $ID,
    document_id INT NOT NULL,
    user_name   VARCHAR(200) DEFAULT '',
    event       VARCHAR(200) NOT NULL,
    comment     TEXT NULL,
    created_at  TIMESTAMP DEFAULT $NOW
) $ENGINE";

// ===== Поручения руководителя =====
$tables['orders'] = "CREATE TABLE IF NOT EXISTS orders (
    id $ID,
    author_id   INT NOT NULL,
    assignee_id INT NOT NULL,
    title       VARCHAR(300) NOT NULL,
    body        TEXT,
    due_date    DATE NULL,
    status      VARCHAR(20) NOT NULL DEFAULT 'new',
    report      TEXT NULL,
    created_at  TIMESTAMP DEFAULT $NOW,
    done_at     TIMESTAMP NULL
) $ENGINE";

// ===== График отпусков =====
$tables['vacation_requests'] = "CREATE TABLE IF NOT EXISTS vacation_requests (
    id $ID,
    employee_id INT NOT NULL,
    year        INT NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    days        INT NOT NULL DEFAULT 0,
    kind        VARCHAR(10) NOT NULL DEFAULT 'initial',  -- initial | change
    replaces_id INT NULL,
    status      VARCHAR(20) NOT NULL DEFAULT 'on_head',  -- on_head|on_approve|approved|rejected|auto_rejected|replaced
    comment     TEXT NULL,
    created_at  TIMESTAMP DEFAULT $NOW,
    decided_at  TIMESTAMP NULL,
    notified_at TIMESTAMP NULL
) $ENGINE";

$tables['vacation_blackouts'] = "CREATE TABLE IF NOT EXISTS vacation_blackouts (
    id $ID,
    department_id INT NULL,
    employee_id   INT NULL,
    start_date    DATE NOT NULL,
    end_date      DATE NOT NULL,
    reason        VARCHAR(300) DEFAULT ''
) $ENGINE";

// ===== Бюджетирование ФОТ =====
$tables['pay_sources'] = "CREATE TABLE IF NOT EXISTS pay_sources (
    id $ID,
    name   VARCHAR(200) NOT NULL,
    kind   VARCHAR(20) NOT NULL DEFAULT 'vneb',  -- gz | subsidy | vneb
    detail VARCHAR(300) DEFAULT ''
) $ENGINE";

$tables['dept_budgets'] = "CREATE TABLE IF NOT EXISTS dept_budgets (
    id $ID,
    department_id INT NOT NULL,
    year      INT NOT NULL,
    source_id INT NOT NULL,
    amount    $MONEY NOT NULL DEFAULT 0,
    UNIQUE (department_id, year, source_id)
) $ENGINE";

// ===== Проекты (доступ к разделам меню) =====
$tables['projects'] = "CREATE TABLE IF NOT EXISTS projects (
    code VARCHAR(20) NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) $ENGINE";

$tables['user_projects'] = "CREATE TABLE IF NOT EXISTS user_projects (
    id $ID,
    user_id INT NOT NULL,
    project_code VARCHAR(20) NOT NULL,
    UNIQUE (user_id, project_code)
) $ENGINE";

// ===== Сертификаты электронной подписи =====
$tables['user_certificates'] = "CREATE TABLE IF NOT EXISTS user_certificates (
    id $ID,
    user_id    INT NOT NULL,
    sign_type  VARCHAR(10) NOT NULL DEFAULT 'PEP',  -- PEP | UNEP | UKEP
    serial     VARCHAR(100) NOT NULL,
    owner_name VARCHAR(200) NOT NULL,
    issued_at  DATE NOT NULL,
    valid_to   DATE NOT NULL
) $ENGINE";

// ===== Электронный табель (полумесячный, с ревизиями и подписью) =====
$tables['tabels'] = "CREATE TABLE IF NOT EXISTS tabels (
    id $ID,
    period        VARCHAR(10) NOT NULL,   -- YYYY-MM-1 | YYYY-MM-2
    department_id INT NULL,               -- NULL = по организации
    revision      INT NOT NULL DEFAULT 0, -- 0 = первичный, далее корректировочные
    status        VARCHAR(10) NOT NULL DEFAULT 'draft',  -- draft | signed
    created_by    INT NOT NULL,
    created_at    TIMESTAMP DEFAULT $NOW,
    signer_id     INT NULL,
    sign_type     VARCHAR(10) NULL,
    signed_at     TIMESTAMP NULL,
    sign_hash     VARCHAR(64) NULL,
    cert_serial   VARCHAR(100) NULL
) $ENGINE";

$tables['tabel_rows'] = "CREATE TABLE IF NOT EXISTS tabel_rows (
    id $ID,
    tabel_id    INT NOT NULL,
    employee_id INT NOT NULL,
    day_marks   VARCHAR(32) NOT NULL DEFAULT '',
    days        INT NOT NULL DEFAULT 0,
    UNIQUE (tabel_id, employee_id)
) $ENGINE";

// ===== Визы: ходатайства из Word =====
$tables['visa_batches'] = "CREATE TABLE IF NOT EXISTS visa_batches (
    id $ID,
    name        VARCHAR(200) NOT NULL,
    uploaded_by INT NULL,
    letter_date VARCHAR(20)  NOT NULL DEFAULT '02.05.25',   -- «от …» в шапке бланка
    entry_date  VARCHAR(20)  NOT NULL DEFAULT '15.02.25',   -- «Въезд в Россию с»
    stay_date   VARCHAR(20)  NOT NULL DEFAULT '15.05.26',   -- «Пребывание в России по»
    signer      VARCHAR(100) NOT NULL DEFAULT 'В.В. СУЩИК', -- подписант (М.П. / Ф.И.О.)
    created_at  TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['visa_rows'] = "CREATE TABLE IF NOT EXISTS visa_rows (
    id $ID,
    batch_id      INT NOT NULL,
    out_no        VARCHAR(50)  DEFAULT '',   -- Исходящий №
    out_date      VARCHAR(20)  DEFAULT '',
    surname_ru    VARCHAR(200) DEFAULT '',
    names_ru      VARCHAR(200) DEFAULT '',
    surname_lat   VARCHAR(200) DEFAULT '',
    names_lat     VARCHAR(200) DEFAULT '',
    citizenship   VARCHAR(100) DEFAULT '',
    residence     VARCHAR(100) DEFAULT '',   -- Государство проживания
    birth_date    VARCHAR(20)  DEFAULT '',
    birth_place   VARCHAR(200) DEFAULT '',
    sex           VARCHAR(10)  DEFAULT '',
    passport_no   VARCHAR(50)  DEFAULT '',
    issue_date    VARCHAR(20)  DEFAULT '',
    expiry_date   VARCHAR(20)  DEFAULT '',
    work_address  TEXT,
    visit_places  VARCHAR(300) DEFAULT '',
    visa_place    VARCHAR(300) DEFAULT '',
    source_file   VARCHAR(255) DEFAULT '',
    table_no      INT DEFAULT 0,
    ai_address    TEXT NULL,                 -- результат ИИ-подстановки
    assigned_to   INT NULL,
    checked_at    TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Опись по стране (запись на формирование ГП/описи). Подписант (ФИО+должность) фиксируется
// в момент формирования; после ответа МИД вносится № и дата визового указания.
$tables['visa_opis'] = "CREATE TABLE IF NOT EXISTS visa_opis (
    id $ID,
    country          VARCHAR(150) NOT NULL,
    signer_name      VARCHAR(150) NOT NULL DEFAULT '',
    signer_position  VARCHAR(255) NOT NULL DEFAULT '',
    instruction_no   VARCHAR(80)  NOT NULL DEFAULT '',   -- № визового указания МИД
    instruction_date VARCHAR(20)  NOT NULL DEFAULT '',
    status           VARCHAR(20)  NOT NULL DEFAULT 'formed', -- formed | instructed
    created_by       INT NULL,
    created_at       TIMESTAMP DEFAULT $NOW,
    instructed_at    TIMESTAMP NULL
) $ENGINE";

// Вычеты с первичного проверяющего при возврате строки на доработку после отказа МИД.
$tables['visa_deductions'] = "CREATE TABLE IF NOT EXISTS visa_deductions (
    id $ID,
    row_id      INT NOT NULL,              -- visa_rows.id (отклонённая строка)
    opis_id     INT NULL,                  -- из какой описи
    employee_id INT NOT NULL,              -- первичный проверяющий (с кого вычет)
    amount      $MONEY NOT NULL DEFAULT 0, -- стоимость проверки строки ИЛИ 0 (не его вина)
    period      VARCHAR(7) NOT NULL,       -- YYYY-MM (месяц фиксации для разнесения в ЗП)
    decided_by  INT NULL,
    reason      TEXT NULL,
    created_at  TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Журнал действий: кто и что сделал.
$tables['audit_log'] = "CREATE TABLE IF NOT EXISTS audit_log (
    id $ID,
    user_id   INT NULL,
    user_name VARCHAR(200) DEFAULT '',
    role      VARCHAR(20)  DEFAULT '',
    action    VARCHAR(120) DEFAULT '',
    label     VARCHAR(200) DEFAULT '',
    details   TEXT,
    ip        VARCHAR(45)  DEFAULT '',
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['positions'] = "CREATE TABLE IF NOT EXISTS positions (
    id $ID,
    title VARCHAR(150) NOT NULL UNIQUE,
    oklad $MONEY NOT NULL DEFAULT 0,
    is_active INT NOT NULL DEFAULT 1
) $ENGINE";

// Каталог сдельных операций (визы по этапам и любые другие, кроме анкет/досье).
$tables['operations'] = "CREATE TABLE IF NOT EXISTS operations (
    id $ID,
    name       VARCHAR(150) NOT NULL,
    unit_price $MONEY NOT NULL DEFAULT 0,
    is_active  INT NOT NULL DEFAULT 1
) $ENGINE";

// Записи сделки по операциям (количество за день).
$tables['piecework'] = "CREATE TABLE IF NOT EXISTS piecework (
    id $ID,
    employee_id  INT NOT NULL,
    operation_id INT NOT NULL,
    work_date    DATE NOT NULL,
    quantity     INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Фиксированные доплаты/подработки сотрудника (сумма за месяц, пропорц. отработанному времени).
$tables['employee_fixed_extras'] = "CREATE TABLE IF NOT EXISTS employee_fixed_extras (
    id $ID,
    employee_id    INT NOT NULL,
    name           VARCHAR(200) NOT NULL,
    monthly_amount $MONEY NOT NULL DEFAULT 0,
    is_active      INT NOT NULL DEFAULT 1
) $ENGINE";

// Менеджер проекта: загруженные списки на проверку.
$tables['assignment_lists'] = "CREATE TABLE IF NOT EXISTS assignment_lists (
    id $ID,
    name        VARCHAR(200) NOT NULL,
    uploaded_by INT,
    created_at  TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Досье из списков (единица работы: назначение + проверка).
$tables['assignment_items'] = "CREATE TABLE IF NOT EXISTS assignment_items (
    id $ID,
    list_id      INT NOT NULL,
    reg_number   VARCHAR(50) NOT NULL,
    country_code VARCHAR(10) NOT NULL,
    assigned_to  INT NULL,
    checked_at   TIMESTAMP NULL,
    comment_id   INT NULL,
    comment_text TEXT NULL,
    recheck      INT NOT NULL DEFAULT 0,   -- 1 = повторная проверка после брака
    source_item_id INT NULL,               -- исходная (бракованная) проверка
    excluded_user  INT NULL,               -- кому нельзя назначать (допустивший брак)
    created_at   TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Каталог комментариев-доработок (выбираются при проверке).
$tables['dorabotka_comments'] = "CREATE TABLE IF NOT EXISTS dorabotka_comments (
    id $ID,
    text      VARCHAR(500) NOT NULL,
    category  VARCHAR(100) NOT NULL DEFAULT 'Прочее',
    is_active INT NOT NULL DEFAULT 1
) $ENGINE";

// Связь досье ↔ причины доработки (может быть несколько).
$tables['item_comments'] = "CREATE TABLE IF NOT EXISTS item_comments (
    id $ID,
    item_id    INT NOT NULL,
    comment_id INT NOT NULL,
    UNIQUE (item_id, comment_id)
) $ENGINE";

// Учёт явки: открытие/закрытие рабочего дня (смены).
$tables['work_days'] = "CREATE TABLE IF NOT EXISTS work_days (
    id $ID,
    employee_id INT NOT NULL,
    work_date   DATE NOT NULL,
    opened_at   TIMESTAMP NULL,
    closed_at   TIMESTAMP NULL,
    UNIQUE (employee_id, work_date)
) $ENGINE";

// Чат: беседы, участники, сообщения, вложения.
$tables['conversations'] = "CREATE TABLE IF NOT EXISTS conversations (
    id $ID,
    type       VARCHAR(10) NOT NULL DEFAULT 'direct',
    title      VARCHAR(200) DEFAULT '',
    created_by INT,
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['conversation_members'] = "CREATE TABLE IF NOT EXISTS conversation_members (
    id $ID,
    conversation_id INT NOT NULL,
    user_id         INT NOT NULL,
    last_read_id    INT NOT NULL DEFAULT 0,
    UNIQUE (conversation_id, user_id)
) $ENGINE";

$tables['messages'] = "CREATE TABLE IF NOT EXISTS messages (
    id $ID,
    conversation_id INT NOT NULL,
    sender_id       INT NOT NULL,
    body            TEXT,
    created_at      TIMESTAMP DEFAULT $NOW
) $ENGINE";

$tables['message_attachments'] = "CREATE TABLE IF NOT EXISTS message_attachments (
    id $ID,
    message_id   INT NOT NULL,
    orig_name    VARCHAR(255) NOT NULL,
    stored_name  VARCHAR(255) NOT NULL,
    mime         VARCHAR(120) DEFAULT '',
    size_bytes   INT NOT NULL DEFAULT 0
) $ENGINE";

foreach ($tables as $name => $sql) {
    $pdo->exec($ddlFix($sql));
    echo "OK  таблица {$name}\n";
}

// Добавление недостающих колонок к существующим таблицам (миграции «на месте»).
function columnExists(string $table, string $column): bool
{
    $driver = \App\Core\Database::driver();
    if ($driver === 'mysql') {
        $n = \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        return (int) $n > 0;
    }
    if ($driver === 'pgsql') {
        $n = \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_name = ? AND column_name = ?',
            [strtolower($table), strtolower($column)]
        );
        return (int) $n > 0;
    }
    foreach (\App\Core\Database::all("PRAGMA table_info({$table})") as $col) {
        if (strcasecmp($col['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

if (!columnExists('users', 'position_id')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN position_id INT NULL');
    echo "OK  колонка users.position_id добавлена\n";
}
if (!columnExists('users', 'schedule_type')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN schedule_type VARCHAR(10) NOT NULL DEFAULT '5_2'");
    echo "OK  колонка users.schedule_type добавлена\n";
}
if (!columnExists('users', 'allowance')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN allowance $MONEY NOT NULL DEFAULT 0");
    echo "OK  колонка users.allowance добавлена\n";
}
if (!columnExists('users', 'does_anketas')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN does_anketas INT NOT NULL DEFAULT 1');
    echo "OK  колонка users.does_anketas добавлена\n";
}
if (!columnExists('users', 'does_operations')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN does_operations INT NOT NULL DEFAULT 0');
    echo "OK  колонка users.does_operations добавлена\n";
}
if (!columnExists('users', 'department_id')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN department_id INT NULL');
    echo "OK  колонка users.department_id добавлена\n";
}
// Принудительная смена пароля при первом входе (пароль задан админом). Существующие
// пользователи получают 1 — их текущий пароль задан админом, нужно сменить при следующем входе.
if (!columnExists('users', 'must_change_password')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password INT NOT NULL DEFAULT 1');
    echo "OK  колонка users.must_change_password добавлена\n";
}
foreach ([
    'is_timekeeper_org'  => 'INT NOT NULL DEFAULT 0',  // табельщик по организации
    'timekeeper_dept_id' => 'INT NULL',                // табельщик конкретного отдела
    'is_hr'              => 'INT NOT NULL DEFAULT 0',  // кадры (видит покрытие табелями)
    'is_accountant'      => 'INT NOT NULL DEFAULT 0',  // бухгалтерия (видит покрытие)
    'deputy_id'          => 'INT NULL',                // заместитель (СЭД) на период
    'deputy_from'        => 'DATE NULL',
    'deputy_to'          => 'DATE NULL',
    'email'              => "VARCHAR(200) DEFAULT ''", // для email-уведомлений
    'is_visa_manager'    => 'INT NOT NULL DEFAULT 0',  // менеджер проекта по визам
    // Недельный норматив проверки анкет «за оклад»: NULL = классическая модель max(оклад, сделка);
    // задан (>=0) = аддитивная модель (оклад+надбавка покрывают норматив, доплата по тарифу только сверх; 0 = чистый сдельщик).
    'anketa_norm'        => 'INT NULL DEFAULT NULL',
    // Персональная надбавка по ТК для почасовиков (2/2): % важнее, иначе фикс ₽/мес.
    'hourly_bonus_pct'   => 'NUMERIC NOT NULL DEFAULT 0',
    'hourly_bonus_rub'   => "$MONEY NOT NULL DEFAULT 0",
] as $col => $ddl) {
    if (!columnExists('users', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE users ADD COLUMN $col $ddl"));
        echo "OK  колонка users.$col добавлена\n";
    }
}
foreach ([
    'grif'        => "VARCHAR(10) NOT NULL DEFAULT ''",  // '' | ДСП — ограничение доступа
    'reply_to_id' => 'INT NULL',                          // связь «Ответ на документ»
] as $col => $ddl) {
    if (!columnExists('documents', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE documents ADD COLUMN $col $ddl"));
        echo "OK  колонка documents.$col добавлена\n";
    }
}
// МосЭДО: регистрация документов (журналы, собственный счётчик, дата регистрации, регистратор).
foreach ([
    'journal_id'    => 'INT NULL',         // журнал регистрации
    'reg_seq'       => 'INT NULL',         // порядковый номер в журнале за год (для счётчика)
    'reg_year'      => 'INT NULL',
    'registered_at' => 'DATETIME NULL',    // дата регистрации (отдельно от created/finished)
    'registered_by' => 'INT NULL',
] as $col => $ddl) {
    if (!columnExists('documents', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE documents ADD COLUMN $col $ddl"));
        echo "OK  колонка documents.$col добавлена\n";
    }
}
if (!columnExists('doc_types', 'journal_id')) {
    $pdo->exec('ALTER TABLE doc_types ADD COLUMN journal_id INT NULL');  // журнал по умолчанию для типа
    echo "OK  колонка doc_types.journal_id добавлена\n";
}
// Сид базовых журналов регистрации (один раз).
if ((int) \App\Core\Database::scalar('SELECT COUNT(*) FROM doc_journals') === 0) {
    foreach ([
        ['Входящие',   'incoming', 'Вх'],
        ['Исходящие',  'outgoing', 'Исх'],
        ['Внутренние', 'internal', ''],
    ] as [$jn, $jd, $jp]) {
        $pdo->prepare('INSERT INTO doc_journals (name, direction, prefix) VALUES (?,?,?)')->execute([$jn, $jd, $jp]);
    }
    echo "OK  журналы регистрации (базовые)\n";
}
if (!columnExists('orders', 'doc_id')) {
    $pdo->exec('ALTER TABLE orders ADD COLUMN doc_id INT NULL');  // поручение/резолюция по документу
    echo "OK  колонка orders.doc_id добавлена\n";
}
if (!columnExists('orders', 'parent_id')) {
    $pdo->exec('ALTER TABLE orders ADD COLUMN parent_id INT NULL');  // вложенная резолюция
    echo "OK  колонка orders.parent_id добавлена\n";
}
// Контроль исполнения поручений.
foreach ([
    'on_control'     => 'INT NOT NULL DEFAULT 0',   // на контроле
    'controller_id'  => 'INT NULL',                  // кто поставил на контроль
    'control_off_at' => 'DATETIME NULL',             // когда снято с контроля
    'control_result' => "VARCHAR(12) NULL",          // in_time | violated (при завершении)
    'remind_days'    => 'INT NOT NULL DEFAULT 3',    // напомнить за N дней до срока
    'last_remind_at' => 'DATETIME NULL',             // антиспам напоминаний (раз в день)
] as $col => $ddl) {
    if (!columnExists('orders', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE orders ADD COLUMN $col $ddl"));
        echo "OK  колонка orders.$col добавлена\n";
    }
}
// МосЭДО: вид поручения, № пункта резолюции, запрос продления срока.
foreach ([
    'kind'           => "VARCHAR(20) NOT NULL DEFAULT 'order'", // order|control|request|info
    'res_point'      => 'VARCHAR(8) NULL',                       // № пункта многопунктовой резолюции
    'ext_req_date'   => 'DATE NULL',                             // запрошенный новый срок
    'ext_req_reason' => 'VARCHAR(300) NULL',
    'ext_req_by'     => 'INT NULL',
    'ext_req_at'     => 'DATETIME NULL',
] as $col => $ddl) {
    if (!columnExists('orders', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE orders ADD COLUMN $col $ddl"));
        echo "OK  колонка orders.$col добавлена\n";
    }
}
// Соисполнители со своим исполнением (статус/отчёт по своей части).
foreach ([
    'status'  => "VARCHAR(12) NOT NULL DEFAULT 'work'", // work|done
    'report'  => 'TEXT NULL',
    'done_at' => 'DATETIME NULL',
] as $col => $ddl) {
    if (!columnExists('order_coexecutors', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE order_coexecutors ADD COLUMN $col $ddl"));
        echo "OK  колонка order_coexecutors.$col добавлена\n";
    }
}
// Контроль на уровне документа.
foreach ([
    'on_control'     => 'INT NOT NULL DEFAULT 0',
    'control_due'    => 'DATE NULL',
    'controller_id'  => 'INT NULL',
    'control_off_at' => 'DATETIME NULL',
] as $col => $ddl) {
    if (!columnExists('documents', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE documents ADD COLUMN $col $ddl"));
        echo "OK  колонка documents.$col добавлена\n";
    }
}
if (!columnExists('doc_approvers', 'file_version')) {
    $pdo->exec('ALTER TABLE doc_approvers ADD COLUMN file_version INT NULL');  // версия вложения на момент визы
    echo "OK  колонка doc_approvers.file_version добавлена\n";
}
foreach (['recheck' => 'INT NOT NULL DEFAULT 0', 'source_item_id' => 'INT NULL', 'excluded_user' => 'INT NULL'] as $col => $ddl) {
    if (!columnExists('assignment_items', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE assignment_items ADD COLUMN $col $ddl"));
        echo "OK  колонка assignment_items.$col добавлена\n";
    }
}
if (!columnExists('doc_files', 'text_content')) {
    $pdo->exec('ALTER TABLE doc_files ADD COLUMN text_content TEXT NULL');
    echo "OK  колонка doc_files.text_content добавлена\n";
}
if (!columnExists('doc_types', 'journal_index')) {
    $pdo->exec("ALTER TABLE doc_types ADD COLUMN journal_index VARCHAR(20) DEFAULT ''");  // индекс дела для нумератора
    echo "OK  колонка doc_types.journal_index добавлена\n";
}
foreach ([
    'credited_at'  => 'DATETIME NULL',          // когда зачтена как операция «Виза — этап 2» (защита от повторного зачёта)
    'rework_note'  => 'TEXT NULL',              // замечание при возврате на доработку
    'rework_by'    => 'INT NULL',
    'rework_at'    => 'DATETIME NULL',
    'rework_count' => 'INT NOT NULL DEFAULT 0',
    // Конвейер статусов и доработка после отказа МИД.
    'opis_id'       => 'INT NULL',                            // членство в описи (FK visa_opis.id)
    'status'        => "VARCHAR(20) NOT NULL DEFAULT 'loaded'", // loaded|assigned|checked|in_opis|instructed|rework
    'recheck'       => 'INT NOT NULL DEFAULT 0',              // 1 = повторная проверка после отказа МИД
    'source_row_id' => 'INT NULL',                            // исходная (отклонённая) строка
    'excluded_user' => 'INT NULL',                            // кому нельзя назначать (первичный проверяющий)
    'mid_refused_at'=> 'DATETIME NULL',                       // когда МИД отклонил строку
    'mid_refuse_note'=> 'TEXT NULL',                          // причина/комментарий отказа
] as $col => $ddl) {
    if (!columnExists('visa_rows', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE visa_rows ADD COLUMN $col $ddl"));
        echo "OK  колонка visa_rows.$col добавлена\n";
    }
}
// Разовая инициализация статусов по существующим данным (checked_at / assigned_to).
if (\App\Services\Settings::get('visa_status_init_v1', '') !== '1') {
    $pdo->exec("UPDATE visa_rows SET status='checked'  WHERE checked_at IS NOT NULL AND (status IS NULL OR status='loaded')");
    $pdo->exec("UPDATE visa_rows SET status='assigned' WHERE checked_at IS NULL AND assigned_to IS NOT NULL AND (status IS NULL OR status='loaded')");
    \App\Services\Settings::set('visa_status_init_v1', '1');
    echo "OK  начальные статусы visa_rows проставлены\n";
}
foreach ([
    'letter_date' => "VARCHAR(20) NOT NULL DEFAULT '02.05.25'",
    'entry_date'  => "VARCHAR(20) NOT NULL DEFAULT '15.02.25'",
    'stay_date'   => "VARCHAR(20) NOT NULL DEFAULT '15.05.26'",
    'signer'      => "VARCHAR(100) NOT NULL DEFAULT 'В.В. СУЩИК'",
] as $col => $ddl) {
    if (!columnExists('visa_batches', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE visa_batches ADD COLUMN $col $ddl"));
        echo "OK  колонка visa_batches.$col добавлена\n";
    }
}
// Аудит правки визового указания (кто/когда менял № и дату уже внесённого указания).
foreach ([
    'instruction_edited_by' => 'INT NULL',
    'instruction_edited_at' => 'DATETIME NULL',
] as $col => $ddl) {
    if (!columnExists('visa_opis', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE visa_opis ADD COLUMN $col $ddl"));
        echo "OK  колонка visa_opis.$col добавлена\n";
    }
}
// ЭП-штамп на подписях документов СЭД (по образцу служебок).
foreach ([
    'sign_type' => "VARCHAR(8) NULL",
    'sign_hash' => "VARCHAR(80) NULL",
] as $col => $ddl) {
    if (!columnExists('doc_approvers', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE doc_approvers ADD COLUMN $col $ddl"));
        echo "OK  колонка doc_approvers.$col добавлена\n";
    }
}
// Прямое назначение стимула вышестоящим (сокращённый маршрут подписи).
if (!columnExists('stimulus_memos', 'direct_tier')) {
    $pdo->exec("ALTER TABLE stimulus_memos ADD COLUMN direct_tier VARCHAR(12) NULL"); // director|deputy
    echo "OK  колонка stimulus_memos.direct_tier добавлена\n";
}
// Связь сгенерированной служебки-проекта с назначением надбавки.
if (!columnExists('stimulus_memos', 'grant_id')) {
    $pdo->exec("ALTER TABLE stimulus_memos ADD COLUMN grant_id INT NULL");
    echo "OK  колонка stimulus_memos.grant_id добавлена\n";
}
// Цель строки стимула (за анкеты/визы/другое) — сделка сверх оклада «зарабатывает» стимул.
if (!columnExists('stimulus_memo_lines', 'purpose')) {
    $pdo->exec("ALTER TABLE stimulus_memo_lines ADD COLUMN purpose VARCHAR(10) NOT NULL DEFAULT 'other'"); // anketas|visas|other
    echo "OK  колонка stimulus_memo_lines.purpose добавлена\n";
}
// Основание из справочника по отделам (для памяти «за что доплата»).
if (!columnExists('stimulus_memo_lines', 'reason_id')) {
    $pdo->exec("ALTER TABLE stimulus_memo_lines ADD COLUMN reason_id INT NULL");
    echo "OK  колонка stimulus_memo_lines.reason_id добавлена\n";
}
if (!columnExists('dorabotka_comments', 'category')) {
    $pdo->exec("ALTER TABLE dorabotka_comments ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT 'Прочее'");
    echo "OK  колонка dorabotka_comments.category добавлена\n";
}

// ===== Мультироли, переводы, служебки о стимуле =====
$extra = [];

// Номенклатура дел (справочник дел со сроком хранения) + списание документов в дело.
$extra['nomenclature_cases'] = "CREATE TABLE IF NOT EXISTS nomenclature_cases (
    id $ID,
    index_code   VARCHAR(30) NOT NULL,             -- индекс дела, напр. 01-15
    title        VARCHAR(300) NOT NULL,            -- заголовок дела
    department_id INT NULL,                         -- подразделение-владелец
    storage_term VARCHAR(40) NOT NULL DEFAULT '5 лет', -- срок хранения (текст: «5 лет», «постоянно», «75 лет»)
    storage_years INT NULL,                          -- срок в годах (NULL = постоянно) — для авторасчёта уничтожения
    year         INT NOT NULL,                       -- год номенклатуры
    status       VARCHAR(12) NOT NULL DEFAULT 'open',-- open | closed | archived
    closed_on    DATE NULL,
    destroy_after INT NULL,                          -- год, после которого можно уничтожить
    is_active    INT NOT NULL DEFAULT 1,
    created_at   TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Справочник корреспондентов (для входящих/исходящих документов).
$extra['correspondents'] = "CREATE TABLE IF NOT EXISTS correspondents (
    id $ID,
    name    VARCHAR(300) NOT NULL,
    kind    VARCHAR(12) NOT NULL DEFAULT 'org',   -- org | citizen | gov
    inn     VARCHAR(20) DEFAULT '',
    address VARCHAR(300) DEFAULT '',
    contact VARCHAR(200) DEFAULT '',
    is_active INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Каталог назначаемых ролей (набор ролей у сотрудника, а не одна).
$extra['roles'] = "CREATE TABLE IF NOT EXISTS roles (
    slug   VARCHAR(40) PRIMARY KEY,
    name   VARCHAR(120) NOT NULL,
    descr  VARCHAR(255) DEFAULT '',
    sort   INT NOT NULL DEFAULT 0
) $ENGINE";

$extra['user_roles'] = "CREATE TABLE IF NOT EXISTS user_roles (
    user_id   INT NOT NULL,
    role_slug VARCHAR(40) NOT NULL,
    UNIQUE (user_id, role_slug)
) $ENGINE";

// История назначений (отдел+должность+оклад+ставка) — для переводов и помесячного перерасчёта.
$extra['position_assignments'] = "CREATE TABLE IF NOT EXISTS position_assignments (
    id $ID,
    user_id       INT NOT NULL,
    department_id INT NULL,
    position_id   INT NULL,
    position_title VARCHAR(150) DEFAULT '',
    oklad         $MONEY NOT NULL DEFAULT 0,
    rate_volume   $MONEY NOT NULL DEFAULT 1,
    started_on    DATE NOT NULL,
    ended_on      DATE NULL,
    reason        VARCHAR(255) DEFAULT '',
    created_by    INT NULL,
    created_at    TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Перечень оснований (раздел 4 Положения: показатели интенсивности/результативности/качества).
$extra['stimulus_grounds'] = "CREATE TABLE IF NOT EXISTS stimulus_grounds (
    id $ID,
    text       VARCHAR(500) NOT NULL,
    category   VARCHAR(120) NOT NULL DEFAULT 'Общие',
    is_active  INT NOT NULL DEFAULT 1
) $ENGINE";

// Служебная записка об установлении стимулирующих выплат (маршрут ЭП).
$extra['stimulus_memos'] = "CREATE TABLE IF NOT EXISTS stimulus_memos (
    id $ID,
    number        VARCHAR(40) DEFAULT '',
    department_id INT NULL,
    author_id     INT NOT NULL,             -- начальник отдела (составитель)
    period        VARCHAR(7) NOT NULL,       -- YYYY-MM
    pay_kind      VARCHAR(12) NOT NULL DEFAULT 'monthly', -- monthly | onetime
    source_id     INT NULL,                  -- источник выплат (pay_sources): госзадание/субсидия/внебюджет
    grounds       TEXT DEFAULT '',           -- основания (склейка выбранных, для текста и проверки дублей)
    grounds_ids   VARCHAR(255) DEFAULT '',   -- id выбранных оснований через запятую
    status        VARCHAR(20) NOT NULL DEFAULT 'draft',
        -- draft | head_signed | deputy_signed | approved | rejected | revision
    head_id       INT NULL, head_sign_type VARCHAR(8) NULL, head_signed_at DATETIME NULL, head_sign_hash VARCHAR(80) NULL,
    deputy_id     INT NULL, deputy_sign_type VARCHAR(8) NULL, deputy_signed_at DATETIME NULL, deputy_sign_hash VARCHAR(80) NULL,
    director_id   INT NULL, director_sign_type VARCHAR(8) NULL, director_signed_at DATETIME NULL, director_sign_hash VARCHAR(80) NULL,
    reject_reason VARCHAR(500) DEFAULT '',
    created_at    TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Строки служебки: сотрудник + сумма + вид + период + расчётный %.
$extra['stimulus_memo_lines'] = "CREATE TABLE IF NOT EXISTS stimulus_memo_lines (
    id $ID,
    memo_id     INT NOT NULL,
    user_id     INT NOT NULL,
    amount      $MONEY NOT NULL DEFAULT 0,
    pay_kind    VARCHAR(12) NOT NULL DEFAULT 'monthly',
    period_from DATE NULL,
    period_to   DATE NULL,
    oklad_load  $MONEY NOT NULL DEFAULT 0,   -- оклад на нагрузку = номин.оклад × ставка (без отработки)
    percent     $MONEY NOT NULL DEFAULT 0    -- amount / oklad_load × 100
) $ENGINE";

// Корректировки стимула вышестоящим (снижение/отмена). Оригинальная строка остаётся;
// в ЗП и сводной берётся последняя корректировка по строке (аудит «кто и на сколько снизил»).
$extra['stimulus_overrides'] = "CREATE TABLE IF NOT EXISTS stimulus_overrides (
    id $ID,
    memo_line_id INT NOT NULL,           -- stimulus_memo_lines.id (корректируемое назначение)
    new_amount   $MONEY NOT NULL DEFAULT 0, -- новая сумма (0 = отмена); только ≤ исходной
    by_user_id   INT NOT NULL,           -- кто снизил/отменил (вышестоящий)
    reason       TEXT NULL,
    created_at   TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Назначение надбавки на период (через стимул): порождает ежемесячные служебки-проекты.
$extra['allowance_grants'] = "CREATE TABLE IF NOT EXISTS allowance_grants (
    id $ID,
    user_id     INT NOT NULL,
    amount      $MONEY NOT NULL DEFAULT 0,    -- надбавка в месяц, ₽
    period_from DATE NOT NULL,
    period_to   DATE NOT NULL,
    grounds_ids VARCHAR(255) DEFAULT '',      -- id оснований из stimulus_grounds через запятую
    grounds     TEXT NULL,                    -- склейка текстов оснований
    source_id   INT NULL,                     -- источник выплат (pay_sources)
    assigned_by INT NULL,
    status      VARCHAR(12) NOT NULL DEFAULT 'active', -- active | canceled
    created_at  TIMESTAMP DEFAULT $NOW
) $ENGINE";

// Справочник оснований стимула по отделам (свободный, вне формальных stimulus_grounds):
// начальник вспоминает, за что назначена каждая доплата. department_id NULL = общие.
$extra['stimulus_reasons'] = "CREATE TABLE IF NOT EXISTS stimulus_reasons (
    id $ID,
    department_id INT NULL,
    text          VARCHAR(500) NOT NULL,
    is_active     INT NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT $NOW
) $ENGINE";

foreach ($extra as $name => $sql) {
    $pdo->exec($ddlFix($sql));
    echo "OK  таблица {$name}\n";
}

// Куратор-зам подразделения (маршрут служебки: начальник → курирующий зам → директор).
if (!columnExists('departments', 'curator_id')) {
    $pdo->exec('ALTER TABLE departments ADD COLUMN curator_id INT NULL');
    echo "OK  колонка departments.curator_id добавлена\n";
}
// Документы: направление (входящий/исходящий/внутренний) + реквизиты корреспондента.
foreach ([
    'direction'        => "VARCHAR(10) NOT NULL DEFAULT 'internal'", // incoming | outgoing | internal
    'correspondent_id' => 'INT NULL',
    'correspondent_name' => "VARCHAR(300) DEFAULT ''",  // денормализовано (от кого/кому)
    'incoming_number'  => "VARCHAR(60) DEFAULT ''",      // исх.№ корреспондента (для входящего)
    'incoming_date'    => 'DATE NULL',                    // дата документа корреспондента
    'delivery'         => "VARCHAR(30) DEFAULT ''",       // способ доставки/отправки
    'case_id'          => 'INT NULL',                     // дело номенклатуры (списан в дело)
    'filed_at'         => 'DATETIME NULL',
    'filed_by'         => 'INT NULL',
] as $col => $ddl) {
    if (!columnExists('documents', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE documents ADD COLUMN $col $ddl"));
        echo "OK  колонка documents.$col добавлена\n";
    }
}
// Иерархия оргструктуры: родительский узел (отдел → центр → … ) и вид узла.
foreach ([
    'parent_id' => 'INT NULL',                                  // вышестоящий узел (NULL = в подчинении директора)
    'kind'      => "VARCHAR(20) NOT NULL DEFAULT 'отдел'",       // отдел | центр | управление | дирекция
] as $col => $ddl) {
    if (!columnExists('departments', $col)) {
        $pdo->exec($ddlFix("ALTER TABLE departments ADD COLUMN $col $ddl"));
        echo "OK  колонка departments.$col добавлена\n";
    }
}
// Перенос штрафа: если зафиксирован после подачи служебки — учитывается в следующем месяце.
if (!columnExists('inspections', 'defer_to_period')) {
    $pdo->exec("ALTER TABLE inspections ADD COLUMN defer_to_period VARCHAR(7) NULL");
    echo "OK  колонка inspections.defer_to_period добавлена\n";
}
// Свободный комментарий контролёра к ошибке (помимо стандартизованного типа).
if (!columnExists('inspections', 'controller_comment')) {
    $pdo->exec("ALTER TABLE inspections ADD COLUMN controller_comment TEXT NULL");
    echo "OK  колонка inspections.controller_comment добавлена\n";
}

// Каталог ролей (идемпотентно).
$rolesCatalog = [
    ['director',         'Директор',                       'Утверждает служебки (финальная подпись)', 10],
    ['deputy_director',  'Заместитель директора',          'Курирует отделы, утверждает служебки', 20],
    ['dept_head',        'Начальник отдела',               'Составляет и подписывает служебки', 30],
    ['accountant',       'Бухгалтерия',                    'Видит подписанные служебки, покрытие табелями', 40],
    ['hr',               'Кадры',                          'Покрытие табелями, кадровые действия', 50],
    ['timekeeper',       'Табельщик',                      'Ведение табеля', 60],
    ['hr_manager',       'Менеджер проекта: кадры',        'Оргструктура, должности, табель (раздел Кадры)', 55],
    ['anketa_manager',   'Менеджер проекта: анкеты',       'Загрузка и распределение анкет (квота)', 70],
    ['visa_manager',     'Менеджер проекта: визы',         'Загрузка и распределение визовых ходатайств', 80],
    ['finance_manager',  'Менеджер проекта: финансы',      'Бюджет ФОТ, тарифы, доплаты, основания стимула', 85],
    ['docs_manager',     'Менеджер проекта: документы',    'Типы документов, обращения, номенклатура дел', 86],
    ['clerk',            'Делопроизводитель (регистратор)','Регистрация документов: присвоение/правка рег.№, журналы, бронь номера', 87],
    ['doc_controller',   'Контролёр документов',           'Постановка документов на контроль и мониторинг исполнения', 88],
    ['controller',       'Контролёр',                      'Выборочная проверка (8%)', 90],
    ['anketa_worker',    'Специалист: проверка анкет',     'Проверка назначенных анкет (квота)', 100],
    ['visa_worker',      'Специалист: проверка виз',       'Проверка назначенных визовых строк', 110],
    ['piecework_worker', 'Специалист: операции',           'Учёт сдельных операций (визы поэтапно и пр.)', 120],
];
foreach ($rolesCatalog as [$slug, $name, $descr, $sort]) {
    $has = \App\Core\Database::scalar('SELECT 1 FROM roles WHERE slug = ?', [$slug]);
    if ($has) {
        $pdo->prepare('UPDATE roles SET name=?, descr=?, sort=? WHERE slug=?')->execute([$name, $descr, $sort, $slug]);
    } else {
        $pdo->prepare('INSERT INTO roles (slug, name, descr, sort) VALUES (?,?,?,?)')->execute([$slug, $name, $descr, $sort]);
    }
}
echo "OK  каталог ролей (" . count($rolesCatalog) . ")\n";

// --- Однократная миграция старых флагов/ролей в user_roles ---
if ((int) \App\Core\Database::scalar('SELECT COUNT(*) FROM user_roles') === 0) {
    $assign = function (int $uid, string $slug) use ($pdo) {
        \App\Core\Database::insertIgnore('INSERT INTO user_roles (user_id, role_slug) VALUES (?,?)', [$uid, $slug], 'user_id, role_slug');
    };
    foreach (\App\Core\Database::all('SELECT * FROM users') as $u) {
        $uid = (int) $u['id'];
        if ($u['role'] === 'manager')    { $assign($uid, 'anketa_manager'); }
        if ($u['role'] === 'controller') { $assign($uid, 'controller'); }
        if ((int) ($u['is_visa_manager'] ?? 0) === 1) { $assign($uid, 'visa_manager'); }
        if ((int) ($u['is_hr'] ?? 0) === 1)           { $assign($uid, 'hr'); }
        if ((int) ($u['is_accountant'] ?? 0) === 1)   { $assign($uid, 'accountant'); }
        if ((int) ($u['is_timekeeper_org'] ?? 0) === 1 || !empty($u['timekeeper_dept_id'])) { $assign($uid, 'timekeeper'); }
        if ($u['role'] === 'employee') {
            if ((int) ($u['does_anketas'] ?? 0) === 1)    { $assign($uid, 'anketa_worker'); }
            if ((int) ($u['does_operations'] ?? 0) === 1) { $assign($uid, 'piecework_worker'); }
        }
    }
    echo "OK  старые флаги перенесены в user_roles\n";
}

// Начальники отделов → роль dept_head (идемпотентная синхронизация при каждом запуске).
foreach (\App\Core\Database::all('SELECT head_id FROM departments WHERE head_id IS NOT NULL') as $d) {
    \App\Core\Database::insertIgnore('INSERT INTO user_roles (user_id, role_slug) VALUES (?,?)', [(int) $d['head_id'], 'dept_head'], 'user_id, role_slug');
}

// Вид служебки: staff (обычная, Прил.№1, маршрут начальник→зам→директор)
// | mgmt (директор устанавливает стимул замам/гл. бухгалтеру, Прил.№2, подпись только директора).
if (!columnExists('stimulus_memos', 'kind')) {
    $pdo->exec("ALTER TABLE stimulus_memos ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'staff'");
    echo "OK  колонка stimulus_memos.kind добавлена\n";
}

// Колонка нормативного % основания (макс. размер выплаты, % от должностного оклада).
if (!columnExists('stimulus_grounds', 'percent')) {
    $pdo->exec($ddlFix('ALTER TABLE stimulus_grounds ADD COLUMN percent ' . $MONEY . ' NOT NULL DEFAULT 0'));
    echo "OK  колонка stimulus_grounds.percent добавлена\n";
}

// ОСНОВАНИЯ (показатели стимулирования) — ТОЧНО из Положения об оплате и стимулировании
// труда ФГБУ «Интеробразование» (2023): Приложение № 1 (интенсивность/высокие результаты
// и качество) и Приложение № 2 (для замов директора и гл. бухгалтера). Колонка percent —
// «Размер выплаты, % от должностного оклада» (до X%).
$I = 'Интенсивность и высокие результаты'; // Приложение № 1, таблица 1
$K = 'Качество выполняемых работ';         // Приложение № 1, таблица 2
$R = 'Руководители (замы, гл. бухгалтер)'; // Приложение № 2
$grounds = [
    // — Приложение № 1: за интенсивность и высокие результаты работы —
    ['Участие в подготовке и проведении важных организационных мероприятий, связанных с основной деятельностью Учреждения, а также мероприятий, направленных на повышение авторитета и имиджа Учреждения', $I, 400],
    ['Многообразие видов выполняемых работ (трудовых операций) в процессе трудовой деятельности', $I, 300],
    ['Оперативное и качественное выполнение особо важных и срочных заданий руководства Учреждения, руководителя структурного подразделения', $I, 200],
    ['Разработка новых программ, инновационных методик', $I, 400],
    ['Внедрение и использование современных информационных технологий при выполнении особо важных и ответственных поручений', $I, 400],
    ['Оперативность и профессионализм в решении вопросов, входящих в компетенцию работника, при подготовке документов, выполнении поручений руководителя', $I, 200],
    ['Достижение высоких результатов в работе за определённый период', $I, 500],
    ['Использование новых эффективных технологий в процессе работы', $I, 400],
    ['Наставничество', $I, 200],
    ['Повышение эффективности работы, сокращение сроков выполнения отдельных элементов трудовой деятельности, а также поручений непосредственного руководителя', $I, 150],
    ['Активное участие и личный вклад работника Учреждения в обеспечение выполнения и реализацию мероприятий, задач, поставленных перед Учреждением', $I, 300],
    ['Достижение высоких результатов в работе с заключёнными государственными (муниципальными) контрактами, гражданско-правовыми договорами, соглашениями с внешними контрагентами', $I, 500],
    ['Особый режим работы, связанный с обеспечением безаварийной, безотказной и бесперебойной работы инженерных и хозяйственно-эксплуатационных систем жизнеобеспечения Учреждения', $I, 200],
    ['Участие в развитии приносящей доход деятельности Учреждения', $I, 200],
    // — Приложение № 1: за качество выполняемых работ —
    ['Отсутствие нарушений работником Учреждения исполнительской и трудовой дисциплины', $K, 100],
    ['Успешное и добросовестное исполнение работником своих должностных обязанностей в соответствующем периоде', $K, 200],
    ['Качественная подготовка и проведение мероприятий, связанных с уставной деятельностью Учреждения', $K, 300],
    ['Высокое качество подготовки документов', $K, 150],
    ['Обеспечение стабильного функционирования Учреждения', $K, 150],
    ['Выполнение показателей государственного задания', $K, 400],
    ['Соблюдение установленных сроков представления отчётности и отсутствие замечаний со стороны пользователей отчётности', $K, 400],
    ['Эффективное и успешное выполнение порученной работы, связанной с обеспечением рабочего периода или уставной деятельности Учреждения', $K, 300],
    ['Прохождение Учреждением контрольно-надзорных мероприятий без замечаний/предписаний со стороны органов (должностных лиц), наделённых полномочиями по осуществлению контроля (надзора)', $K, 200],
    ['Участие в соответствующем периоде в выполнении важных работ, реализации проектов', $K, 300],
    ['Выполнение плановых показателей для структурных подразделений', $K, 300],
    ['Отсутствие замечаний по работе структурного подразделения', $K, 300],
    ['Разумная инициатива, творческий подход к исполнению должностных обязанностей, применение в работе современных форм и методов осуществления труда', $K, 250],
    ['Качественное и своевременное организационно-техническое обеспечение деятельности Учреждения', $K, 300],
    ['Качественная организация работы структурного подразделения Учреждения', $K, 400],
    ['Обеспечение безаварийной работы систем жизнеобеспечения Учреждения', $K, 200],
    // — Приложение № 2: для заместителей генерального директора и главного бухгалтера —
    ['Достижение ключевых показателей эффективности работы курируемого направления деятельности Учреждения', $R, 400],
    ['Выполнение показателей государственного задания по курируемому направлению деятельности', $R, 400],
    ['Отсутствие обоснованных жалоб и замечаний по деятельности курируемых структурных подразделений', $R, 300],
    ['Качественная подготовка и своевременная сдача отчётности', $R, 300],
    ['Успешное (без нарушений) прохождение проверок контрольно-надзорных органов, отсутствие предписаний проверяющих органов по курируемому направлению деятельности', $R, 400],
    ['Своевременное материально-техническое обеспечение деятельности Учреждения', $R, 200],
    ['Своевременное создание необходимых условий для проведения запланированных Учреждением мероприятий', $R, 200],
    ['Безаварийная, безотказная и бесперебойная работа технических средств и оборудования в Учреждении', $R, 150],
    ['Увеличение объёма полученных Учреждением средств от приносящей доход деятельности', $R, 200],
    ['Организация и проведение мероприятий, направленных на повышение авторитета и имиджа Учреждения', $R, 300],
    ['Инициативность деятельности', $R, 300],
    ['Качественная и своевременная организация работы по закупкам товаров, работ и услуг для нужд Учреждения, своевременное включение закупок в план и его выполнение, обеспечение исполнения заключённых гражданско-правовых договоров', $R, 300],
    ['Соблюдение сроков и порядка сдачи бухгалтерской, налоговой и иных видов предусмотренной отчётности', $R, 200],
    ['Эффективная работа с контрагентами-должниками', $R, 200],
];
// Каноничный перечень из Положения проставляется один раз (флаг grounds_polozhenie_2023).
// Перезаписывает прежние ориентировочные основания на точные показатели с реальными %.
if (\App\Services\Settings::get('grounds_polozhenie_2023', '') !== '1') {
    $pdo->exec('DELETE FROM stimulus_grounds');
    $st = $pdo->prepare('INSERT INTO stimulus_grounds (text, category, percent, is_active) VALUES (?,?,?,1)');
    foreach ($grounds as [$text, $cat, $pct]) { $st->execute([$text, $cat, $pct]); }
    \App\Services\Settings::set('grounds_polozhenie_2023', '1');
    echo "OK  основания стимула из Положения (" . count($grounds) . ", с реальными %)\n";
}

// Причины доработок: ранее сид содержал список ДВАЖДЫ — чистим дубли и приводим к
// каноничному уникальному перечню (один раз, флаг dorabotka_clean_v2), затем UNIQUE-индекс,
// чтобы дубли больше не появлялись (в т.ч. при добавлении через интерфейс).
if (\App\Services\Settings::get('dorabotka_clean_v2', '') !== '1') {
    $reasons = array_values(array_unique(require __DIR__ . '/seed_comments.php'));
    // Категоризатор — те же правила, что в seed.php.
    $dcat = function (string $text): string {
        $t = mb_strtolower($text, 'UTF-8');
        $rules = [
            'Справка ВИЧ' => ['вич'], 'Гепатит/туберкулёз' => ['гепатит', 'туберкул'],
            'Медицинская справка' => ['медицинск', 'мед. справк', 'медсправ', 'флюорограф'],
            'Паспорт' => ['паспорт', 'удостоверени личности'],
            'Образование/диплом' => ['диплом', 'об образовани', 'аттестат', 'автореферат', 'публикац', 'олимпиад', 'академсправк', 'приложени к диплом', 'табель', 'обучени'],
            'Анкета' => ['анкет'], 'Гражданство' => ['гражданств', 'легальност', 'внж', 'рвп'],
            'Согласие/перс. данные' => ['согласие', 'персональных данных'],
            'Направление/уровень' => ['направлени', 'уровень', 'форма обучения', 'образовательн программ', 'специальност', 'дпо'],
            'Список/Россотрудничество' => ['россотрудничеств', 'основной список', 'дублир', 'дубль', 'реестр', 'иас фркп', 'квот'],
        ];
        foreach ($rules as $cat => $kws) { foreach ($kws as $kw) { if (mb_strpos($t, $kw) !== false) { return $cat; } } }
        return 'Прочее';
    };
    $pdo->exec('DELETE FROM dorabotka_comments');
    $st = $pdo->prepare('INSERT INTO dorabotka_comments (text, category, is_active) VALUES (?,?,1)');
    foreach ($reasons as $t) { $st->execute([$t, $dcat($t)]); }
    \App\Services\Settings::set('dorabotka_clean_v2', '1');
    echo "OK  причины доработок очищены от дублей (" . count($reasons) . " уникальных)\n";
}
// Защита от повторных дублей по тексту.
try { $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_dorabotka_text ON dorabotka_comments(text)'); } catch (\Throwable $e) {}

// Демо-номенклатура дел текущего года (если пусто).
if ((int) \App\Core\Database::scalar('SELECT COUNT(*) FROM nomenclature_cases') === 0) {
    $yy = (int) date('Y');
    $cases = [
        ['01-01', 'Приказы по основной деятельности', 'постоянно', null],
        ['01-15', 'Служебные записки и докладные', '5 лет', 5],
        ['02-03', 'Входящая корреспонденция', '5 лет', 5],
        ['02-04', 'Исходящая корреспонденция', '5 лет', 5],
        ['03-01', 'Обращения граждан', '5 лет', 5],
    ];
    foreach ($cases as [$idx, $title, $term, $years]) {
        $pdo->prepare('INSERT INTO nomenclature_cases (index_code, title, storage_term, storage_years, year) VALUES (?,?,?,?,?)')
            ->execute([$idx, $title, $term, $years, $yy]);
    }
    echo "OK  номенклатура дел (" . count($cases) . " дел)\n";
}

echo "Схема готова (драйвер: {$driver}).\n";
