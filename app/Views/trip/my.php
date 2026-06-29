<?php $money = fn($v) => number_format((float) $v, 2, ',', ' '); ?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Командировки, в которые вы направлены. Полная заявка и приказ доступны по ссылке «открыть».</p>

<section class="panel">
    <table class="table tbl-cards">
        <thead><tr><th>№</th><th>Куда</th><th>Период</th><th>Источник</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td data-label="№"><?= e($t['number'] ?: '—') ?></td>
                <td data-label="Куда"><?= e($t['destination']) ?><?= $t['event'] ? ' — ' . e($t['event']) : '' ?></td>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($t['date_from']))) ?> — <?= e(date('d.m.Y', strtotime($t['date_to']))) ?></td>
                <td data-label="Источник" class="muted"><?= e($t['source_name'] ?? '') ?></td>
                <td data-label="Статус"><?= $t['status'] === 'approved' ? '<span class="tag ok">' . e($statuses[$t['status']]) . '</span>' : '<span class="tag">' . e($statuses[$t['status']] ?? $t['status']) . '</span>' ?></td>
                <td><a class="btn btn-mini" href="/trips/<?= (int) $t['id'] ?>">открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Командировок нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
