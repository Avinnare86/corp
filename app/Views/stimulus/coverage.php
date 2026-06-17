<?php
// Отчёт для бухгалтерии: помесячное покрытие сотрудников стимулом (ежемес./единовр.; утв. + проекты).
$byDept = [];
foreach ($rows as $r) { $byDept[$r['dept_name'] ?: '— без отдела —'][] = $r; }
ksort($byDept, SORT_LOCALE_STRING);
$qs = $period !== '' ? '?period=' . rawurlencode($period) : '';
$tot = ['m_appr'=>0,'o_appr'=>0,'m_proj'=>0,'o_proj'=>0,'total'=>0];
foreach ($rows as $r) { foreach ($tot as $k=>$v) { $tot[$k] += (float)$r[$k]; } }
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <h1 style="margin:0;font-size:1.2rem">Покрытие стимулом (бухгалтерия)</h1>
    <a class="btn btn-primary" href="/memos/coverage/export<?= e($qs) ?>">⬇ Выгрузить в Excel</a>
</div>

<section class="panel">
    <form method="get" action="/memos/coverage" class="form-inline" style="gap:10px;align-items:flex-end">
        <label>Месяц
            <select name="period" onchange="this.form.submit()">
                <option value="">— все месяцы —</option>
                <?php foreach ($periods as $p): ?><option value="<?= e($p) ?>" <?= $period === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
        </label>
        <span class="muted">Утверждённый стимул — к выплате; проекты — план (служебки в работе). Суммы — с учётом корректировок.</span>
    </form>
</section>

<?php foreach ($byDept as $dept => $list): ?>
<section class="panel">
    <h2 style="margin-top:0"><?= e($dept) ?></h2>
    <table class="table">
        <thead><tr>
            <th>Месяц</th><th>Сотрудник</th>
            <th class="num">Ежемес. (утв.)</th><th class="num">Единовр. (утв.)</th>
            <th class="num">Ежемес. (проект)</th><th class="num">Единовр. (проект)</th><th class="num">Итого</th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
            <tr>
                <td class="mono"><?= e($r['period']) ?></td>
                <td><?= e($r['recipient']) ?></td>
                <td class="num"><?= $r['m_appr'] ? money($r['m_appr']) : '—' ?></td>
                <td class="num"><?= $r['o_appr'] ? money($r['o_appr']) : '—' ?></td>
                <td class="num muted"><?= $r['m_proj'] ? money($r['m_proj']) : '—' ?></td>
                <td class="num muted"><?= $r['o_proj'] ? money($r['o_proj']) : '—' ?></td>
                <td class="num"><strong><?= money($r['total']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endforeach; ?>

<?php if ($rows): ?>
<section class="panel">
    <table class="table">
        <tr><td><strong>ИТОГО по выборке</strong></td>
            <td class="num"><strong><?= money($tot['m_appr']) ?></strong></td>
            <td class="num"><strong><?= money($tot['o_appr']) ?></strong></td>
            <td class="num"><strong><?= money($tot['m_proj']) ?></strong></td>
            <td class="num"><strong><?= money($tot['o_proj']) ?></strong></td>
            <td class="num"><strong><?= money($tot['total']) ?></strong></td>
        </tr>
    </table>
</section>
<?php else: ?>
<section class="panel"><p class="muted">Данных за период нет.</p></section>
<?php endif; ?>
