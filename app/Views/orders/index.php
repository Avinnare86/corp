<?php
use App\Controllers\OrderController;
$stCls = ['new'=>'st-wait','work'=>'st-wait','review'=>'st-rev','done'=>'st-ok','canceled'=>''];
$today = date('Y-m-d');
?>
<h1>Поручения</h1>

<div class="tabs">
    <a class="tab <?= $tab==='in'||($tab!=='out'&&$tab!=='control')?'active':'' ?>" href="/orders">Мне поручено</a>
    <a class="tab <?= $tab==='out'?'active':'' ?>" href="/orders?tab=out">Я поручил</a>
    <?php if ($isBoss): ?><a class="tab <?= $tab==='control'?'active':'' ?>" href="/orders?tab=control">🔍 На контроле</a><?php endif; ?>
    <?php if ($isBoss): ?><a class="tab" href="/orders/report">📈 Дисциплина</a><?php endif; ?>
</div>

<?php if ($tab === 'control' && $isBoss): ?>
<section class="panel" style="display:flex;align-items:center;gap:12px">
    <form method="post" action="/orders/remind" class="inline"><?= csrf_field() ?>
        <button class="btn btn-primary">🔔 Разослать напоминания и эскалацию</button></form>
    <span class="muted">Исполнителям — о приближении срока (за N дней) и о просрочке; автору — эскалация по просроченным. Повторно не чаще раза в день.</span>
</section>
<?php endif; ?>

<?php if ($isBoss && $tab === 'out'): ?>
<section class="panel">
    <h2>Дать поручение</h2>
    <form method="post" action="/orders" class="grid-form">
        <?= csrf_field() ?>
        <label style="grid-column:span 2">Поручение<input type="text" name="title" required></label>
        <label>Вид
            <select name="kind">
                <?php foreach (OrderController::KIND as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Ответственный
            <select name="assignee_id" required>
                <?php foreach ($subordinates as $sub): ?><option value="<?= (int)$sub['id'] ?>"><?= e($sub['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Соисполнители (Ctrl+клик)
            <select name="coexecutors[]" multiple size="3">
                <?php foreach ($subordinates as $sub): ?><option value="<?= (int)$sub['id'] ?>"><?= e($sub['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Срок<input type="date" name="due_date"></label>
        <label style="grid-column:1/-1">Подробности<textarea name="body" rows="2"></textarea></label>
        <button class="btn btn-primary">Дать поручение</button>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <table class="table">
        <thead><tr><th>Поручение</th><th><?= $tab==='out'?'Исполнитель':'От кого' ?></th><th>Срок</th><th>Статус</th><th style="width:320px">Действия</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o):
            $over = $o['due_date'] && $o['due_date'] < $today && !in_array($o['status'], ['done','canceled'], true);
        ?>
            <tr>
                <td><a href="/orders/<?= (int)$o['id'] ?>"><strong><?= e($o['title']) ?></strong></a>
                    <?php if (($o['kind'] ?? 'order') !== 'order'): ?><span class="tag off"><?= e(OrderController::KIND[$o['kind']] ?? $o['kind']) ?></span><?php endif; ?>
                    <?php if ((int)$o['co_count']): ?><span class="tag">+<?= (int)$o['co_count'] ?> соисп.</span><?php endif; ?>
                    <?php if ((int)$o['child_count']): ?><span class="tag">↳ <?= (int)$o['child_count'] ?></span><?php endif; ?>
                    <?php if ($tab!=='out' && (int)$o['assignee_id']!==$meId): ?><span class="tag">я — соисполнитель</span><?php endif; ?>
                    <?php if ($o['report']): ?><br><span class="muted">Отчёт: <i><?= e(mb_strimwidth($o['report'],0,90,'…')) ?></i></span><?php endif; ?>
                </td>
                <td><?= e($tab==='out' ? $o['assignee_name'] : $o['author_name']) ?></td>
                <td class="<?= $over?'minus':'muted' ?>"><?= e($o['due_date'] ?: '—') ?><?= $over?' ⚠':'' ?></td>
                <td><span class="st <?= $stCls[$o['status']] ?? '' ?>"><?= e(OrderController::STATUS[$o['status']] ?? $o['status']) ?></span>
                    <?php if ((int)$o['on_control']): ?><br><span class="tag" style="background:#fff3d6;color:#8a5a00">🔍 на контроле</span><?php endif; ?>
                    <?php if (($o['control_result']??'')==='in_time'): ?><br><span class="tag ok">✓ в срок</span>
                    <?php elseif (($o['control_result']??'')==='violated'): ?><br><span class="tag" style="background:#ffe1e1;color:#a40000">⚠ с нарушением срока</span><?php endif; ?>
                    <?php if ($over): ?><br><span class="tag" style="background:#ffe1e1;color:#a40000">⚠ просрочено</span><?php endif; ?>
                </td>
                <td>
                <?php if ($tab !== 'out' && (int)$o['assignee_id'] === $meId): ?>
                    <?php if ($o['status'] === 'new'): ?>
                        <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline"><?= csrf_field() ?>
                            <button class="btn btn-mini" name="act" value="accept">Принять в работу</button></form>
                    <?php endif; ?>
                    <?php if (in_array($o['status'], ['new','work'], true)): ?>
                        <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="row-form" style="margin-top:4px"><?= csrf_field() ?>
                            <input type="text" name="report" placeholder="отчёт об исполнении" style="min-width:160px">
                            <button class="btn btn-mini btn-primary" name="act" value="report">✓ Исполнено</button></form>
                    <?php endif; ?>
                <?php elseif ($tab === 'out'): ?>
                    <?php if ($o['status'] === 'review'): ?>
                        <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline"><?= csrf_field() ?>
                            <button class="btn btn-mini btn-primary" name="act" value="accept_done">Принять</button></form>
                        <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="row-form inline"><?= csrf_field() ?>
                            <input type="text" name="comment" placeholder="что доработать" class="narrow">
                            <button class="btn btn-mini" name="act" value="return">↩ Вернуть</button></form>
                    <?php endif; ?>
                    <?php if (!in_array($o['status'], ['done','canceled'], true)): ?>
                        <form method="post" action="/orders/<?= (int)$o['id'] ?>/action" class="inline" onsubmit="return confirm('Снять поручение?')"><?= csrf_field() ?>
                            <button class="btn btn-mini btn-danger" name="act" value="cancel">Снять</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Поручений нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
