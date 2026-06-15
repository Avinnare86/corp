<?php
use App\Controllers\AppealController;
$stCls = ['registered'=>'st-wait','work'=>'st-wait','extended'=>'st-rev','answered'=>'st-ok'];
$active = $a['status'] !== 'answered';
$overdue = $active && $a['due_date'] < date('Y-m-d');
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/appeals">← Обращения</a>
    <h1 style="margin:0;font-size:1.2rem"><?= e($a['number']) ?> — <?= e($a['subject']) ?></h1>
    <span class="st <?= $stCls[$a['status']] ?? '' ?>"><?= e(AppealController::STATUS[$a['status']] ?? $a['status']) ?></span>
    <?php if ($overdue): ?><span class="st st-rev">⚠ просрочено</span><?php endif; ?>
</div>

<div class="board">
<div>
<section class="panel">
    <h2>Карточка обращения</h2>
    <table class="table">
        <tr><td class="muted" style="width:160px">Заявитель</td><td><strong><?= e($a['applicant']) ?></strong></td></tr>
        <tr><td class="muted">Контакт</td><td><?= e($a['contact'] ?: '—') ?></td></tr>
        <tr><td class="muted">Источник</td><td><?= e(AppealController::SOURCE[$a['source']] ?? $a['source']) ?></td></tr>
        <tr><td class="muted">Поступило</td><td><?= e($a['received_at']) ?></td></tr>
        <tr><td class="muted">Срок ответа</td><td class="<?= $overdue?'minus':'' ?>"><strong><?= e($a['due_date']) ?></strong> (59-ФЗ)</td></tr>
        <tr><td class="muted">Исполнитель</td><td><?= e($a['assignee_name'] ?? '—') ?></td></tr>
    </table>
    <?php if (trim((string)$a['body']) !== ''): ?>
        <h3 class="sub">Текст обращения</h3>
        <div class="doc-body"><?= nl2br(e($a['body'])) ?></div>
    <?php endif; ?>
    <?php if ($a['answer']): ?>
        <h3 class="sub">Ответ заявителю (<?= e(substr((string)$a['answered_at'],0,16)) ?>)</h3>
        <div class="doc-body" style="border-left:4px solid var(--ok)"><?= nl2br(e($a['answer'])) ?></div>
    <?php endif; ?>
</section>

<?php if ($log): ?>
<section class="panel">
    <h2>История</h2>
    <?php foreach ($log as $l): ?>
        <div class="hist-row"><span class="muted mono"><?= e(substr((string)$l['created_at'],0,16)) ?></span>
            <span><?= e($l['event']) ?> <span class="muted">(<?= e($l['user_name']) ?>)</span></span></div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
</div>

<div>
<?php if ($active): ?>
<section class="panel">
    <h2>Действия</h2>
    <?php if ($manage): ?>
    <form method="post" action="/appeals/<?= (int)$a['id'] ?>/action" class="form-inline"><?= csrf_field() ?>
        <label>Исполнитель
            <select name="assignee_id"><option value="">—</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$a['assignee_id']===(int)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-mini" name="act" value="assign">Назначить</button>
    </form>
    <?php endif; ?>

    <?php if (($manage || $isAssignee) && !$wasExtended): ?>
    <h3 class="sub">Продление срока (однократно, +30 дней)</h3>
    <form method="post" action="/appeals/<?= (int)$a['id'] ?>/action" class="form-inline"><?= csrf_field() ?>
        <label class="grow">Причина<input type="text" name="reason" required></label>
        <button class="btn" name="act" value="extend">Продлить</button>
    </form>
    <?php endif; ?>

    <?php if ($manage || $isAssignee): ?>
    <h3 class="sub">Ответ заявителю</h3>
    <form method="post" action="/appeals/<?= (int)$a['id'] ?>/action"><?= csrf_field() ?>
        <label>Текст ответа<textarea name="answer" rows="5" required></textarea></label>
        <button class="btn btn-primary" name="act" value="answer" onclick="return confirm('Зафиксировать ответ и закрыть обращение?')">✓ Дан ответ</button>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
</div>
