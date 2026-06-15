<h1>Визы: отработанное</h1>

<div class="cards">
    <div class="card"><div class="card-label">Всего проверено анкет</div><div class="card-value big"><?= (int)$total ?></div></div>
    <div class="card"><div class="card-label">К проверке</div><div class="card-value"><a href="/visas">← Вернуться к гриду</a></div></div>
</div>

<section class="panel">
    <h2>По датам и партиям</h2>
    <table class="table">
        <thead><tr><th>Дата</th><th>Партия (пачка)</th><th class="num">Проверено анкет</th><th></th></tr></thead>
        <tbody>
        <?php $prev = null; foreach ($byDay as $g): ?>
            <tr>
                <td><?= $g['d'] !== $prev ? '<strong>' . e(date('d.m.Y', strtotime($g['d']))) . '</strong>' : '' ?></td>
                <td><?= e($g['batch_name']) ?></td>
                <td class="num"><?= (int)$g['cnt'] ?></td>
                <td><a class="btn btn-mini" href="/visas/done?date=<?= e($g['d']) ?>">анкеты за день →</a></td>
            </tr>
        <?php $prev = $g['d']; endforeach; ?>
        <?php if (!$byDay): ?><tr><td colspan="4" class="muted">Проверенных анкет пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($date !== ''): ?>
<section class="panel">
    <h2>Анкеты за <?= e(date('d.m.Y', strtotime($date))) ?> (<?= count($rows) ?>)</h2>
    <table class="table">
        <thead><tr><th>№</th><th>Фамилия (лат)</th><th>Гражданство</th><th>Партия</th><th>Время</th><th>Доработки</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?= e($r['out_no'] ?: '#'.$r['id']) ?></td>
                <td><?= e($r['surname_lat']) ?> <?= e($r['names_lat']) ?></td>
                <td><?= e($r['citizenship']) ?></td>
                <td><?= e($r['batch_name']) ?></td>
                <td><?= e(substr((string)$r['checked_at'], 11, 5)) ?></td>
                <td><?= (int)$r['rework_count'] ? '⚠ ' . (int)$r['rework_count'] : '—' ?></td>
                <td><a class="btn btn-mini" href="/visas/row/<?= (int)$r['id'] ?>">✎ Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">За этот день анкет нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="muted">Нашли ошибку в проверенной анкете? Откройте её — можно исправить поля или вернуть на доработку.</p>
</section>
<?php endif; ?>
