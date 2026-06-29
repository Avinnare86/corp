<?php
$money = fn($v) => number_format((float) $v, 2, ',', ' ');
$val = fn($k, $d = '') => $t[$k] ?? $d;
$tid = $t ? (int) $t['id'] : 0;
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/trips">← к списку командировок</a></p>

<?php if ($t && $t['status'] === 'revision' && $t['reject_reason']): ?>
    <div class="panel" style="border-left:4px solid #b00020"><strong>Возвращено на доработку:</strong> <?= e($t['reject_reason']) ?></div>
<?php endif; ?>

<section class="panel">
    <h2 style="margin-top:0">Основные сведения</h2>
    <form method="post" action="/trips" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if ($tid): ?><input type="hidden" name="id" value="<?= $tid ?>"><?php endif; ?>
        <label>Командируемый
            <select name="employee_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>" <?= (int) $val('employee_id') === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Источник финансирования
            <select name="source_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($sources as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $val('source_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="grow">Город / место назначения<input type="text" name="destination" value="<?= e($val('destination')) ?>" maxlength="200" required></label>
        <label class="grow">Мероприятие<input type="text" name="event" value="<?= e($val('event')) ?>" maxlength="300" placeholder="Форум, совещание…"></label>
        <label>С<input type="date" name="date_from" value="<?= e($val('date_from')) ?>" required></label>
        <label>По<input type="date" name="date_to" value="<?= e($val('date_to')) ?>" required></label>
        <label class="grow" style="flex-basis:100%">Цель / задание<textarea name="purpose" rows="2"><?= e($val('purpose')) ?></textarea></label>
        <label>Проживание, ₽ (план)<input type="text" name="lodging_sum" value="<?= $tid ? rtrim(rtrim(number_format((float) $val('lodging_sum', 0), 2, '.', ''), '0'), '.') : '' ?>" placeholder="0"></label>
        <label>Проезд/билеты, ₽ (план)<input type="text" name="travel_sum" value="<?= $tid ? rtrim(rtrim(number_format((float) $val('travel_sum', 0), 2, '.', ''), '0'), '.') : '' ?>" placeholder="0"></label>
        <button class="btn btn-primary"><?= $tid ? 'Сохранить' : 'Создать черновик' ?></button>
    </form>
    <?php if (!$tid): ?><p class="muted" style="margin:8px 0 0">После создания черновика добавьте сегменты пребывания (для суточных), доп.расходы и вложения-подтверждения.</p><?php endif; ?>
</section>

<?php if ($tid): ?>
<section class="panel">
    <h2 style="margin-top:0">Сегменты пребывания (для суточных)</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Период</th><th>Место</th><th class="num">Дней</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($segments as $s): ?>
            <tr>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($s['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($s['end_date']))) ?></td>
                <td data-label="Место"><?= e($locLabels[$s['location']] ?? $s['location']) ?></td>
                <td data-label="Дней" class="num"><?= \App\Services\TripService::calDays($s['start_date'], $s['end_date']) ?></td>
                <td><form method="post" action="/trips/<?= $tid ?>/segment/<?= (int) $s['id'] ?>/delete"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$segments): ?><tr><td colspan="4" class="muted">Сегменты не заданы. Добавьте хотя бы один, покрывающий весь период.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="/trips/<?= $tid ?>/segment" class="grid-form" style="margin-top:8px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>С<input type="date" name="start_date" required></label>
        <label>По<input type="date" name="end_date" required></label>
        <label>Место<select name="location"><?php foreach ($locLabels as $lk => $lv): ?><option value="<?= e($lk) ?>"><?= e($lv) ?></option><?php endforeach; ?></select></label>
        <button class="btn">Добавить сегмент</button>
    </form>
</section>

<section class="panel">
    <h2 style="margin-top:0">Дополнительные расходы</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Вид</th><th class="num">Сумма, ₽</th><th>Примечание</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($extras as $ex): ?>
            <tr>
                <td data-label="Вид"><?= e($ex['kind_name']) ?></td>
                <td data-label="Сумма" class="num"><?= $money($ex['amount']) ?></td>
                <td data-label="Примечание" class="muted"><?= e($ex['note'] ?? '') ?></td>
                <td><form method="post" action="/trips/<?= $tid ?>/extra/<?= (int) $ex['id'] ?>/delete"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$extras): ?><tr><td colspan="4" class="muted">Доп.расходов нет (оргвзнос и т.п.).</td></tr><?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="/trips/<?= $tid ?>/extra" class="grid-form" style="margin-top:8px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Вид<select name="kind_id" required><option value="">—</option><?php foreach ($kinds as $k): ?><option value="<?= (int) $k['id'] ?>"><?= e($k['name']) ?></option><?php endforeach; ?></select></label>
        <label>Сумма, ₽<input type="text" name="amount" placeholder="0" required></label>
        <label class="grow">Примечание<input type="text" name="note" maxlength="300"></label>
        <button class="btn">Добавить расход</button>
    </form>
