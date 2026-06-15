<?php
/**
 * Начальные данные. Запуск:  php database/seed.php
 * Безопасно перезапускать — пропускает уже существующие записи.
 */
$config = require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

function upsertUser(array $u): void
{
    $exists = Database::scalar('SELECT id FROM users WHERE login = ?', [$u['login']]);
    if ($exists) {
        echo "  пропуск (есть): {$u['login']}\n";
        return;
    }
    Database::insert(
        'INSERT INTO users (full_name, login, password_hash, role, position, oklad, rate_volume, is_active)
         VALUES (?,?,?,?,?,?,?,1)',
        [
            $u['full_name'], $u['login'],
            password_hash($u['password'], PASSWORD_DEFAULT),
            $u['role'], $u['position'] ?? '', $u['oklad'] ?? 0, $u['rate_volume'] ?? 1,
        ]
    );
    echo "  создан: {$u['login']} / {$u['password']} ({$u['role']})\n";
}

echo "Пользователи:\n";
upsertUser(['full_name' => 'Администратор', 'login' => 'admin', 'password' => 'admin123', 'role' => 'admin', 'position' => 'Администратор', 'oklad' => 0]);
upsertUser(['full_name' => 'Контролёр Иванов И.И.', 'login' => 'control', 'password' => 'control123', 'role' => 'controller', 'position' => 'Контролёр-эксперт', 'oklad' => 40000, 'rate_volume' => 1]);
upsertUser(['full_name' => 'Петров П.П.', 'login' => 'petrov', 'password' => 'petrov123', 'role' => 'employee', 'position' => 'Специалист', 'oklad' => 30000, 'rate_volume' => 1]);
upsertUser(['full_name' => 'Сидорова А.А.', 'login' => 'sidorova', 'password' => 'sidorova123', 'role' => 'employee', 'position' => 'Специалист', 'oklad' => 30000, 'rate_volume' => 0.5]);
upsertUser(['full_name' => 'Менеджер проекта', 'login' => 'manager', 'password' => 'manager123', 'role' => 'manager', 'position' => 'Менеджер проекта (анкеты)', 'oklad' => 0]);
upsertUser(['full_name' => 'Менеджер по визам', 'login' => 'vizman', 'password' => 'vizman123', 'role' => 'employee', 'position' => 'Менеджер по визам', 'oklad' => 30000]);

echo "Должности (справочник окладов):\n";
$positions = [
    ['Специалист 1 категории', 27100],
    ['Ведущий специалист', 27100],
];
foreach ($positions as [$title, $oklad]) {
    $has = Database::scalar('SELECT id FROM positions WHERE title = ?', [$title]);
    if (!$has) {
        Database::insert('INSERT INTO positions (title, oklad, is_active) VALUES (?,?,1)', [$title, $oklad]);
        echo "  {$title}: оклад {$oklad} ₽\n";
    }
}
// Привязка демо-специалистов к должности «Специалист 1 категории».
$posId = (int) Database::scalar("SELECT id FROM positions WHERE title = 'Специалист 1 категории'");
if ($posId) {
    Database::run("UPDATE users SET position_id = ? WHERE login IN ('petrov','sidorova') AND position_id IS NULL", [$posId]);
}

echo "Тарифы (3 группы): простые 50 / остальные 70 / сложные 90:\n";
// Группа 1 = простые (50), 2 = остальные/по умолчанию (70), 3 = сложные (90).
$groups = [
    [1, 'Простые (ближнее зарубежье)', 50],
    [2, 'Остальные (по умолчанию)', 70],
    [3, 'Сложные', 90],
];
foreach ($groups as [$no, $title, $price]) {
    if (Database::scalar('SELECT group_no FROM price_groups WHERE group_no = ?', [$no])) {
        Database::run('UPDATE price_groups SET title=?, price=? WHERE group_no=?', [$title, $price, $no]);
    } else {
        Database::insert('INSERT INTO price_groups (group_no, title, price) VALUES (?,?,?)', [$no, $title, $price]);
    }
    echo "  группа {$no}: {$price} ₽\n";
}
Database::run('DELETE FROM price_groups WHERE group_no > 3'); // убрать старые 4/5

