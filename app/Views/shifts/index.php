<h1>Сменный график (2/2 — колл-центр)</h1>
<p class="muted" style="margin-top:0">У сотрудников на графике 2/2 «оклад» — это <strong>ставка за час</strong>.
    Здесь ведётся плановый график (часы/день, в т.ч. ночные) и фактический табель. Зарплата считается по этим часам:
    прогноз — по плану, итог — по факту.</p>

<section class="panel">
    <form method="get" action="/shifts" class="form-inline" style="margin-bottom:10px">
        <label>Месяц<input type="month" name="month" value="<?= e($month) ?>"></label>
        <button class="btn btn-primary">Показать</button>
        <a class="btn" href="/shifts/export?month=<?= e($month) ?>&range=full">⇩ Excel (весь месяц)</a>
    </form>

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