</section>

<section class="panel">
    <h2 style="margin-top:0">Вложения-подтверждения</h2>
    <p class="muted" style="margin-top:0">К проживанию и проезду обязательно приложить документ, подтверждающий стоимость (для любого источника, включая целевую субсидию).</p>
    <table class="table tbl-cards">
        <thead><tr><th>Тип</th><th>Файл</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($attachments as $a): ?>
            <tr>
                <td data-label="Тип"><?= e($attKinds[$a['kind']] ?? $a['kind']) ?></td>
                <td data-label="Файл"><a href="/trips/<?= $tid ?>/attachment/<?= (int) $a['id'] ?>"><?= e($a['orig_name']) ?></a></td>
                <td><form method="post" action="/trips/<?= $tid ?>/attachment/<?= (int) $a['id'] ?>/delete"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$attachments): ?><tr><td colspan="3" class="muted">Вложений нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="/trips/<?= $tid ?>/upload" class="grid-form" enctype="multipart/form-data" style="margin-top:8px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Тип<select name="kind"><?php foreach ($attKinds as $kk => $kv): ?><option value="<?= e($kk) ?>"><?= e($kv) ?></option><?php endforeach; ?></select></label>
        <label class="grow">Файл (≤20 МБ)<input type="file" name="file" required></label>
        <button class="btn">Загрузить</button>
    </form>
</section>

<section class="panel">
    <h2 style="margin-top:0">Смета</h2>
    <table class="table">
        <tbody>
            <tr><td>Суточные (РФ <?= (int) $estimate['days_rf'] ?> дн. + зарубеж <?= (int) $estimate['days_abroad'] ?> дн.)</td><td class="num"><?= $money($estimate['per_diem']) ?></td></tr>
            <tr><td>Проживание</td><td class="num"><?= $money($estimate['lodging']) ?></td></tr>
            <tr><td>Проезд/билеты</td><td class="num"><?= $money($estimate['travel']) ?></td></tr>
            <tr><td>Доп.расходы</td><td class="num"><?= $money($estimate['extras']) ?></td></tr>
            <tr style="font-weight:700"><td>Итого смета</td><td class="num"><?= $money($estimate['total']) ?> ₽</td></tr>
        </tbody>
    </table>
    <?php $bsrc = null; foreach ($budget as $b) { if ((int) $b['id'] === (int) $t['source_id']) { $bsrc = $b; break; } } ?>
    <?php if ($bsrc && $bsrc['budget'] > 0): ?>
        <p class="muted">Бюджет командировок по источнику «<?= e($bsrc['name']) ?>»: доступно <strong><?= $money($bsrc['available']) ?> ₽</strong> из <?= $money($bsrc['budget']) ?> ₽.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 style="margin-top:0">Подать на согласование</h2>
    <?php if ($issues): ?>
        <div style="border-left:4px solid #b00020;padding-left:10px;margin-bottom:10px">
            <strong>Перед подачей устраните:</strong>
            <ul style="margin:6px 0"><?php foreach ($issues as $iss): ?><li><?= e($iss) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <form method="post" action="/trips/<?= $tid ?>/submit" class="grid-form" onsubmit="return confirm('Подписать и подать заявку директору?')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Вид подписи<select name="sign_type"><?php foreach ($signTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label class="grow">Пароль<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" <?= $issues ? 'disabled' : '' ?>>Подписать и подать</button>
    </form>
    <div style="margin-top:10px;border-top:1px solid var(--line);padding-top:10px">
        <form method="post" action="/trips/<?= $tid ?>/delete" onsubmit="return confirm('Удалить черновик заявки?')">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-danger">Удалить черновик</button>
        </form>
    </div>
</section>
<?php endif; ?>
