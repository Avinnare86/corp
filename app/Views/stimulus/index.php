<?php use App\Controllers\StimulusController; $st = StimulusController::STATUS;
$stClass = ['draft'=>'','head_signed'=>'st-wait','deputy_signed'=>'st-wait','approved'=>'st-ok','rejected'=>'st-rev','revision'=>'st-rev'];
function memoRow($m, $st, $stClass) { ?>
    <tr>
        <td class="mono"><?= e($m['number'] ?: '#'.$m['id']) ?></td>
        <td><a href="/memos/<?= (int)$m['id'] ?>"><?= e($m['dept_name'] ?? '—') ?></a>
            <?php if (!empty($m['author_name'])): ?><br><span class="muted" style="font-size:.76rem"><?= e($m['author_name']) ?></span><?php endif; ?></td>
        <td><?= e($m['period']) ?></td>
        <td><?= $m['pay_kind']==='onetime' ? 'единовременная' : 'ежемесячная' ?></td>
        <td><span class="st <?= $stClass[$m['status']] ?? '' ?>"><?= e($st[$m['status']] ?? $m['status']) ?></span></td>
        <td><a class="btn btn-mini" href="/memos/<?= (int)$m['id'] ?>">Открыть</a></td>
    </tr>
<?php } ?>
<div class="chat-head">
    <h1 style="margin:0;font-size:1.2rem">Служебки о стимуле</h1>
    <?php if ($canCreate): ?><a class="btn btn-primary" href="/memos/new">+ Новая служебка</a><?php endif; ?>
    <?php if (!empty($canCreateMgmt)): ?><a class="btn btn-gold" href="/memos/mgmt/new">+ Стимул замам / гл. бухгалтеру</a><?php endif; ?>
</div>

<?php if ($todo): ?>
<section class="panel">
    <h2>Ждут вашего решения <span class="badge"><?= count($todo) ?></span></h2>
    <table class="table"><thead><tr><th>№</th><th>Отдел / автор</th><th>Период</th><th>Вид</th><th>Статус</th><th></th></tr></thead>
        <tbody><?php foreach ($todo as $m) memoRow($m, $st, $stClass); ?></tbody></table>
</section>
<?php endif; ?>

<?php if ($accountant): ?>
<section class="panel">
    <h2>Бухгалтерия: подписанные служебки</h2>
    <p class="muted">Видны после утверждения замом (до утверждения директором тоже).</p>
    <table class="table"><thead><tr><th>№</th><th>Отдел / автор</th><th>Период</th><th>Вид</th><th>Статус</th><th></th></tr></thead>
        <tbody><?php foreach ($accountant as $m) memoRow($m, $st, $stClass); ?></tbody></table>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Мои служебки</h2>
    <?php if (!$mine): ?><p class="muted">Пока нет. <?php if ($canCreate): ?><a href="/memos/new">Создать первую</a>.<?php endif; ?></p><?php else: ?>
    <table class="table"><thead><tr><th>№</th><th>Отдел</th><th>Период</th><th>Вид</th><th>Статус</th><th></th></tr></thead>
        <tbody><?php foreach ($mine as $m) memoRow($m, $st, $stClass); ?></tbody></table>
    <?php endif; ?>
</section>