echo "Справочник стран (по ISO-коду; не указанные = тариф 70):\n";
// Простые (50) — группа 1.
$simple = ['AZE'=>'Азербайджан','ARM'=>'Армения','BLR'=>'Белоруссия','KAZ'=>'Казахстан','KGZ'=>'Киргизия','UZB'=>'Узбекистан','TJK'=>'Таджикистан'];
// Сложные (90) — группа 3.
$complex = ['DZA'=>'Алжир','ESH'=>'Алжир (Западная Сахара)','AGO'=>'Ангола','BGD'=>'Бангладеш','BFA'=>'Буркина-Фасо','BDI'=>'Бурунди','GAB'=>'Габон','HTI'=>'Гаити','GHA'=>'Гана','GIN'=>'Гвинея','DMA'=>'Доминика','ZMB'=>'Замбия','IRQ'=>'Ирак','IRN'=>'Иран','YEM'=>'Йемен','KHM'=>'Камбоджа','COG'=>'Конго','CIV'=>'Кот-д’Ивуар','LBN'=>'Ливан','MRT'=>'Мавритания','MDG'=>'Мадагаскар','MYS'=>'Малайзия','MLI'=>'Мали','MAR'=>'Марокко','MOZ'=>'Мозамбик','MCO'=>'Монако','MMR'=>'Мьянма','NPL'=>'Непал','NER'=>'Нигер','NGA'=>'Нигерия','OMN'=>'Оман','RWA'=>'Руанда','SAU'=>'Саудовская Аравия','SEN'=>'Сенегал','SYR'=>'Сирия','THA'=>'Таиланд','TZA'=>'Танзания','FRA'=>'Франция','CAF'=>'ЦАР','TCD'=>'Чад','LKA'=>'Шри-Ланка','GNQ'=>'Экваториальная Гвинея','ETH'=>'Эфиопия','ZAF'=>'ЮАР','SSD'=>'Южный Судан','JPN'=>'Япония'];
// Особенность (70) — явно группа 2 (для справочника), включая страны загружаемых списков.
$special = ['ABH'=>'Абхазия','BWA'=>'Ботсвана','VEN'=>'Венесуэла','VNM'=>'Вьетнам','KEN'=>'Кения','LVA'=>'Латвия','UGA'=>'Уганда','OST'=>'Южная Осетия','GEO'=>'Грузия','CHN'=>'Китай'];
foreach ([[$simple,1],[$complex,3],[$special,2]] as [$set,$grp]) {
    foreach ($set as $code => $name) {
        if (Database::scalar('SELECT code FROM countries WHERE code = ?', [$code])) {
            Database::run('UPDATE countries SET name=?, group_no=? WHERE code=?', [$name, $grp, $code]);
        } else {
            Database::insert('INSERT INTO countries (code, name, group_no) VALUES (?,?,?)', [$code, $name, $grp]);
        }
    }
}
echo "  простых: " . count($simple) . ", сложных: " . count($complex) . ", особых: " . count($special) . "\n";

echo "Операции (сделка кроме анкет):\n";
$operations = [
    ['Виза — этап 1', 10],
    ['Виза — этап 2', 15],
    ['Виза — этап 3', 10],
];
foreach ($operations as [$name, $price]) {
    $has = Database::scalar('SELECT id FROM operations WHERE name = ?', [$name]);
    if (!$has) {
        Database::insert('INSERT INTO operations (name, unit_price, is_active) VALUES (?,?,1)', [$name, $price]);
        echo "  {$name}: {$price} ₽/шт\n";
    }
}

// Демо: Сидорова — Call-центр 2/2 (только операции/визы); Петров — анкеты+операции, надбавка 8000.
Database::run("UPDATE users SET schedule_type='2_2', does_anketas=0, does_operations=1 WHERE login='sidorova'");
Database::run("UPDATE users SET allowance=8000, does_anketas=1, does_operations=1 WHERE login='petrov'");
$petrovId = (int) Database::scalar("SELECT id FROM users WHERE login='petrov'");
if ($petrovId && !Database::scalar('SELECT id FROM employee_fixed_extras WHERE employee_id=? AND name=?', [$petrovId, 'Ведение реестра'])) {
    Database::insert('INSERT INTO employee_fixed_extras (employee_id, name, monthly_amount, is_active) VALUES (?,?,?,1)',
        [$petrovId, 'Ведение реестра', 12000]);
    echo "  демо фикс-доплата Петрову: Ведение реестра 12000 ₽\n";
}

