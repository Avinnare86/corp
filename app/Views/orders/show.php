<?php
use App\Controllers\OrderController;
$stCls = ['new'=>'st-wait','work'=>'st-wait','review'=>'st-rev','done'=>'st-ok','canceled'=>''];
$active = !in_array($o['status'], ['done','canceled'], true);
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/orders<?= $isAuthor ? '?tab=out' : '' ?>">← Поручения</a>
    <h1 style="margin:0;font-size:1.2rem">Поручение #<?= (int)$o['id'] ?></h1>
    <span class="st <?= $stCls[$o['status']] ?? '' ?>"><?= e(OrderController::STATUS[$o['status']] ?? $o['status']) ?></span>
</div>

<div class="board">
<div>
<section class="panel">
    <h2><?= e($o['title']) ?></h2>
    <?php if ($o['body']): ?><div class="doc-body" style="margin-bottom:10px"><?= nl2br(e($o['body'])) ?></div><?php endif; ?>
    <table class="table">
        <tr><td class="muted" style="width:170px">От кого</td><td><?= e($o['author_name']) ?></td></tr>
        <tr><td class="muted">Ответственный</td><td><strong><?= e($o['assignee_name']) ?></strong></td></tr>
        <?php if ($cos): ?><tr><td class="muted">Соисполнители</td><td><?= e(implode(', ', array_map(fn($c)=>$c['full_name'], $cos))) ?></td></tr><?php endif; ?>
        <tr><td class="muted">Срок</td><td><?= e($o['due_date'] ?: '—') ?><?= $o['due_date'] && $o['due_date'] < date('Y-m-d') && $active ? ' <span class="minus">⚠ просрочено</span>' : '' ?></td></tr>
        <tr><td class="muted">Контроль</td><td>
            <?php if ((int)$o['on_control']): ?><span class="tag" style="background:#fff3d6;color:#8a5a00">🔍 на контроле</span> (напоминать за <?= (int)$o['remind_days'] ?> дн.)
            <?php elseif (($o['control_result']??'')==='in_time'): ?><span class="tag ok">✓ исполнено в срок</span>
            <?php elseif (($o['control_result']??'')==='violated'): ?><span class="tag" style="background:#ffe1e1;color:#a40000">⚠ исполнено с нарушением срока</span>
            <?php else: ?><span class="muted">не на контроле</span><?php endif; ?>
        </td></tr>
        <?php if ($o['doc_id']): ?><tr><td class="muted">Документ</td><td><a href="/docs/<?= (int)$o['doc_id'] ?>"><?= e(($o['doc_reg'] ? '№'.$o['doc_reg'].' ' : '') . $o['doc_title']) ?></a></td></tr><?php endif; ?>
        <?php if ($o['parent_id']): ?><tr><td class="muted">Родительское</td><td><a href="/orders/<?= (int)$o['parent_id'] ?>">поручение #<?= (int)$o['parent_id'] ?></a></td></tr><?php endif; ?>
    </table>

    <div class="form-inline" style="margin-top:12px">
        <?php if ($isAssignee && $o['status']==='new'): ?>
            <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline"><?= csrf_field() ?>
                <button class="btn btn-mini btn-primary" name="act" value="accept">Принять в работу</button></form>
        <?php endif; ?>
        <?php if ($isAuthor && $o['status']==='review'): ?>
            <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline"><?= csrf_field() ?>
                <button class="btn btn-mini btn-primary" name="act" value="accept_done">✓ Принять исполнение</button></form>
            <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="row-form inline"><?= csrf_field() ?>
                <input type="text" name="comment" placeholder="что доработать" class="narrow">
                <button class="btn btn-mini" name="act" value="return">↩ Вернуть</button></form>
        <?php endif; ?>
        <?php if ($isAuthor && $active): ?>
            <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline" onsubmit="return confirm('Снять поручение?')"><?= csrf_field() ?>
                <button class="btn btn-mini btn-danger" name="act" value="cancel">Снять</button></form>
        <?php endif; ?>
        <?php if (($isAuthor || !empty($isPrivileged)) && $active): ?>
            <?php if (!(int)$o['on_control']): ?>
                <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="row-form inline"><?= csrf_field() ?>
                    напомнить за <input type="number" name="remind_days" value="3" min="0" style="width:56px"> дн.
                    <button class="btn btn-mini" name="act" value="control_on">🔍 На контроль</button></form>
            <?php else: ?>
                <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline"><?= csrf_field() ?>
                    <button class="btn btn-mini" name="act" value="control_off">Снять с контроля</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($isAssignee && in_array($o['status'], ['new','work'], true)): ?>
    <h3 class="sub">Итоговый отчёт</h3>
    <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="form-inline"><?= csrf_field() ?>
        <label class="grow">Отчёт об исполнении<input type="text" name="report" required></label>
        <button class="btn btn-primary" name="act" value="report">✓ Исполнено</button>
    </form>
    <?php endif; ?>

    <?php if (($isAssignee || $isCo) && $active): ?>
    <h3 class="sub">Промежуточный отчёт</h3>
    <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="form-inline"><?= csrf_field() ?>
        <label class="grow">Ход исполнения<input type="text" name="report" required></label>
        <button class="btn" name="act" value="interim">+ Добавить</button>
    </form>
    <?php endif; ?>

    <?php if ($isAuthor && $active): ?>
    <h3 class="sub">Перенос срока</h3>
    <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="form-inline"><?= csrf_field() ?>
        <label>Новый срок<input type="date" name="new_date" required></label>
        <label class="grow">Причина<input type="text" name="reason" required></label>
        <button class="btn" name="act" value="postpone">Перенести</button>
    </form>
    <?php endif; ?>
