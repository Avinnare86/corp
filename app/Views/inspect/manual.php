<h1><?= e($title) ?></h1>
<p><a href="/inspect">← к списку выборок</a></p>
<p class="muted" style="margin-top:0">Выберите проверенные анкеты для контроля <strong>вне привязки к дате</strong>. После создания выборка отрабатывается как обычная: вердикт контролёра, штраф за ошибку, повторная проверка браковки.</p>

<form method="get" action="/inspect/manual" class="panel flt" style="margin-bottom:14px">
    <input type="hidden" name="applied" value="1">
    <label>С<br><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>По<br><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label>Специалист<br>
        <select name="emp">
            <option value="0">Все</option>
            <?php foreach ($employees as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (int) $emp === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Страна<br>
        <select name="country">
            <option value="">Все</option>
            <?php foreach ($countries as $c): ?>
                <option value="<?= e($c['code']) ?>" <?= $c['code'] === $country ? 'selected' : '' ?>><?= e($c['name'] ?: $c['code']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="chk" style="align-self:flex-end"><input type="checkbox" name="x_listed" value="1" <?= !empty($exclListed) ? 'checked' : '' ?>> без включённых в другие списки на контроль</label>
    <label class="chk" style="align-self:flex-end"><input type="checkbox" name="x_inspected" value="1" <?= !empty($exclInspected) ? 'checked' : '' ?>> без уже проверенных (с вердиктом)</label>
    <button class="btn primary" type="submit" style="align-self:flex-end">Показать</button>
</form>

<form method="post" action="/inspect/manual" class="panel flt" style="margin-bottom:14px;align-items:flex-end"
      onsubmit="return confirm('Сформировать выборку из случайных N% анкет по текущему фильтру?')">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="percent">
    <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
    <input type="hidden" name="emp" value="<?= (int) $emp ?>"><input type="hidden" name="country" value="<?= e($country) ?>">
    <?php if (!empty($exclListed)): ?><input type="hidden" name="x_listed" value="1"><?php endif; ?>
    <?php if (!empty($exclInspected)): ?><input type="hidden" name="x_inspected" value="1"><?php endif; ?>
    <label>Процент от выборки<br><input type="number" name="pct" value="10" min="1" max="100" style="width:90px"> %</label>
    <label class="grow">Название<br><input type="text" name="title" placeholder="напр. Случайный контроль 10%" style="width:100%;box-sizing:border-box"></label>
    <button class="btn primary" type="submit">Сформировать N% по фильтру (<?= count($cands) ?> найдено)</button>
</form>

<form method="post" action="/inspect/manual">
    <?= csrf_field() ?>
    <div class="panel flt" style="margin-bottom:12px">
        <label style="flex:1;min-width:200px">Название выборки<br><input type="text" name="title" placeholder="напр. Доп. контроль Турция" style="width:100%;box-sizing:border-box"></label>
        <label class="chk-all" style="align-self:center"><input type="checkbox" id="all"> Отметить все</label>
        <button class="btn primary" type="submit" id="mkBtn" disabled>Сформировать из выбранных (<span id="cnt">0</span>)</button>
    </div>
    <table class="table tbl-cards">
        <thead>
            <tr>
                <th style="width:32px"></th>
                <th>Специалист</th><th>Рег. номер</th><th>Страна</th><th>Дата проверки</th><th>Контроль</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$cands): ?>
                <tr><td colspan="6" class="muted" style="text-align:center;padding:16px">Нет проверенных анкет по фильтру. Измените период/специалиста/страну.</td></tr>
            <?php endif; ?>
            <?php foreach ($cands as $a): $done = (int) $a['inspected'] > 0; ?>
                <tr<?= $done ? ' class="muted"' : '' ?>>
                    <td data-label="Выбрать"><input type="checkbox" class="pick" name="pick[]" value="<?= (int) $a['id'] ?>"<?= $done ? ' disabled title="анкета уже проходила контроль"' : '' ?>></td>
                    <td><?= e($a['employee_name']) ?></td>
                    <td><strong><?= e($a['reg_number']) ?></strong></td>
                    <td><?= e($a['country_code']) ?></td>
                    <td><?= e($a['checked_day']) ?></td>
                    <td><?= $done ? '<span class="muted">контролировалась</span>' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($cands && count($cands) >= 500): ?>
        <p class="muted" style="font-size:.85rem">Показаны первые 500 анкет — сузьте фильтр, если нужной нет в списке.</p>
    <?php endif; ?>
</form>

<script>
(function () {
    var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.pick:not([disabled])')); };
    var cnt = document.getElementById('cnt'), btn = document.getElementById('mkBtn'), all = document.getElementById('all');
    function upd() { var n = picks().filter(function (c) { return c.checked; }).length; cnt.textContent = n; btn.disabled = n === 0; }
    document.addEventListener('change', function (e) { if (e.target && e.target.classList && e.target.classList.contains('pick')) upd(); });
    if (all) all.addEventListener('change', function () { picks().forEach(function (c) { c.checked = all.checked; }); upd(); });
    upd();
})();
</script>
