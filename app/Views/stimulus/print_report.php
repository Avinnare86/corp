<?php
// Служебки на печать: по отделам, в разрезе ежемесячные/единовременные, со ссылками на PDF.
use App\Controllers\StimulusController;
$byDept = [];
foreach ($memos as $m) {
    $byDept[$m['dept_name'] ?: '— без отдела —'][] = $m;
}
ksort($byDept, SORT_LOCALE_STRING);
$kindLabel = fn($k) => $k === 'onetime' ? 'Единовременные' : 'Ежемесячные';
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <h1 style="margin:0;font-size:1.2rem">Служебки на печать</h1>
    <a class="btn btn-primary" href="/memos/print-batch?period=<?= e($period) ?>" target="_blank">⬇ Распечатать все (PDF)</a>
</div>

<section class="panel">
    <form method="get" action="/memos/print-report" class="form-inline" style="gap:10px;align-items:flex-end">
        <label>Месяц
            <select name="period" onchange="this.form.submit()">
                <option value="">— все месяцы —</option>
                <?php foreach ($periods as $p): ?><option value="<?= e($p) ?>" <?= $period === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
        </label>
        <span class="muted">Группировка по отделам, в разрезе ежемесячные/единовременные. PDF — печать формы из браузера.</span>
    </form>
</section>

<?php foreach ($byDept as $dept => $list): ?>
<section class="panel">
    <h2 style="margin-top:0"><?= e($dept) ?></h2>
    <?php
    $groups = ['monthly' => [], 'onetime' => []];
    foreach ($list as $m) { $groups[$m['pay_kind'] === 'onetime' ? 'onetime' : 'monthly'][] = $m; }
    ?>
    <?php foreach ($groups as $k => $items): if (!$items) continue; ?>
        <h3 class="sub"><?= $kindLabel($k) ?> (<?= count($items) ?>)</h3>
        <table class="table">
            <thead><tr><th>№</th><th>Период</th><th class="num">Чел.</th><th>Статус</th><th>Автор</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $m): ?>
                <tr>
                    <td class="mono"><?= e($m['number'] ?: ('#' . $m['id'])) ?></td>
                    <td class="muted"><?= e($m['period']) ?></td>
                    <td class="num"><?= (int)$m['people'] ?></td>
                    <td><span class="tag <?= $m['status'] === 'approved' ? '' : 'off' ?>"><?= e($statusLabels[$m['status']] ?? $m['status']) ?></span></td>
                    <td class="muted"><?= e($m['author_name']) ?></td>
                    <td><a class="btn btn-mini" href="/memos/<?= (int)$m['id'] ?>/print" target="_blank">PDF</a>
                        <a class="btn btn-mini" href="/memos/<?= (int)$m['id'] ?>">открыть</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if (!$memos): ?><section class="panel"><p class="muted">Служебок за период нет.</p></section><?php endif; ?>
