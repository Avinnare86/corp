<h1>Администрирование</h1>

<div class="cards">
    <a class="card link" href="/admin/employees">
        <div class="card-label">Специалистов</div>
        <div class="card-value big"><?= (int) $stats['employees'] ?></div>
    </a>
    <a class="card link" href="/admin/countries">
        <div class="card-label">Стран в справочнике</div>
        <div class="card-value big"><?= (int) $stats['countries'] ?></div>
    </a>
    <a class="card link" href="/admin/errors">
        <div class="card-label">Типов ошибок</div>
        <div class="card-value big"><?= (int) $stats['errors'] ?></div>
    </a>
    <div class="card">
        <div class="card-label">Всего досье</div>
        <div class="card-value big"><?= (int) $stats['dossiers'] ?></div>
    </div>
</div>

<section class="panel">
    <h2>Разделы</h2>
    <ul class="links">
        <li><a href="/admin/employees">Сотрудники</a> — добавление/удаление, должность, объём ставки, прогноз ЗП</li>
        <li><a href="/admin/positions">Должности</a> — справочник должностей с окладами</li>
        <li><a href="/admin/countries">Справочник стран</a> — код, название, группа</li>
        <li><a href="/admin/pricing">Тарифы по группам</a> — цена за досье (5 групп)</li>
        <li><a href="/admin/operations">Операции</a> — визы по этапам и прочая сделка с ценой за штуку</li>
        <li><a href="/admin/comments">Причины доработок</a> — каталог причин и категорий</li>
        <li><a href="/audit">Журнал действий</a> — кто и что сделал</li>
        <li><a href="/admin/extras">Фикс-доплаты</a> — фиксированные суммы за месяц по сотрудникам (пропорц. времени)</li>
        <li><a href="/admin/errors">Типы ошибок</a> — снижение по каждому типу</li>
        <li><a href="/admin/timesheet">Табель</a> — норма и отработанные дни (для оклада)</li>
        <li><a href="/admin/settings">Настройки</a> — % выборки на проверку, эскалация повторов</li>
        <li><a href="/inspect">Контроль анкет</a> — формирование и проверка выборки</li>
    </ul>
</section>
