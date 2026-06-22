<h1>Сменный табель 0504421 (2/2) — <?= e($t['dept_name'] ?: '—') ?>
    <?= (int)$t['revision'] > 0 ? '<span class="tag">корректировочный №' . (int)$t['revision'] . '</span>' : '' ?></h1>
<p><a href="/timesheet2?month=<?= e(substr($t['period'],0,7)) ?>&half=<?= e(substr($t['period'],8)) ?>">← к списку</a>
   · период с <?= (int)substr($dates[0],8,2) ?> по <?= (int)substr(end($dates),8,2) ?> · <?= e(substr($t['period'],0,7)) ?></p>

<section class="panel">
    <p class="muted" style="margin-top:0">Табель сформирован из <a href="/shifts?month=<?= e(substr($t['period'],0,7)) ?>">сменного графика 2/2</a> по правилам формы <b>ОКУД 0504421</b> (сплошная регистрация явок/неявок: код + часы);
        день/ночь посчитаны автоматически по ночному окну, два кода в один день — дробью «Я/Н» и часы «4/8». Правьте смены в графике и жмите «Пересформировать».</p>
    <details style="margin:0 0 8px"><summary class="muted" style="cursor:pointer">Условные обозначения (полный перечень ОКУД 0504421)</summary>
        <div style="font-size:.8rem;columns:2;column-gap:24px;margin-top:6px">
            <?php foreach (\App\Controllers\TabelController::OKUD_CODES as $code => $info): ?>
                <div><b><?= e($code) ?></b> — <?= e($info[0]) ?></div>
            <?php endforeach; ?>
        </div>
    </details>
    <div class="table-scroll">
    <table class="table" style="min-width:760px;font-size:.85rem">
        <thead><tr><th>Сотрудник</th><th></th>
            <?php foreach ($dates as $d): ?><th style="text-align:center;padding:4px 2px"><?= (int)substr($d,8,2) ?></th><?php endforeach; ?>
            <th class="num">Итого</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $cells = $r['cells_arr'] ?? []; ?>
            <tr>
                <td rowspan="2"><?= e($r['full_name']) ?><br><span class="muted" style="font-size:.72rem"><?= e($r['position'] ?? '') ?></span></td>
                <td class="muted" style="font-size:.7rem">код</td>
                <?php foreach ($dates as $i => $d): ?><td style="text-align:center;font-weight:600"><?= e($cells[$i]['c'] ?? '') ?></td><?php endforeach; ?>
                <td class="num" rowspan="2"><strong><?= (int)$r['days'] ?></strong> дн.<br><span class="muted"><?= e(\App\Controllers\TabelController::fmtHours((float)$r['hours'])) ?> ч</span></td>
            </tr>
            <tr>
                <td class="muted" style="font-size:.7rem">часы</td>
                <?php foreach ($dates as $i => $d): ?><td style="text-align:center;color:#555;font-size:.78rem"><?= e($cells[$i]['h'] ?? '') ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($dates)+3 ?>" class="muted">В отделе нет сотрудников на графике 2/2.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/regenerate" style="margin-top:10px;display:inline">
        <?= csrf_field() ?>
        <button class="btn" onclick="return confirm('Пересформировать табель из текущего графика 2/2?')">↻ Пересформировать из графика</button>
    </form>
</section>

<section class="panel">
    <h2>Подписать электронной подписью</h2>
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/sign" class="grid-form">
        <?= csrf_field() ?>
        <label>Вид подписи
            <select name="sign_type">
                <?php foreach ($signTypes as $sv => $sl):
                    $hasCert = $sv === 'PEP' || array_filter($certs, fn($c) => $c['sign_type'] === $sv); ?>
                    <option value="<?= $sv ?>" <?= !$hasCert ? 'disabled' : '' ?>><?= e($sl) ?><?= !$hasCert ? ' — нет сертификата' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Пароль учётной записи (подтверждение)
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn btn-gold" onclick="return confirm('Подписать табель? После подписания изменения возможны только корректировочным табелем.')">🖋 Подписать</button>
    </form>
    <p class="muted">ПЭП выпускается системой автоматически при первом подписании. Подписанный табель отображается листом 0504421 со штампом ЭП и попадает в покрытие/расчёт.</p>
</section>