// Демо-табель на текущий месяц (чтобы гарантия отображалась сразу).
$curPeriod = date('Y-m');
foreach (['petrov' => [21, 21], 'sidorova' => [15, 15]] as $login => [$norm, $worked]) {
    $eid = (int) Database::scalar('SELECT id FROM users WHERE login = ?', [$login]);
    if ($eid && !Database::scalar('SELECT id FROM timesheets WHERE employee_id=? AND period=?', [$eid, $curPeriod])) {
        Database::insert('INSERT INTO timesheets (employee_id, period, norm_days, worked_days) VALUES (?,?,?,?)',
            [$eid, $curPeriod, $norm, $worked]);
    }
}

echo "Типы ошибок:\n";
$errors = [
    ['Опечатка в данных', 50],
    ['Не проверен источник', 150],
    ['Пропущено несоответствие', 300],
    ['Неверная классификация', 200],
];
foreach ($errors as [$name, $penalty]) {
    $has = Database::scalar('SELECT id FROM error_types WHERE name = ?', [$name]);
    if (!$has) {
        Database::insert('INSERT INTO error_types (name, penalty, is_active) VALUES (?,?,1)', [$name, $penalty]);
        echo "  {$name}: −{$penalty} ₽\n";
    }
}

echo "Настройки:\n";
$settings = [
    'inspection_percent'     => (string) $config['defaults']['inspection_percent'],
    'penalty_step'           => '0.5',   // ступенчатая эскалация: +0.5 к множителю за повтор
    'penalty_max_multiplier' => '2.0',   // потолок множителя штрафа
    'daily_norm'             => '60',    // целевая дневная норма анкет (ориентир)
];
foreach ($settings as $k => $v) {
    $has = Database::scalar('SELECT skey FROM settings WHERE skey = ?', [$k]);
    if (!$has) {
        Database::insert('INSERT INTO settings (skey, sval) VALUES (?,?)', [$k, $v]);
        echo "  {$k} = {$v}\n";
    }
}

echo "Каталог комментариев-доработок:\n";
// Категория по ключевым словам (первое совпадение).
function dorabotkaCategory(string $text): string {
    $t = mb_strtolower($text, 'UTF-8');
    $rules = [
        'Справка ВИЧ'            => ['вич'],
        'Гепатит/туберкулёз'     => ['гепатит', 'туберкул'],
        'Медицинская справка'    => ['медицинск', 'мед. справк', 'медсправ', 'флюорограф'],
        'Паспорт'                => ['паспорт', 'удостоверени личности'],
        'Образование/диплом'     => ['диплом', 'об образовани', 'аттестат', 'автореферат', 'публикац', 'олимпиад', 'академсправк', 'приложени к диплом', 'табель', 'обучени'],
        'Анкета'                 => ['анкет'],
        'Гражданство'            => ['гражданств', 'легальност', 'внж', 'рвп'],
        'Согласие/перс. данные'  => ['согласие', 'персональных данных'],
        'Направление/уровень'    => ['направлени', 'уровень', 'форма обучения', 'образовательн программ', 'специальност', 'дпо'],
        'Список/Россотрудничество' => ['россотрудничеств', 'основной список', 'дублир', 'дубль', 'реестр', 'иас фркп', 'квот'],
    ];
    foreach ($rules as $cat => $kws) {
        foreach ($kws as $kw) { if (mb_strpos($t, $kw) !== false) { return $cat; } }
    }
    return 'Прочее';
}
if ((int) Database::scalar('SELECT COUNT(*) FROM dorabotka_comments') === 0) {
    $comments = array_values(array_unique(require __DIR__ . '/seed_comments.php'));
    foreach ($comments as $text) {
        Database::insert('INSERT INTO dorabotka_comments (text, category, is_active) VALUES (?,?,1)', [$text, dorabotkaCategory($text)]);
    }
    echo "  загружено комментариев: " . count($comments) . "\n";
} else {
    echo "  уже есть\n";
}

echo "СЭД: оргструктура и типы документов:\n";
if ((int) Database::scalar('SELECT COUNT(*) FROM departments') === 0) {
    $petrovId2 = (int) Database::scalar("SELECT id FROM users WHERE login='petrov'");
    $mgrId = (int) Database::scalar("SELECT id FROM users WHERE login='manager'");
    $d1 = Database::insert('INSERT INTO departments (name, head_id) VALUES (?,?)', ['Отдел проверки анкет', $petrovId2 ?: null]);
    $d2 = Database::insert('INSERT INTO departments (name, head_id) VALUES (?,?)', ['Управление проектом', $mgrId ?: null]);
    Database::run("UPDATE users SET department_id = ? WHERE login IN ('petrov','sidorova')", [$d1]);
    Database::run("UPDATE users SET department_id = ? WHERE login IN ('manager','control')", [$d2]);
    echo "  подразделения: Отдел проверки анкет (рук. Петров), Управление проектом (рук. Менеджер)\n";
}
if ((int) Database::scalar('SELECT COUNT(*) FROM doc_types') === 0) {
    $types = [['Приказ','ПР'],['Распоряжение','РСП'],['Служебная записка','СЗ'],['Заявление','ЗВЛ'],['Протокол','ПРТ'],['Акт','АКТ'],['Договор','ДОГ']];
    foreach ($types as [$n,$p]) { Database::insert('INSERT INTO doc_types (name, prefix) VALUES (?,?)', [$n,$p]); }
    echo "  типы документов: " . count($types) . "\n";
}

