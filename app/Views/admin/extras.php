<h1>Фиксированные доплаты и подработки</h1>

<section class="panel">
    <form method="get" action="/admin/extras" class="form-inline">
        <label>Сотрудник
            <select name="employee" onchange="this.form.submit()">
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>" <?= (int)$empId===(int)$emp['id']?'selected':'' ?>><?= e($emp['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <p class="muted">Сумма за месяц начисляется пропорционально отработанному времени и покрывает оклад
        в первую очередь (как часть заработка против гарантии).</p>
</section>

<section class="panel">
    <h2>Добавить доплату сотруднику</h2>
    <form method="post" action="/admin/extras" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $empId ?>">
        <label>Название работы<input type="text" name="name" placeholder="Ведение реестра" required></label>
        <label>Сумма за месяц, ₽<input type="number" step="0.01" name="monthly_amount" value="0" required></label>
        <button class="btn btn-primary">Добавить</button>
    </form>

    <table class="table">
        <thead><tr><th>Работа</th><th class="num">Сумма/мес</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($extras as $x): ?>
            <tr>
                <td><?= e($x['name']) ?></td>
                <td class="num"><?= money($x['monthly_amount']) ?></td>
                <td><?= (int)$x['is_active'] ? '<span class="tag ok">активна</span>' : '<span class="tag off">выкл</span>' ?></td>
                <td>
                    <form method="post" action="/admin/extras/<?= (int) $x['id'] ?>/delete" onsubmit="return confirm('Удалить доплату?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-mini btn-danger">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$extras): ?>
            <tr><td colspan="4" class="muted">У сотрудника нет фикс-доплат.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
