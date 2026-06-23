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
    <?php if ($canCreate): ?><a class="btn" href="/memos/carry">↧ Перенос с прошлого месяца</a><?php endif; ?>
    <?php if (!empty($canCreateMgmt)): ?><a class="btn btn-gold" href="/memos/mgmt/new">+ Стимул замам / гл. бухгалтеру</a><?php endif; ?>
</div>

<div class="form-inline" style="gap:6px;margin:0 0 4px">
    <a class="btn btn-mini <?= !empty($archive) ? '' : 'btn-primary' ?>" href="/memos">Актуальные</a>
    <a class="btn btn-mini <?= !empty($archive) ? 'btn-primary' : '' ?>" href="/memos?archive=1">🗄 Архив</a>
</div>

<?php if (empty($archive)): ?>
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

<?php else: ?>
<section class="panel">
    <h2>🗄 Архив отклонённых служебок</h2>
    <p class="muted">Окончательно отклонённые служебки (после подписи ЭП). Безвозвратно удалить может только администратор; автор может вернуть на доработку.</p>
    <table class="table">
        <thead><tr><th>№</th><th>Отдел / автор</th><th>Период</th><th>Причина отклонения</th><th>Архивировал</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($archived as $m): ?>
            <tr>
                <td class="mono"><?= e($m['number'] ?: '#'.$m['id']) ?></td>
                <td><a href="/memos/<?= (int)$m['id'] ?>"><?= e($m['dept_name'] ?? '—') ?></a><br><span class="muted" style="font-size:.76rem"><?= e($m['author_name'] ?? '') ?></span></td>
                <td><?= e($m['period']) ?></td>
                <td class="muted" style="font-size:.82rem"><?= e($m['reject_reason'] ?? '') ?></td>
                <td class="muted" style="font-size:.78rem"><?= e($m['archiver'] ?? '') ?><br><?= e(substr((string)$m['archived_at'],0,16)) ?></td>
                <td>
                    <a class="btn btn-mini" href="/memos/<?= (int)$m['id'] ?>">Открыть</a>
                    <form method="post" action="/memos/<?= (int)$m['id'] ?>/unarchive" class="inline" onsubmit="return confirm('Вернуть служебку из архива на доработку?')"><?= csrf_field() ?><button class="btn btn-mini">↩ На доработку</button></form>
                    <?php if (!empty($isAdmin)): ?>
                    <form method="post" action="/memos/<?= (int)$m['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить служебку БЕЗВОЗВРАТНО? Действие необратимо.')"><?= csrf_field() ?><button class="btn btn-mini btn-danger">🗑 Удалить</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$archived): ?><tr><td colspan="6" class="muted">Архив пуст.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
