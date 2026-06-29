<?php
$money = fn($v) => number_format((float) $v, 2, ',', ' ');
$dt = fn($s) => $s ? date('d.m.Y', strtotime($s)) : '';
$ep = fn($t) => $t ? ($signTypes[$t] ?? $t) : '';
$stTag = function (string $s) use ($statuses) {
    $cls = $s === 'approved' ? 'ok' : ($s === 'rejected' ? 'err' : '');
    return '<span class="tag ' . $cls . '">' . e($statuses[$s] ?? $s) . '</span>';
};
$tot = 0.0; $totFact = 0.0;
foreach ($rows as $r) { $tot += (float) $r['plan_shown']; if ($r['fact_total'] !== null) { $totFact += (float) $r['fact_total']; } }
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Реестр служебок на командировки за месяц (по дате начала) — подписанные и до подписи.
    Показаны кто подал и кто согласовал (вид ЭП и дата), смета и факт. Доступно скачивание в Excel.</p>

<form method="get" action="/trips/registry" class="panel flt" style="margin-bottom:16px">
    <label>Месяц<br>
        <select name="month" onchange="this.form.submit()">
            <?php foreach ($months as $m): ?>
                <option value="<?= e($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn primary" type="submit">Показать</button>
    <a class="btn" href="/trips/registry/export?month=<?= urlencode($month) ?>">⤓ Excel</a>
</form>

<section class="panel">
    <table class="table tbl-cards">
        <thead><tr>
            <th>№</th><th>Командируемый</th><th>Отдел</th><th>Город</th><th>Период</th><th>Источник</th>
            <th class="num">Смета, ₽</th><th class="num">Факт, ₽</th><th>Статус</th>
            <th>Подал</th><th>Согласовал</th><th>Факт</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td data-label="№"><?= e($t['number'] ?: '—') ?></td>
                <td data-label="Командируемый"><?= e($t['emp_name']) ?></td>
                <td data-label="Отдел" class="muted"><?= e($t['dept_name'] ?? '') ?></td>
                <td data-label="Город"><?= e($t['destination']) ?></td>
                <td data-label="Период"><?= $dt($t['date_from']) ?>—<?= $dt($t['date_to']) ?></td>
                <td data-label="Источник" class="muted"><?= e($t['source_name'] ?? '') ?></td>
                <td data-label="Смета" class="num"><?= $money($t['plan_shown']) ?></td>
                <td data-label="Факт" class="num"><?= $t['fact_total'] !== null ? $money($t['fact_total']) : '—' ?></td>
                <td data-label="Статус"><?= $stTag($t['status']) ?></td>
                <td data-label="Подал"><?php if ($t['author_signed_at']): ?><?= e($t['author_name'] ?? '') ?><br><span class="muted" style="font-size:.76rem"><?= $ep($t['author_sign_type']) ?> · <?= e(substr((string) $t['author_signed_at'], 0, 10)) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td data-label="Согласовал"><?php if ($t['director_signed_at']): ?><?= e($t['director_sign_name'] ?: '') ?><br><span class="muted" style="font-size:.76rem"><?= $ep($t['director_sign_type']) ?> · <?= e(substr((string) $t['director_signed_at'], 0, 10)) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td data-label="Факт"><?php if ($t['fact_at']): ?><span class="tag ok">внесён</span><?php elseif ($t['status'] === 'approved'): ?><span class="tag">ожидает</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><a class="btn btn-mini" href="/trips/<?= (int) $t['id'] ?>">открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="13" class="muted">За этот месяц служебок на командировки нет.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr style="font-weight:600">
                <td colspan="6">Итого (<?= count($rows) ?>)</td>
                <td class="num"><?= $money($tot) ?></td>
                <td class="num"><?= $totFact > 0 ? $money($totFact) : '—' ?></td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</section>

<?php if (!empty($isAccountant)): ?>
<p class="muted" style="font-size:.85rem">Бухгалтерия: фактические расходы вносятся по утверждённой заявке — откройте строку со статусом «ожидает» и заполните блок «Фактические расходы». После факта в бюджете командировок учитывается факт вместо плана.</p>
<?php endif; ?>