echo "Проекты и подключения:\n";
if ((int) Database::scalar('SELECT COUNT(*) FROM projects') === 0) {
    foreach ([['kvota','Квота'],['visa','Визы'],['kadry','Кадры'],['fin','Финансы'],['docs','Документы']] as [$c,$n]) {
        Database::insert('INSERT INTO projects (code, name) VALUES (?,?)', [$c, $n]);
    }
    $proj = function (string $login, array $codes) {
        $uid = (int) Database::scalar('SELECT id FROM users WHERE login = ?', [$login]);
        if (!$uid) { return; }
        foreach ($codes as $c) {
            Database::insert('INSERT INTO user_projects (user_id, project_code) VALUES (?,?)', [$uid, $c]);
        }
    };
    $proj('petrov',   ['kvota','visa','kadry','docs']);
    $proj('sidorova', ['visa','kadry','docs']);
    $proj('control',  ['kvota','kadry','docs']);
    $proj('manager',  ['kvota','visa','kadry','fin','docs']);
    $proj('admin',    ['kvota','visa','kadry','fin','docs']);
    echo "  5 проектов, демо-подключения назначены\n";
}
// Демо: табельщик отдела и кадры/бухгалтерия.
upsertUser(['full_name' => 'Кадровик К.К.', 'login' => 'hr', 'password' => 'hr123', 'role' => 'employee', 'position' => 'Специалист по кадрам', 'oklad' => 0]);
upsertUser(['full_name' => 'Бухгалтер Б.Б.', 'login' => 'buh', 'password' => 'buh123', 'role' => 'employee', 'position' => 'Бухгалтер', 'oklad' => 0]);
Database::run("UPDATE users SET is_hr=1 WHERE login='hr'");
Database::run("UPDATE users SET is_accountant=1 WHERE login='buh'");
$d1id = (int) Database::scalar("SELECT id FROM departments WHERE name LIKE 'Отдел проверки%'");
if ($d1id) { Database::run("UPDATE users SET timekeeper_dept_id=? WHERE login='sidorova'", [$d1id]); }
foreach (['hr' => ['kadry'], 'buh' => ['kadry','fin']] as $lg => $codes) {
    $uid = (int) Database::scalar('SELECT id FROM users WHERE login = ?', [$lg]);
    foreach ($codes as $c) {
        if (!Database::scalar('SELECT 1 FROM user_projects WHERE user_id=? AND project_code=?', [$uid, $c])) {
            Database::insert('INSERT INTO user_projects (user_id, project_code) VALUES (?,?)', [$uid, $c]);
        }
    }
}

echo "Источники выплат:\n";
if ((int) Database::scalar('SELECT COUNT(*) FROM pay_sources') === 0) {
    Database::insert("INSERT INTO pay_sources (name, kind, detail) VALUES ('Госзадание', 'gz', '')");
    Database::insert("INSERT INTO pay_sources (name, kind, detail) VALUES ('Целевая субсидия', 'subsidy', 'Субсидия на иные цели (отбор иностранных граждан)')");
    Database::insert("INSERT INTO pay_sources (name, kind, detail) VALUES ('Внебюджет', 'vneb', '')");
    echo "  Госзадание, Целевая субсидия, Внебюджет\n";
}

