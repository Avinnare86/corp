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
    <h2 style="margin-top:0">Визовое указание получено</h2>
    <p class="muted" style="margin-top:0">№ и дату можно отредактировать (например, при опечатке). Изменение фиксируется в журнале действий — кто и когда правил.</p>
    <form method="post" action="/visas/opis/<?= (int)$opis['id'] ?>/instruction-edit" class="form-inline" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <label>№ визового указания<input type="text" name="instruction_no" value="<?= e($opis['instruction_no']) ?>"></label>
        <label>Дата указания<input type="text" name="instruction_date" value="<?= e($opis['instruction_date']) ?>" placeholder="дд.мм.гггг"></label>
        <button class="btn btn-mini" onclick="return confirm('Сохранить изменение визового указания? Будет записано в журнал действий.')">Изменить указание</button>
        <?php if (!empty($opis['instruction_edited_at'])): ?>
            <span class="muted">последняя правка: <strong><?= e($editorName ?: '—') ?></strong>, <?= e(substr((string)$opis['instruction_edited_at'], 0, 16)) ?></span>
        <?php endif; ?>
    </form>
</section>

<section class="panel">
    <h2 style="margin-top:0">Состав описи (<?= count($rows) ?> чел.)</h2>
    <p class="muted" style="margin-top:0">Если из полученного указания нужно <strong>удалить человека</strong> (МИД отказал по уже внесённой строке и т.п.) — нажмите «в доработку».
        Это равнозначно отказу: строка уйдёт на повторную проверку другому специалисту с пометкой <em>«повторно, удалён из визового указания»</em>.
        <strong>Комментарий обязателен.</strong> По вычету с первичного проверяющего решаете вы: 0 (не его вина) или стоимость проверки.</p>
    <table class="table">
        <thead><tr><th>Исх. №</th><th>Фамилия</th><th>Гражданство</th><th>Паспорт</th><th>Удаление из указания</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?= e($r['out_no'] ?: ('#' . $r['id'])) ?></td>
                <td><?= e(trim($r['surname_lat'] . ' ' . $r['names_lat'])) ?></td>
                <td class="muted"><?= e($r['citizenship']) ?></td>
                <td><?= e($r['passport_no']) ?></td>
                <td>
                    <form method="post" action="/visas/opis/<?= (int)$opis['id'] ?>/refuse-row" class="form-inline" style="gap:6px;flex-wrap:wrap;align-items:center"
                          onsubmit="return confirm('Убрать из визового указания и отправить на доработку (повторно)?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                        <input type="text" name="comment" placeholder="причина (обязательно)" required style="max-width:220px">
                        <select name="deduct">
                            <option value="0">вычет 0</option>
                            <option value="1">снять <?= number_format($price, 2, ',', ' ') ?> ₽</option>
                        </select>
                        <button class="btn btn-mini" style="color:#c0392b">в доработку</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">В описи не осталось строк (все удалены / на доработке).</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="muted">Отказанные/удалённые строки — на доработке: <a href="/visas/rework">МИД: на доработке</a>.</p>
</section>
<?php endif; ?>
