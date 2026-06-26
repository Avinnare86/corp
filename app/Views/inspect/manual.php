<h1><?= e($title) ?></h1>
<p><a href="/inspect">← к списку выборок</a></p>
<p class="muted" style="margin-top:0">Выберите проверенные анкеты для контроля <strong>вне привязки к дате</strong>. После создания выборка отрабатывается как обычная: вердикт контролёра, штраф за ошибку, повторная проверка браковки.</p>

<form method="get" action="/inspect/manual" class="panel" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
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
    <button class="btn primary" type="submit">Показать</button>
</form>

<form method="post" action="/inspect/manual">
    <?= csrf_field() ?>
    <div class="panel" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
        <label>Название выборки<br><input type="text" name="title" placeholder="напр. Доп. контроль Турция" style="min-width:280px"></label>
        <button class="btn primary" type="submit" id="mkBtn" disabled>Сформировать выборку из выбранных (<span id="cnt">0</span>)</button>
    </div>
    <table class="tbl-wide">
        <thead>
            <tr>
                <th style="width:32px"><input type="checkbox" id="all" title="выбрать все"></th>
                <th>Специалист</th><th>Рег. номер</th><th>Страна</th><th>Дата проверки</th><th>Контроль</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$cands): ?>
                <tr><td colspan="6" class="muted" style="text-align:center;padding:16px">Нет проверенных анкет по фильтру. Измените период/специалиста/страну.</td></tr>
            <?php endif; ?>
            <?php foreach ($cands as $a): ?>
                <?php $done = (int) $a['inspected'] > 0; ?>
                <tr<?= $done ? ' class="muted"' : '' ?>>
                    <td><input type="checkbox" class="pick" name="pick[]" value="<?= (int) $a['id'] ?>"<?= $done ? ' disabled title="анкета уже проходила контроль"' : '' ?>></td>
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
    var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.pick')); };
    var cnt = document.getElementById('cnt'), btn = document.getElementById('mkBtn'), all = document.getElementById('all');
    function upd() { var n = picks().filter(function (c) { return c.checked; }).length; cnt.textContent = n; btn.disabled = n === 0; }
    document.addEventListener('change', function (e) { if (e.target && e.target.classList && e.target.classList.contains('pick')) upd(); });
    if (all) all.addEventListener('change', function () { picks().forEach(function (c) { c.checked = all.checked; }); upd(); });
    upd();
})();
</script>
