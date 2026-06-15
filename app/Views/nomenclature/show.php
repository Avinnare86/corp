<?php use App\Controllers\NomenclatureController; $st = NomenclatureController::STATUS;
$dirLabel = ['incoming'=>'вх.','outgoing'=>'исх.','internal'=>'внутр.']; ?>
<div class="chat-head">
    <a class="btn btn-mini" href="/nomenclature?year=<?= (int)$case['year'] ?>">← Номенклатура</a>
    <h1 style="margin:0;font-size:1.2rem">Дело <?= e($case['index_code']) ?></h1>
    <span class="st <?= $case['status']==='open'?'st-ok':'st-wait' ?>"><?= e($st[$case['status']] ?? $case['status']) ?></span>
</div>

<section class="panel">
    <table class="table">
        <tr><td class="muted" style="width:170px">Заголовок</td><td><strong><?= e($case['title']) ?></strong></td></tr>
        <tr><td class="muted">Индекс</td><td class="mono"><?= e($case['index_code']) ?></td></tr>
        <tr><td class="muted">Подразделение</td><td><?= e($case['dept_name'] ?: 'общее') ?></td></tr>
        <tr><td class="muted">Год</td><td><?= (int)$case['year'] ?></td></tr>
        <tr><td class="muted">Срок хранения</td><td><?= e($case['storage_term']) ?><?= $case['storage_years']!==null?' ('.(int)$case['storage_years'].' лет)':' (постоянно)' ?></td></tr>
        <?php if ($case['closed_on']): ?><tr><td class="muted">Закрыто</td><td><?= e($case['closed_on']) ?></td></tr><?php endif; ?>
        <?php if ($case['destroy_after']): ?><tr><td class="muted">Уничтожение после</td><td><?= (int)$case['destroy_after'] ?> г.<?= (int)$case['destroy_after'] < (int)date('Y') ? ' <span class="minus">— срок хранения истёк</span>' : '' ?></td></tr><?php endif; ?>
    </table>
    <?php if ($canManage && $case['status']==='open'): ?>
        <form method="post" action="/nomenclature/<?= (int)$case['id'] ?>/close" class="inline" onsubmit="return confirm('Закрыть дело? Будет рассчитан год возможного уничтожения.')" style="margin-top:10px">
            <?= csrf_field() ?><button class="btn">📁 Закрыть дело</button></form>
    <?php elseif ($canManage && $case['status']==='closed'): ?>
        <form method="post" action="/nomenclature/<?= (int)$case['id'] ?>/archive" class="inline" style="margin-top:10px">
            <?= csrf_field() ?><button class="btn">🗄 Передать в архив</button></form>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Подшитые документы (<?= count($docs) ?>)</h2>
    <table class="table">
        <thead><tr><th>Рег. №</th><th>Тип</th><th>Заголовок</th><th>Напр.</th><th>Списан</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $d): ?>
            <tr class="doc-row" onclick="location.href='/docs/<?= (int)$d['id'] ?>'">
                <td class="mono"><?= e($d['reg_number'] ?: '—') ?></td>
                <td><?= e($d['type_name']) ?></td>
                <td><?= e($d['title']) ?></td>
                <td class="muted"><?= e($dirLabel[$d['direction']] ?? '') ?></td>
                <td class="muted"><?= e(substr((string)$d['filed_at'],0,10)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$docs): ?><tr><td colspan="5" class="muted">В деле пока нет документов. Списывайте документы в дело из их карточек.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
