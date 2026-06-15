<h1>Анкета <?= e($row['out_no'] ?: '#'.$row['id']) ?> — <?= e($row['surname_lat']) ?></h1>

<section class="panel">
    <p class="muted" style="margin-top:0">
        Партия: <strong><?= e($row['batch_name']) ?></strong> ·
        Специалист: <strong><?= e($row['spec_name'] ?: 'не назначен') ?></strong> ·
        Статус: <?= $row['checked_at'] ? '<span class="plus">✓ проверена ' . e(substr((string)$row['checked_at'],0,16)) . '</span>' : '<span class="minus">в работе</span>' ?>
        <?php if ((int)$row['rework_count']): ?>
            · Доработок: <strong><?= (int)$row['rework_count'] ?></strong>
            <?php if ($row['rework_note']): ?>(последнее замечание: «<?= e($row['rework_note']) ?>», <?= e(substr((string)$row['rework_at'],0,16)) ?>)<?php endif; ?>
        <?php endif; ?>
    </p>

    <form method="post" action="/visas/row/<?= (int)$row['id'] ?>/save" class="grid-form">
        <?= csrf_field() ?>
        <?php foreach ($fields as $f => $label): ?>
            <label<?= in_array($f, ['work_address','ai_address','visit_places','visa_place'], true) ? ' style="grid-column:1/-1"' : '' ?>>
                <?= e($label) ?>
                <textarea name="<?= e($f) ?>" rows="<?= in_array($f, ['work_address','ai_address'], true) ? 2 : 1 ?>"
                    <?= $f==='ai_address' ? 'class="vai"' : '' ?>><?= e($row[$f] ?? '') ?></textarea>
            </label>
        <?php endforeach; ?>
        <div style="grid-column:1/-1" class="form-inline">
            <button class="btn btn-primary">💾 Сохранить правки</button>
            <a class="btn" href="<?= $isManager ? '/visas/batch/' . (int)$row['batch_id'] . '/rows' : ($row['checked_at'] ? '/visas/done' : '/visas') ?>">← Назад</a>
        </div>
    </form>
</section>

<?php if ($row['checked_at']): ?>
<section class="panel">
    <h2>Вернуть на доработку</h2>
    <form method="post" action="/visas/row/<?= (int)$row['id'] ?>/rework" class="form-inline" style="align-items:flex-end">
        <?= csrf_field() ?>
        <label style="flex:1;min-width:280px">Замечание (что не так)
            <input type="text" name="note" placeholder="напр. неверно указан адрес места работы"></label>
        <button class="btn btn-gold" onclick="return confirm('Снять отметку «проверено» и вернуть анкету в очередь специалиста?')">↩ Вернуть на доработку</button>
    </form>
    <p class="muted">Анкета снова появится в гриде специалиста с пометкой ⚠ и замечанием. Зачёт в сделку не удваивается.</p>
</section>
<?php endif; ?>