echo "Шаблон маршрута СЭД:\n";
if ((int) Database::scalar('SELECT COUNT(*) FROM route_templates') === 0) {
    $pid = (int) Database::scalar("SELECT id FROM users WHERE login='petrov'");
    $aid = (int) Database::scalar("SELECT id FROM users WHERE login='admin'");
    $mid = (int) Database::scalar("SELECT id FROM users WHERE login='manager'");
    if ($pid && $aid && $mid) {
        $tid = Database::insert("INSERT INTO route_templates (name) VALUES ('Типовой: согласование → подписание → ознакомление')");
        Database::insert('INSERT INTO route_template_steps (template_id, step_no, stage_type, parallel, user_id) VALUES (?,?,?,?,?)', [$tid, 1, 'approve', 1, $pid]);
        Database::insert('INSERT INTO route_template_steps (template_id, step_no, stage_type, parallel, user_id) VALUES (?,?,?,?,?)', [$tid, 1, 'approve', 1, $mid]);
        Database::insert('INSERT INTO route_template_steps (template_id, step_no, stage_type, parallel, user_id) VALUES (?,?,?,?,?)', [$tid, 2, 'sign', 0, $aid]);
        Database::insert('INSERT INTO route_template_steps (template_id, step_no, stage_type, parallel, user_id) VALUES (?,?,?,?,?)', [$tid, 3, 'ack', 1, $pid]);
        echo "  создан демо-шаблон (параллельное согласование → подпись → ознакомление)\n";
    }
}

echo "Визы: промпт и менеджер:\n";
if (!Database::scalar("SELECT skey FROM settings WHERE skey='visa_prompt'")) {
    Database::insert('INSERT INTO settings (skey, sval) VALUES (?,?)',
        ['visa_prompt', (string) file_get_contents(__DIR__ . '/visa_prompt_default.txt')]);
    echo "  промпт подстановки адресов записан в настройки\n";
}
// Роли разделены: менеджер анкет (manager) — квота; менеджер виз (vizman) — загрузка/распределение виз.
Database::run("UPDATE users SET is_visa_manager=0 WHERE login='manager'");
Database::run("UPDATE users SET is_visa_manager=1, does_anketas=0, does_operations=0 WHERE login='vizman'");
$vizId = (int) Database::scalar("SELECT id FROM users WHERE login='vizman'");
if ($vizId && !Database::scalar("SELECT 1 FROM user_projects WHERE user_id=? AND project_code='visa'", [$vizId])) {
    Database::insert('INSERT INTO user_projects (user_id, project_code) VALUES (?,?)', [$vizId, 'visa']);
}

// Демо-НАБОР ролей (seed создаёт пользователей после migrate, поэтому роли назначаем явно).
$demoRoles = [
    'control'  => ['controller'],
    'manager'  => ['anketa_manager', 'deputy_director'],   // менеджер анкет + курирующий зам (для служебок)
    'vizman'   => ['visa_manager'],
    'petrov'   => ['anketa_worker', 'piecework_worker', 'dept_head'],
    'sidorova' => ['piecework_worker', 'timekeeper'],
    'hr'       => ['hr'],
    'buh'      => ['accountant', 'director'],               // бухгалтерия + директор (демо-утверждающий)
];
foreach ($demoRoles as $login => $slugs) {
    $uid = (int) Database::scalar('SELECT id FROM users WHERE login = ?', [$login]);
    if (!$uid) { continue; }
    foreach ($slugs as $slug) {
        if (!Database::scalar('SELECT 1 FROM user_roles WHERE user_id=? AND role_slug=?', [$uid, $slug])) {
            Database::insert('INSERT INTO user_roles (user_id, role_slug) VALUES (?,?)', [$uid, $slug]);
        }
    }
    \App\Controllers\OrgController::syncLegacyFlags($uid);
}
// Куратор-зам отдела проверки анкет — manager (маршрут служебки: petrov → manager → buh/директор).
$mgrId = (int) Database::scalar("SELECT id FROM users WHERE login='manager'");
if ($mgrId) { Database::run("UPDATE departments SET curator_id=? WHERE name='Отдел проверки анкет'", [$mgrId]); }
echo "  демо-роли назначены (набор ролей у сотрудников)\n";

echo "Чат:\n";
if (!Database::scalar("SELECT id FROM conversations WHERE type='group' AND title='Общий чат'")) {
    $adminId = (int) Database::scalar("SELECT id FROM users WHERE login='admin'");
    $convId = Database::insert("INSERT INTO conversations (type, title, created_by) VALUES ('group', 'Общий чат', ?)", [$adminId]);
    foreach (Database::all('SELECT id FROM users') as $u) {
        Database::insert('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?,?)', [$convId, (int) $u['id']]);
    }
    Database::insert('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)',
        [$convId, $adminId, 'Добро пожаловать в общий чат! Здесь публикуются объявления.']);
    echo "  создан групповой «Общий чат» со всеми участниками\n";
}

echo "Готово.\n";
