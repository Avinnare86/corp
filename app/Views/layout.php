<?php
use App\Core\Auth;
use App\Services\NotificationService;
use App\Controllers\ChatController;

$role = Auth::role();
$uid = Auth::id();
$unread = $uid ? NotificationService::unreadCount((int) $uid) : 0;
$chatUnread = $uid ? ChatController::unreadCount((int) $uid) : 0;
$docsInbox = $uid ? \App\Controllers\DocumentController::inboxCount((int) $uid) : 0;
$ordersInbox = $uid ? \App\Controllers\OrderController::inboxCount((int) $uid) : 0;
$vacInbox = $uid ? \App\Controllers\VacationController::inboxCount((int) $uid) : 0;
// ---- меню по НАБОРУ ролей; группы-проекты управляют видимостью разделов ----
$can = fn(string ...$s) => Auth::effectiveHas(...$s);  // меню учитывает режим И.о. (роли замещаемого)
$isAdmin = $role === 'admin';
$menu = [];
if ($uid) {
    // менеджмент кадровых разделов: админ или менеджеры проектов
    $isMgr = $can('anketa_manager', 'visa_manager');
    $isTimekeeper = $isAdmin || $can('timekeeper', 'dept_head') || !empty($authUser['timekeeper_dept_id'])
        || \App\Core\Database::scalar('SELECT 1 FROM departments WHERE head_id = ?', [$uid]);
    $isHrAcc = $isAdmin || $can('hr', 'accountant');
    // менеджеры проектов (админ имеет все роли автоматически)
    $isHrMgr   = $can('hr_manager', 'hr');   // менеджер проекта кадры или кадровик
    $isFinMgr  = $can('finance_manager');    // менеджер проекта финансы
    $isDocsMgr = $can('docs_manager');       // менеджер проекта документы

    $menu[''][] = ['/', 'Главная', 0];

    // Группы модулей открываются ПО РОЛЯМ (проекты упразднены).
    if ($can('anketa_worker', 'anketa_manager', 'controller')) {
        $g = [];
        if ($can('anketa_worker')) { $g[] = ['/dossiers', 'Проверка досье', 0]; $g[] = ['/norm', 'Мой норматив', 0]; }
        if ($can('controller'))    { $g[] = ['/inspect', 'Контроль анкет', 0]; }
        if ($can('anketa_manager')) { $g[] = ['/manager', 'Распределение', 0]; $g[] = ['/manager/report', 'Отчёт', 0]; $g[] = ['/norm/report', 'Норматив', 0]; }
        $g[] = ['/rating', 'Рейтинг', 0];
        // настройки менеджера проекта по квоте
        if ($can('anketa_manager')) {
            $g[] = ['/admin/countries', 'Страны', 0];
            $g[] = ['/admin/comments', 'Причины', 0];
            $g[] = ['/admin/errors', 'Ошибки', 0];
            $g[] = ['/manager/arrival', 'Линии прибытия', 0];
        }
        if ($g) { $menu['Квота'] = $g; }
    }
    if ($can('visa_worker', 'visa_manager', 'piecework_worker', 'visa_mid')) {
        $g = [];
        $visaInbox = \App\Controllers\VisaController::inboxCount((int) $uid);
        if ($can('visa_worker')) {
            $g[] = ['/visas', 'Проверка виз', $visaInbox];
            $g[] = ['/visas/done', 'Отработанное', 0];
        }
        if ($can('visa_manager')) {
            $g[] = ['/visas/manage', 'Загрузка и распределение', 0];
            $g[] = ['/visas/timesheet', 'Учёт и акцепт работы', 0];
            $g[] = ['/visas/opis', 'Формирование описей', 0];
            $g[] = ['/visas/opis/list', 'Описи / Указания', 0];
            $g[] = ['/visas/rework', 'МИД: на доработке', \App\Controllers\VisaOpisController::reworkCount()];
            $g[] = ['/visas/report', 'Отчёт', 0];
            $g[] = ['/visas/report/status', 'Сводный отчёт', 0];
            $g[] = ['/visas/report/period', 'Динамика за период', 0];
        }
        // Учётчик виз / передача в МИД — описи и указания (без дублирования с виз-менеджером)
        if ($can('visa_mid') && !$can('visa_manager')) {
            $g[] = ['/visas/opis', 'Формирование описей', 0];
            $g[] = ['/visas/opis/list', 'Описи / Указания', 0];
        }
        if ($can('visa_worker', 'visa_manager')) { $g[] = ['/visas/rating', 'Рейтинг', 0]; }
        if ($can('piecework_worker', 'visa_worker')) { $g[] = ['/piecework', 'Визы/операции (учёт)', 0]; }
        if ($can('visa_manager'))     { $g[] = ['/admin/operations', 'Каталог операций', 0]; }
        if ($g) { $menu['Визы'] = $g; }
    }
    // Кадры — по ролям. Бухгалтерия/директор/руководители тоже видят «Сотрудники» (для надбавок/просмотра).
    $canSeeStaff = $isHrMgr || $can('accountant', 'director', 'deputy_director');
    if ($isHrMgr || $isTimekeeper || $canSeeStaff || $can('dept_head')) {
        $g = [];
        if ($isTimekeeper || $isHrMgr || $can('dept_head', 'deputy_director', 'director')) { $g[] = ['/vacations', 'Отпуска', $vacInbox]; }
        if ($isTimekeeper) { $g[] = ['/timesheet2', 'Эл. табель', 0]; }
        if (\App\Controllers\ShiftController::canSee((int) $uid)) { $g[] = ['/shifts', 'Сменный график (2/2)', 0]; $g[] = ['/timesheet2?kind=shift', 'Табель 0504421 (2/2)', 0]; }
        if ($isHrAcc) { $g[] = ['/timesheet2/coverage', 'Покрытие табелями', 0]; }
        if ($canSeeStaff) { $g[] = ['/admin/employees', 'Сотрудники', 0]; }
        // управление оргструктурой/должностями/табелем — только менеджер проекта кадры или кадровик
        if ($isHrMgr) {
            $g[] = ['/admin/org', 'Оргструктура', 0];
            $g[] = ['/admin/positions', 'Должности', 0];
            $g[] = ['/admin/timesheet', 'Табель (месяц)', 0];
        }
        if ($g) { $menu['Кадры'] = $g; }
    }
    // Служебки о стимуле — начальники/замы/директор/бухгалтерия (вне привязки к проектам)
    if ($can('dept_head', 'deputy_director', 'director', 'accountant', 'finance_manager')) {
        $memoInbox = \App\Controllers\StimulusController::inboxCount((int) $uid);
        $g = [['/memos', 'Служебки о стимуле', $memoInbox], ['/memos/summary', 'Сводная по стимулу', 0]];
        if ($can('dept_head', 'deputy_director', 'director')) { $g[] = ['/memos/carry', 'Перенос с прошлого месяца', 0]; }
        if ($can('director', 'deputy_director')) { $g[] = ['/memos/direct/new', 'Назначить напрямую', 0]; }
        $g[] = ['/memos/print-report', 'Служебки на печать', 0];
        if ($can('accountant', 'director', 'finance_manager')) { $g[] = ['/memos/coverage', 'Покрытие (бухгалтерия)', 0]; }
        if ($can('dept_head', 'deputy_director', 'director')) { $g[] = ['/memos/reasons', 'Основания (справочник)', 0]; }
        $menu['Стимул'] = $g;
    }
    // Финансы — только менеджер проекта финансы
    if ($isFinMgr) {
        $menu['Финансы'] = [
            ['/budget', 'Бюджет ФОТ', 0],
            ['/admin/grounds', 'Основания стимула', 0],
            ['/admin/pricing', 'Тарифы', 0],
            ['/admin/extras', 'Доплаты', 0],
        ];
    }
    // Документы — доступны всем пользователям; поручения входят в этот блок
    $gd = [
        ['/docs', 'Документы', $docsInbox],
        ['/orders', 'Поручения', $ordersInbox],
    ];
    $appealsInbox = \App\Controllers\AppealController::inboxCount((int) $uid);
    if ($isDocsMgr || $appealsInbox) { $gd[] = ['/appeals', 'Обращения граждан', $appealsInbox]; }
    if ($can('clerk') || $isDocsMgr) {   // делопроизводитель / регистратор
        $gd[] = ['/docs/register/new', 'Регистрация', 0];
        $gd[] = ['/docs/journals', 'Журналы регистрации', 0];
    }
    if ($can('doc_controller')) {        // контролёр документов
        $gd[] = ['/docs?folder=control', 'Контроль документов', 0];
    }
    if ($isDocsMgr) { // менеджер проекта документы
        $gd[] = ['/acting', 'Замещение и И.о./ВРИО', 0];
        $gd[] = ['/nomenclature', 'Номенклатура дел', 0];
        $gd[] = ['/nomenclature/archive', 'Архив дел', 0];
    }
    $menu['Документы'] = $gd;
    // Админ — только системный администратор (журнал действий — тоже только админ)
    if ($isAdmin) {
        $menu['Админ'] = [
            ['/admin', 'Админ-панель', 0],
            ['/admin/settings', 'Настройки', 0],
            ['/admin/data', 'Управление данными', 0],
            ['/audit', 'Журнал', 0],
        ];
    }
    // всегда (Моя ЭП — в профиле, рядом с ФИО)
    $menu[''][] = ['/chat', 'Чат', $chatUnread];
    $menu[''][] = ['/notifications', 'Уведомления', $unread];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> — <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;600;700;800&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css?v=3">
    <script defer src="/assets/app.js?v=3"></script>
</head>
<?php $mobView = $_COOKIE['mobview'] ?? ''; ?>
<body class="<?= $mobView === 'full' ? 'force-desktop' : '' ?>">
<header class="topbar">
    <a class="brand" href="/">
        <span class="mark">
            <svg width="24" height="13" viewBox="0 0 52 26" fill="none"><path d="M4 18 C 11 4, 19 4, 26 13 C 33 22, 41 22, 48 8" stroke="#fff" stroke-width="6" stroke-linecap="round"/></svg>
        </span>
        <span class="brand-text"><?= e($appName) ?></span>
    </a>
    <?php if (Auth::check()): ?>
    <nav class="nav">
        <div class="nav-head"><span>Меню</span><button type="button" class="nav-close" onclick="navBurger()" aria-label="Закрыть">✕</button></div>
        <?php
        $badge = fn($n) => $n ? ' <span class="badge">' . (int) $n . '</span>' : '';
        // одиночные пункты (группа '')
        foreach ($menu[''] ?? [] as [$href, $label, $cnt]) {
            echo '<a href="' . e($href) . '">' . e($label) . $badge($cnt) . '</a>';
        }
        // группы-проекты
        foreach ($menu as $group => $items) {
            if ($group === '' || !$items) { continue; }
            $sum = array_sum(array_column($items, 2));
            echo '<div class="nav-group"><button type="button" class="nav-gbtn" onclick="navToggle(this)">'
                . e($group) . $badge($sum) . ' <span class="caret">▾</span></button><div class="nav-drop">';
            foreach ($items as [$href, $label, $cnt]) {
                echo '<a href="' . e($href) . '">' . e($label) . $badge($cnt) . '</a>';
            }
            echo '</div></div>';
        }
        ?>
        <div class="nav-tablemode"><button type="button" id="tblViewBtn" onclick="toggleTableView()">📋 Показать полные таблицы</button></div>
    </nav>
    <button type="button" class="nav-burger" onclick="navBurger()" aria-label="Меню">☰</button>
    <script>
    function navToggle(btn){
        var g = btn.parentElement, was = g.classList.contains('open');
        document.querySelectorAll('.nav-group.open').forEach(function(x){ x.classList.remove('open'); });
        if (!was) g.classList.add('open');
    }
    document.addEventListener('click', function(e){
        if (!e.target.closest('.nav-group')) document.querySelectorAll('.nav-group.open').forEach(function(x){ x.classList.remove('open'); });
    });
    </script>
    <div class="user">
        <?php if (!empty($actingCtx['options'])): ?>
        <form method="post" action="/acting/switch" style="display:inline;margin:0">
            <input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>">
            <select name="to" onchange="this.form.submit()" title="Режим работы (сам / И.о.)" style="max-width:180px">
                <option value="">— работаю как сам —</option>
                <?php foreach ($actingCtx['options'] as $o): ?>
                    <option value="<?= (int)$o['absent_id'] ?>" <?= (int)($actingCtx['current'] ?? 0)===(int)$o['absent_id']?'selected':'' ?>><?= $o['kind']==='vrio'?'ВРИО':'И.о.' ?>: <?= e($o['absent_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <span><?= e($authUser['full_name'] ?? '') ?></span>
        <a href="/certs" title="Моя электронная подпись">Моя ЭП</a>
        <a href="/acting" title="Замещение и И.о./ВРИО">Замещение</a>
        <a href="/password/change" title="Сменить пароль">Пароль</a>
        <a class="btn-logout" href="/logout">Выход</a>
    </div>
    <?php endif; ?>
</header>

<main class="container">
    <?php if (!empty($impostor['active'])): ?>
    <div class="flash" style="background:#7a1f1f;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <span>⚠ Вы работаете как <strong><?= e($impostor['asName']) ?></strong> (вход администратора). Все действия выполняются от его имени.</span>
        <form method="post" action="/admin/return" style="margin:0"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button class="btn btn-mini">↩ Вернуться к админу</button></form>
    </div>
    <?php endif; ?>
    <?php if (!empty($actingCtx['current'])): ?>
    <div class="flash" style="background:#1f4e7a;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <span>👤 Вы действуете как <strong>И.о. <?= e($actingCtx['currentName']) ?></strong> — подписи и действия идут с вашей ЭП с пометкой «И.о.».</span>
        <form method="post" action="/acting/switch" style="margin:0"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="to" value=""><button class="btn btn-mini">Выйти из режима И.о.</button></form>
    </div>
    <?php endif; ?>
    <?php if (!empty($flashMsg)): ?>
        <div class="flash flash-<?= e($flashMsg['type']) ?>"><?= nl2br(e($flashMsg['message'])) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<footer class="footer">
    <?= e($appName) ?> · <?= date('Y') ?><?php if (!empty($appVersion)): ?> · версия <?= e($appVersion) ?><?php endif; ?>
</footer>

<?php if (Auth::check()) { include __DIR__ . '/partials/widget.php'; } ?>
</body>
</html>
