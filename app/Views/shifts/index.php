<h1>Сменный график (2/2 — колл-центр)</h1>
<p class="muted" style="margin-top:0">У сотрудников на графике 2/2 «оклад» — это <strong>ставка за час</strong>.
    Здесь ведётся плановый график (часы/день, в т.ч. ночные) и фактический табель. Зарплата считается по этим часам:
    прогноз — по плану, итог — по факту.</p>

<section class="panel">
    <form method="get" action="/shifts" class="form-inline" style="margin-bottom:10px">
        <label>Месяц<input type="month" name="month" value="<?= e($month) ?>"></label>
        <button class="btn btn-primary">Показать</button>
        <a class="btn" href="/shifts/export?month=<?= e($month) ?>&range=full">⇩ Excel (часы)</a>
    </form>
    <?php if (!empty($grafikDepts)): ?>
    <form method="get" action="/shifts/grafik" class="form-inline" style="margin-bottom:10px" target="_blank">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <label>График сменности (печать), отдел
            <select name="dept">
                <?php foreach ($grafikDepts as $gd): ?><option value="<?= (int)$gd['id'] ?>"><?= e($gd['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button class="btn">📄 Открыть график (печать/PDF)</button>
        <span class="muted">официальный «График сменности» на месяц: Р — рабочий, Р/Н — с ночными (дн/ночь), О — отпуск</span>
    </form>
    <?php endif; ?>

    <?php if (!$emps): ?>
        <p class="muted">Нет активных сотрудников на графике 2/2. Поставьте в карточке сотрудника режим «2/2 Call-центр».</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Отдел</th><th>Сотрудник</th><th class="num">Ставка, ₽/ч</th><th class="num">План, ч</th><th class="num">Факт, ч</th><th>График</th></tr></thead>
        <tbody>
        <?php foreach ($emps as $e): ?>
            <tr>
                <td class="muted"><?= e($e['dept_name'] ?? '—') ?></td>
                <td><?= e($e['full_name']) ?> <span class="muted"><?= e($e['position']) ?></span></td>
                <td class="num"><?= money($e['oklad']) ?></td>
                <td class="num"><?= rtrim(rtrim(number_format((float)$e['plan'], 2, '.', ' '), '0'), '.') ?></td>
                <td class="num"><?= rtrim(rtrim(number_format((float)$e['fact'], 2, '.', ' '), '0'), '.') ?></td>
                <td>
                    <?php if ($canEdit): ?>
                        <a class="btn btn-mini" href="/shifts/edit?employee=<?= (int)$e['id'] ?>&month=<?= e($month) ?>&range=full&mode=plan">✎ План</a>
                        <a class="btn btn-mini" href="/shifts/edit?employee=<?= (int)$e['id'] ?>&month=<?= e($month) ?>&range=full&mode=fact">✎ Факт</a>
                    <?php else: ?>
                        <span class="muted">только просмотр</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
