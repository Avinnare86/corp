<?php
$money = fn($v) => number_format((float) $v, 2, ',', ' ');
$stTag = function (string $s) use ($statuses) {
    $cls = $s === 'approved' ? 'ok' : ($s === 'rejected' ? 'err' : '');
    return '<span class="tag ' . $cls . '">' . e($statuses[$s] ?? $s) . '</span>';
};
?>
<h1><?= e($title) ?></h1>
<?php if ($canCreate): ?>
    <p style="margin-top:0"><a class="btn primary" href="/trips/form">+ Новая заявка на командировку</a></p>
<?php endif; ?>

<section class="panel">
    <h2 style="margin-top:0">Заявки</h2>
    <table class="table tbl-cards">
        <thead><tr><th>№</th><th>Командируемый</th><th>Отдел</th><th>Куда</th><th>Период</th><th>Источник</th><th class="num">Смета, ₽</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($actual as $t): ?>
            <tr>
                <td data-label="№"><?= e($t['number'] ?: '—') ?></td>
                <td data-label="Командируемый"><?= e($t['emp_name']) ?></td>
                <td data-label="Отдел" class="muted"><?= e($t['dept_name'] ?? '') ?></td>
                <td data-label="Куда"><?= e($t['destination']) ?></td>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($t['date_from']))) ?>—<?= e(date('d.m.Y', strtotime($t['date_to']))) ?></td>
                <td data-label="Источник" class="muted"><?= e($t['source_name'] ?? '') ?></td>
                <td data-label="Смета" class="num"><?= $money($t['plan']) ?><?= $t['fact_at'] ? ' <span class="muted" style="font-size:.75rem">(факт)</span>' : '' ?></td>
                <td data-label="Статус"><?= $stTag($t['status']) ?></td>
                <td><a class="btn btn-mini" href="/trips/<?= (int) $t['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$actual): ?><tr><td colspan="9" class="muted">Заявок нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php if (!empty($archive)): ?>
<section class="panel">
    <h2>Архив</h2>
    <table class="table tbl-cards">
        <thead><tr><th>№</th><th>Командируемый</th><th>Куда</th><th>Период</th><th class="num">Смета, ₽</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($archive as $t): ?>
            <tr>
                <td data-label="№"><?= e($t['number'] ?: '—') ?></td>
                <td data-label="Командируемый"><?= e($t['emp_name']) ?></td>
                <td data-label="Куда"><?= e($t['destination']) ?></td>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($t['date_from']))) ?>—<?= e(date('d.m.Y', strtotime($t['date_to']))) ?></td>
                <td data-label="Смета" class="num"><?= $money($t['plan']) ?></td>
                <td data-label="Статус"><?= $stTag($t['status']) ?></td>
                <td><a class="btn btn-mini" href="/trips/<?= (int) $t['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