</section>

<?php if ($reports): ?>
<section class="panel">
    <h2>Отчёты</h2>
    <?php foreach ($reports as $r): ?>
        <div class="hist-row">
            <span class="muted mono"><?= e(substr((string)$r['created_at'],0,16)) ?></span>
            <span class="tag <?= $r['kind']==='final'?'ok':'' ?>"><?= $r['kind']==='final'?'итоговый':'промежуточный' ?></span>
            <span><?= e($r['full_name']) ?>: <?= e($r['text']) ?></span>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($dueLog): ?>
<section class="panel">
    <h2>История переносов срока</h2>
    <?php foreach ($dueLog as $l): ?>
        <div class="hist-row">
            <span class="muted mono"><?= e(substr((string)$l['created_at'],0,16)) ?></span>
            <span><?= e($l['old_date'] ?: '—') ?> → <strong><?= e($l['new_date']) ?></strong> — <?= e($l['reason']) ?> <span class="muted">(<?= e($l['user_name']) ?>)</span></span>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
</div>

<div>
<section class="panel">
    <h2>Вложенные резолюции</h2>
    <?php foreach ($children as $ch): ?>
        <div class="hist-row">
            <span class="st <?= $stCls[$ch['status']] ?? '' ?>"><?= e(OrderController::STATUS[$ch['status']] ?? $ch['status']) ?></span>
            <a href="/orders/<?= (int)$ch['id'] ?>"><?= e($ch['title']) ?></a>
            <span class="muted">→ <?= e($ch['assignee_name']) ?><?= $ch['due_date'] ? ', до ' . e($ch['due_date']) : '' ?></span>
        </div>
    <?php endforeach; ?>
    <?php if (!$children): ?><p class="muted">Нет.</p><?php endif; ?>

    <?php if ($isAssignee && $active): ?>
    <h3 class="sub">Расписать подчинённому</h3>
    <form method="post" action="/orders" class="grid-form"><?= csrf_field() ?>
        <input type="hidden" name="parent_id" value="<?= (int)$o['id'] ?>">
        <label style="grid-column:1/-1">Текст<input type="text" name="title" required></label>
        <label>Исполнитель
            <select name="assignee_id">
                <?php foreach ($allUsers as $u2): if ((int)$u2['id']===$meId) continue; ?>
                    <option value="<?= (int)$u2['id'] ?>"><?= e($u2['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Срок<input type="date" name="due_date"></label>
        <button class="btn btn-primary">Дать вложенное поручение</button>
    </form>
    <?php endif; ?>
</section>
</div>
</div>
