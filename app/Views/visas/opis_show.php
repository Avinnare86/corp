<?php $formed = $opis['status'] === 'formed'; ?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/visas/opis/list">← Описи</a>
    <h1 style="margin:0;font-size:1.2rem">Опись: <?= e($opis['country']) ?></h1>
    <?php if ($formed): ?><span class="tag off">сформирована</span>
    <?php else: ?><span class="tag" style="background:#27ae60;color:#fff">указание получено</span><?php endif; ?>
    <span class="muted"><?= count($rows) ?> чел.</span>
</div>

<section class="panel">
    <div class="form-inline" style="gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn btn-primary" href="/visas/opis/<?= (int)$opis['id'] ?>/docs">⬇ Документы (ОПИСЬ + ОПИСЬ-СП + ГП + список)</a>
        <span class="muted">Подписант: <strong><?= e($opis['signer_name']) ?></strong>, <?= e($opis['signer_position']) ?></span>
        <?php if ($formed): ?>
            <form method="post" action="/visas/opis/<?= (int)$opis['id'] ?>/delete" onsubmit="return confirm('Удалить опись? Анкеты вернутся в кандидаты на формирование.')" style="margin-left:auto">
                <?= csrf_field() ?><button class="btn btn-mini" style="color:#c0392b">Удалить опись</button>
            </form>
        <?php else: ?>
            <span style="margin-left:auto">Указание: <strong>№ <?= e($opis['instruction_no']) ?> от <?= e($opis['instruction_date']) ?></strong></span>
        <?php endif; ?>
    </div>
</section>

<?php if ($formed): ?>
<section class="panel">
    <h2 style="margin-top:0">Визовое указание МИД и отказы</h2>
    <p class="muted" style="margin-top:0">После ответа МИД внесите № и дату визового указания. Отметьте строки, по которым МИД <strong>отказал</strong> —
        они уйдут на доработку (повторная проверка другим специалистом). По каждой отказанной строке решите: снять ли с первоначального
        проверяющего стоимость проверки (<?= number_format($price, 2, ',', ' ') ?> ₽) или 0 (если не его вина).</p>
    <form method="post" action="/visas/opis/<?= (int)$opis['id'] ?>/instruction">
        <?= csrf_field() ?>
        <div class="grid-form" style="max-width:680px;margin-bottom:8px">
            <label>№ визового указания<input type="text" name="instruction_no"></label>
            <label>Дата указания<input type="text" name="instruction_date" placeholder="дд.мм.гггг"></label>
            <label style="grid-column:1/-1">Комментарий по отказам (необязательно)<input type="text" name="refuse_note"></label>
        </div>
        <table class="table">
            <thead><tr><th>Отказ МИД</th><th>Исх. №</th><th>Фамилия</th><th>Гражданство</th><th>Вычет с проверяющего</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><input type="checkbox" name="refused[]" value="<?= (int)$r['id'] ?>"></td>
                    <td class="mono"><?= e($r['out_no'] ?: ('#' . $r['id'])) ?></td>
                    <td><?= e(trim($r['surname_lat'] . ' ' . $r['names_lat'])) ?></td>
                    <td class="muted"><?= e($r['citizenship']) ?></td>
                    <td>
                        <select name="deduct[<?= (int)$r['id'] ?>]">
                            <option value="0">0 (не его вина)</option>
                            <option value="1">снять <?= number_format($price, 2, ',', ' ') ?> ₽</option>
                        </select>
                    </td>
                    <td>
                        <button type="submit" formaction="/visas/opis/<?= (int)$opis['id'] ?>/remove" formnovalidate
                                name="row_id" value="<?= (int)$r['id'] ?>" class="btn btn-mini"
                                onclick="return confirm('Убрать анкету из описи (вернуть в кандидаты)?')">убрать</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" onclick="return confirm('Сохранить указание? Отмеченные строки уйдут на доработку.')">Сохранить указание</button>
    </form>
</section>
<?php else: ?>
<section class="panel">
    <h2 style="margin-top:0">Состав описи (указание получено)</h2>
    <table class="table">
        <thead><tr><th>Исх. №</th><th>Фамилия</th><th>Гражданство</th><th>Паспорт</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?= e($r['out_no'] ?: ('#' . $r['id'])) ?></td>
                <td><?= e(trim($r['surname_lat'] . ' ' . $r['names_lat'])) ?></td>
                <td class="muted"><?= e($r['citizenship']) ?></td>
                <td><?= e($r['passport_no']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted">Отказанные МИД строки выведены из этой описи и переведены на доработку — см. <a href="/visas/rework">МИД: на доработке</a>.</p>
</section>
<?php endif; ?>
