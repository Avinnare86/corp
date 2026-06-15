<h1>Меню контролёра</h1>

<section class="panel">
    <h2>Сформировать выборку на проверку</h2>
    <p class="muted">Случайные <?= e($percent) ?>% анкет по каждому специалисту за выбранный рабочий день
        (по умолчанию — прошлый день).</p>
    <form method="post" action="/inspect/generate" class="form-inline">
        <?= csrf_field() ?>
        <label>Рабочий день
            <input type="date" name="work_date" value="<?= e($yesterday) ?>" required>
        </label>
        <button type="submit" class="btn btn-primary">Сформировать выборку</button>
    </form>
</section>

<section class="panel">
    <h2>Выборки</h2>
    <table class="table">
        <thead>
        <tr><th>Рабочий день</th><th class="num">Анкет</th><th class="num">Проверено</th><th>Статус</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td><?= e($b['work_date']) ?></td>
                <td class="num"><?= (int) $b['total'] ?></td>
                <td class="num"><?= (int) $b['done'] ?></td>
                <td><?= $b['finished_at'] ? '<span class="tag ok">завершена</span>' : '<span class="tag">в работе</span>' ?></td>
                <td><a class="btn btn-mini" href="/inspect/queue?date=<?= urlencode($b['work_date']) ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?>
            <tr><td colspan="5" class="muted">Выборок пока нет.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
