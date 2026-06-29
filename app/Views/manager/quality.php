<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Качество работы специалистов: ошибки, выявленные <strong>при проверке</strong> (анкеты, отправленные на доработку), и ошибки, выявленные <strong>при последующем контроле</strong>. Фильтр — по периоду и по стране (по всем сразу либо по конкретной).</p>

<form method="get" action="/manager/quality" class="panel flt" style="margin-bottom:16px">
    <label>Месяц<br>
        <select name="period">
            <option value="">Все периоды</option>
            <?php foreach ($periods as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>или с<br><input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
    <label>по<br><input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
    <label>Страна<br>
        <select name="country">
            <option value="">Все страны</option>
            <?php foreach ($countries as $c): ?>
                <option value="<?= e($c['code']) ?>" <?= $c['code'] === $country ? 'selected' : '' ?>>
                    <?= e($c['name'] ?: $c['code']) ?> (<?= e($c['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn primary" type="submit">Показать</button>
    <a class="btn" href="/manager/quality/export?period=<?= urlencode($period) ?>&country=<?= urlencode($country) ?>&from=<?= urlencode($from ?? '') ?>&to=<?= urlencode($to ?? '') ?>">⤓ Excel</a>
</form>

<p class="muted" style="margin-top:0">
    Выбрано: период — <strong><?= $period !== '' ? e($period) : 'все' ?></strong>,
    страна — <strong><?= $country !== '' ? e(($countryName ?: $country)) : 'все' ?></strong>.
</p>

<?php
// Список на контроль: ведём на экран выбора (/inspect/manual) с теми же фильтрами. Период → диапазон дат.
$cFrom = ($from ?? '') !== '' ? $from : ($period !== '' ? $period . '-01' : '');
$cTo   = ($to ?? '') !== '' ? $to : ($period !== '' ? date('Y-m-t', strtotime($period . '-01')) : '');
$canControl = \App\Core\Auth::effectiveHas('controller', 'admin', 'manager');
$ctlUrl = fn($emp) => '/inspect/manual?from=' . urlencode($cFrom) . '&to=' . urlencode($cTo)
    . '&country=' . urlencode($country) . ($emp ? '&emp=' . (int) $emp : '');
?>
<?php if ($canControl): ?>
<p style="margin:0 0 16px">
    <a class="btn primary" href="<?= e($ctlUrl(0)) ?>">📋 Сформировать список на контроль</a>
    <span class="muted" style="font-size:.85rem">по текущему фильтру; анкеты с найденными ошибками (доработка) и уже проверенные в выборку не попадают.</span>
</p>
<?php endif; ?>

<table class="table tbl-cards q-card">
    <thead>
        <tr>
            <th rowspan="2">Специалист</th>
            <th colspan="3" style="text-align:center">Проверка</th>
            <th colspan="3" style="text-align:center">Последующий контроль</th>
        </tr>
        <tr>
            <th style="text-align:right">Проверено</th>
            <th style="text-align:right">Ошибок</th>
            <th style="text-align:right">% ошибок</th>
            <th style="text-align:right">Проконтр.</th>
            <th style="text-align:right">Ошибок</th>
            <th style="text-align:right">% ошибок</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:18px">Нет проверенных анкет за выбранный период/страну.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr class="qd-row" data-uid="<?= (int) $r['uid'] ?>" style="cursor:pointer" title="Показать проверенные анкеты">
                <td><span class="qd-arrow" style="display:inline-block;width:1em;color:#6b7280">▸</span><?= e($r['full_name']) ?><?php if ($canControl): ?> <a class="btn btn-mini" style="margin-left:6px;font-weight:400" href="<?= e($ctlUrl((int) $r['uid'])) ?>" onclick="event.stopPropagation()" title="Сформировать список на контроль по этому специалисту">на&nbsp;контроль</a><?php endif; ?></td>
                <td style="text-align:right" data-label="Проверено"><?= (int) $r['checked'] ?></td>
                <td style="text-align:right" data-label="Ошибок (проверка)"><?= (int) $r['check_err'] ?></td>
                <td style="text-align:right" data-label="% ошибок (пров.)"><?= number_format((float) $r['check_pct'], 1, ',', ' ') ?>%</td>
                <td style="text-align:right" data-label="Проконтролировано"><?= (int) $r['inspected'] ?></td>
                <td style="text-align:right" data-label="Ошибок (контроль)"><?= (int) $r['ctrl_err'] ?></td>
                <td style="text-align:right" data-label="% ошибок (контр.)"><?= $r['ctrl_pct'] === null ? '—' : number_format((float) $r['ctrl_pct'], 1, ',', ' ') . '%' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
        <tfoot>
            <tr style="font-weight:600">
                <td>Итого</td>
                <td style="text-align:right" data-label="Проверено"><?= (int) $tot['checked'] ?></td>
                <td style="text-align:right" data-label="Ошибок (проверка)"><?= (int) $tot['check_err'] ?></td>
                <td style="text-align:right" data-label="% ошибок (пров.)"><?= number_format((float) $tot['check_pct'], 1, ',', ' ') ?>%</td>
                <td style="text-align:right" data-label="Проконтролировано"><?= (int) $tot['inspected'] ?></td>
                <td style="text-align:right" data-label="Ошибок (контроль)"><?= (int) $tot['ctrl_err'] ?></td>
                <td style="text-align:right" data-label="% ошибок (контр.)"><?= $tot['ctrl_pct'] === null ? '—' : number_format((float) $tot['ctrl_pct'], 1, ',', ' ') . '%' ?></td>
            </tr>
        </tfoot>
    <?php endif; ?>
</table>

<p class="muted" style="font-size:.85rem">
    «Ошибок при проверке» — число анкет, по которым специалист указал доработку (выявил недочёты).
    «% ошибок (проверка)» — доля таких анкет от проверенных.
    «Ошибок при контроле» — число анкет, в которых контролёр при выборочной проверке нашёл недочёт (учтены только проконтролированные анкеты).
    <br>Нажмите на строку специалиста, чтобы раскрыть его проверенные анкеты.
</p>

<script>
(function () {
    var f = { period: <?= json_encode($period) ?>, country: <?= json_encode($country) ?>, from: <?= json_encode($from ?? '') ?>, to: <?= json_encode($to ?? '') ?> };
    Array.prototype.forEach.call(document.querySelectorAll('.qd-row'), function (row) {
        row.addEventListener('click', function () {
            var arrow = row.querySelector('.qd-arrow');
            var next = row.nextElementSibling;
            if (next && next.classList.contains('qd-detail')) {
                var show = next.style.display === 'none';
                next.style.display = show ? '' : 'none';
                if (arrow) arrow.textContent = show ? '▾' : '▸';
                return;
            }
            var tr = document.createElement('tr');
            tr.className = 'qd-detail';
            var td = document.createElement('td');
            td.colSpan = 7; td.style.padding = '6px 10px'; td.style.background = '#f7f9fc';
            td.innerHTML = '<span class="muted">Загрузка…</span>';
            tr.appendChild(td);
            row.parentNode.insertBefore(tr, row.nextSibling);
            if (arrow) arrow.textContent = '▾';
            var qs = 'uid=' + encodeURIComponent(row.getAttribute('data-uid'))
                + '&period=' + encodeURIComponent(f.period) + '&country=' + encodeURIComponent(f.country)
                + '&from=' + encodeURIComponent(f.from) + '&to=' + encodeURIComponent(f.to);
            fetch('/manager/quality/dossiers?' + qs, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) { td.innerHTML = html; if (window.enhanceTables) window.enhanceTables(td); })
                .catch(function () { td.innerHTML = '<span class="muted">Ошибка загрузки</span>'; });
        });
    });
})();
</script>
