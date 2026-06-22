<h1>Админ-панель</h1>
<p class="muted" style="margin-top:0">Навигатор по всем разделам администрирования и справочникам системы.</p>

<div class="cards">
    <a class="card link" href="/admin/employees"><div class="card-label">Сотрудников</div><div class="card-value big"><?= (int) $stats['employees'] ?></div></a>
    <a class="card link" href="/admin/countries"><div class="card-label">Стран в справочнике</div><div class="card-value big"><?= (int) $stats['countries'] ?></div></a>
    <a class="card link" href="/admin/errors"><div class="card-label">Типов ошибок</div><div class="card-value big"><?= (int) $stats['errors'] ?></div></a>
    <div class="card"><div class="card-label">Всего досье</div><div class="card-value big"><?= (int) $stats['dossiers'] ?></div></div>
</div>

<?php
$sections = [
    'Кадры и оргструктура' => [
        ['/admin/employees',   'Сотрудники',          'карточки, должность/ставка, перевод, надбавки'],
        ['/admin/org',         'Оргструктура',        'подразделения, руководители, кураторы, иерархия'],
        ['/admin/org/roles',   'Роли и доступы',      'набор ролей сотрудника, замещение'],
        ['/admin/positions',   'Должности',           'справочник должностей с окладами'],
        ['/admin/timesheet',   'Табель (норма/дни)',  'норма и отработанные дни для оклада'],
        ['/timesheet2',        'Табель (месяц/смены)','электронный табель ½ месяца, подпись ЭП'],
        ['/acting',            'Замещение и И.о./ВРИО','исполняющие обязанности на период'],
    ],
    'Квота (анкеты)' => [
        ['/manager',           'Распределение анкет', 'загрузка списков и назначение специалистам'],
        ['/admin/countries',   'Справочник стран',    'код, название, группа тарифа'],
        ['/admin/pricing',     'Тарифы по группам',   'цена за анкету по группам стран'],
        ['/admin/comments',    'Причины доработок',   'каталог причин и категорий'],
        ['/admin/errors',      'Типы ошибок',         'снижение по каждому типу'],
        ['/manager/arrival',   'Линии прибытия',      'справочники ЛП/ДЛП (План приёма)'],
        ['/inspect',           'Контроль анкет',      'формирование и проверка выборки'],
    ],
    'Визы' => [
        ['/admin/operations',  'Каталог операций',    'этапы виз и прочая сделка, цена за штуку'],
        ['/visas/manage',      'Загрузка и распределение', 'партии ходатайств, распределение'],
        ['/visas/opis/list',   'Описи / Указания',    'формирование описей и указаний МИД'],
    ],
    'Стимул и финансы' => [
        ['/budget',            'Бюджет ФОТ',          'бюджет по отделам и источникам, остаток по источникам'],
        ['/admin/grounds',     'Основания стимула',   'перечень показателей (раздел 4 Положения)'],
        ['/memos',             'Служебки о стимуле',  'назначение и подписание стимула'],
        ['/memos/reasons',     'Справочник оснований','свободные основания по отделам'],
        ['/memos/summary',     'Сводная по стимулу',  'стимул по месяцам и отделам'],
    ],
    'Документы (СЭД)' => [
        ['/docs',              'Документы',           'входящие/исходящие/внутренние, согласование'],
        ['/nomenclature',      'Номенклатура дел',    'дела, списание в дело, архив'],
        ['/appeals',           'Обращения граждан',   'регистрация и контроль (59-ФЗ)'],
    ],
    'Система' => [
        ['/admin/settings',    'Настройки',           'выборка, штрафы, OpenRouter, SMTP, подписанты'],
        ['/admin/data',        'Управление данными',  'удаление/откат записей по сущностям'],
        ['/audit',             'Журнал действий',     'кто и что сделал'],
    ],
];
?>
<div class="role-cols" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
<?php foreach ($sections as $title => $items): ?>
    <section class="panel" style="margin:0">
        <h2 style="margin-top:0;font-size:1.05rem"><?= e($title) ?></h2>
        <ul class="links" style="margin:0">
            <?php foreach ($items as [$href, $label, $desc]): ?>
                <li><a href="<?= e($href) ?>"><?= e($label) ?></a> <span class="muted" style="font-size:.82rem">— <?= e($desc) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
</div>
