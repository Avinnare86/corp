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

    <?php $pendingCnt = count($pending ?? []); ?>
    <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
        <form method="post" action="/inspect/generate-all" style="margin:0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-block" <?= $pendingCnt ? '' : 'disabled' ?>>
                Сформировать выборку по всем непроверенным датам<?= $pendingCnt ? ' (' . $pendingCnt . ')' : '' ?>
            </button>
        </form>
        <?php if ($pendingCnt): ?>
            <p class="muted" style="margin:8px 0 4px">Даты с проверенными анкетами, по которым выборка ещё не сформирована:</p>
            <table class="table" style="max-width:520px">
                <thead><tr><th>Дата</th><th class="num">Проверено анкет</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr><td><?= e($p['d']) ?></td><td class="num"><?= (int) $p['cnt'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted" style="margin:8px 0 0">Все даты с проверенными анкетами уже охвачены выборкой.</p>
        <?php endif; ?>
    </div>

    <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
        <a class="btn btn-block" href="/inspect/manual">+ Ручная выборка анкет на контроль (вне даты)</a>
        <p class="muted" style="margin:6px 0 0">Выбрать конкретные проверенные анкеты вручную — отрабатываются как обычная выборка (вердикт, штраф, повторная проверка).</p>
    </div>
</section>

<section class="panel">
    <h2>Выборки</h2>
    <table class="table">
        <thead>
        <tr><th>Выборка</th><th class="num">Анкет</th><th class="num">Проверено</th><th>Статус</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td>
                    <?php if (!empty($b['is_manual'])): ?>
                        <?= e($b['title'] ?: 'Ручная выборка') ?> <span class="tag">ручная</span>
                    <?php else: ?>
                        <?= e($b['work_date']) ?>
                    <?php endif; ?>
                </td>
                <td class="num"><?= (int) $b['total'] ?></td>
                <td class="num"><?= (int) $b['done'] ?></td>
                <td><?= $b['finished_at'] ? '<span class="tag ok">завершена</span>' : '<span class="tag">в работе</span>' ?></td>
                <td><a class="btn btn-mini" href="/inspect/queue?batch=<?= (int) $b['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?>
            <tr><td colspan="5" class="muted">Выборок пока нет.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
