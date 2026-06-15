<?php
use App\Controllers\VacationController;
$stCls = ['on_head'=>'st-wait','on_approve'=>'st-wait','approved'=>'st-ok','rejected'=>'st-rev','auto_rejected'=>'st-rev','replaced'=>''];
?>
<h1>График отпусков — <?= (int)$year ?></h1>

<form method="get" action="/vacations" class="form-inline">
    <label>Год<input type="number" name="year" value="<?= (int)$year ?>" style="max-width:110px" onchange="this.form.submit()"></label>
</form>

<div class="board">
<section class="panel">
    <h2>Моя заявка на отпуск</h2>
    <form method="post" action="/vacations" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="year" value="<?= (int)$year ?>">
        <label>С<input type="date" name="start_date" required></label>
        <label>По<input type="date" name="end_date" required></label>
        <?php if ($approvedMine): ?>
        <label>Тип
            <select name="kind" onchange="document.getElementById('replBox').style.display=this.value==='change'?'':'none'">
                <option value="initial">Новый период</option>
                <option value="change">Изменение сроков</option>
            </select>
        </label>
        <label id="replBox" style="display:none">Заменяет период
            <select name="replaces_id">
                <?php foreach ($approvedMine as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['start_date']) ?> — <?= e($a['end_date']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <button class="btn btn-primary">Подать заявку</button>
    </form>
    <p class="muted">Заявка идёт руководителю отдела, затем на утверждение. Дни в запретных зонах отклоняются автоматически.</p>

    <h3 class="sub">Мои заявки</h3>
    <table class="table">
        <tbody>
        <?php foreach ($my as $m): ?>
            <tr>
                <td><?= e($m['start_date']) ?> — <?= e($m['end_date']) ?> <span class="muted">(<?= (int)$m['days'] ?> дн.)</span>
                    <?= $m['kind']==='change'?'<span class="tag">изменение</span>':'' ?></td>
                <td><span class="st <?= $stCls[$m['status']] ?? '' ?>"><?= e(VacationController::STATUS[$m['status']] ?? $m['status']) ?></span>
                    <?php if ($m['comment']): ?><br><span class="muted" style="font-size:.78rem"><?= e($m['comment']) ?></span><?php endif; ?></td>
                <td><?php if ($m['status']==='approved'): ?><a class="btn btn-mini" href="/vacations/<?= (int)$m['id'] ?>/notice">Уведомление</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$my): ?><tr><td class="muted">Заявок нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <?php if ($queueHead): ?>
    <h2>На согласование (мой отдел)</h2>
    <?php foreach ($queueHead as $q): ?>
        <form method="post" action="/vacations/<?= (int)$q['id'] ?>/decide" class="panel" style="padding:12px;margin-bottom:10px">
            <?= csrf_field() ?>
            <strong><?= e($q['full_name']) ?></strong> <?= $q['kind']==='change'?'<span class="tag">изменение</span>':'' ?>
            <div class="form-inline" style="margin-top:8px">
                <label>С<input type="date" name="start_date" value="<?= e($q['start_date']) ?>"></label>
                <label>По<input type="date" name="end_date" value="<?= e($q['end_date']) ?>"></label>
                <label class="grow">Комментарий<input type="text" name="comment"></label>
            </div>
            <button class="btn btn-mini btn-primary" name="act" value="approve">✓ Согласовать (можно с правкой дат)</button>
            <button class="btn btn-mini btn-danger" name="act" value="reject">✕ Отклонить</button>
        </form>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($queueApprove): ?>
    <h2>На утверждение</h2>
    <?php foreach ($queueApprove as $q): ?>
        <form method="post" action="/vacations/<?= (int)$q['id'] ?>/decide" class="panel" style="padding:12px;margin-bottom:10px">
            <?= csrf_field() ?>
            <strong><?= e($q['full_name']) ?></strong> <span class="muted"><?= e($q['dept_name'] ?? '') ?></span>:
            <?= e($q['start_date']) ?> — <?= e($q['end_date']) ?> (<?= (int)$q['days'] ?> дн.)
            <?= $q['kind']==='change'?'<span class="tag">изменение</span>':'' ?>
            <?php if ($q['comment']): ?><div class="muted" style="font-size:.8rem"><?= e($q['comment']) ?></div><?php endif; ?>
            <div class="form-inline" style="margin-top:8px">
                <label class="grow">Комментарий<input type="text" name="comment"></label>
                <button class="btn btn-mini btn-primary" name="act" value="approve">✓ Утвердить</button>
                <button class="btn btn-mini btn-danger" name="act" value="reject">✕ Отклонить</button>
            </div>
        </form>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$queueHead && !$queueApprove): ?><h2>Согласование</h2><p class="muted">Нет заявок, ожидающих вашего решения.</p><?php endif; ?>
</section>
</div>

<section class="panel">
    <h2>Сводный график <?= (int)$year ?></h2>
    <table class="table">
        <thead><tr><th>Подразделение</th><th>Сотрудник</th><th>Период</th><th class="num">Дней</th><th>Статус</th><th>Уведомление</th></tr></thead>
        <tbody>
        <?php foreach ($schedule as $s2): ?>
            <tr>
                <td class="muted"><?= e($s2['dept_name'] ?? '—') ?></td>
                <td><?= e($s2['full_name']) ?></td>
                <td><?= e($s2['start_date']) ?> — <?= e($s2['end_date']) ?></td>
                <td class="num"><?= (int)$s2['days'] ?></td>
                <td><span class="st <?= $stCls[$s2['status']] ?? '' ?>"><?= e(VacationController::STATUS[$s2['status']] ?? $s2['status']) ?></span></td>
                <td><?php if ($s2['status']==='approved'): ?>
                    <a class="btn btn-mini" href="/vacations/<?= (int)$s2['id'] ?>/notice"><?= $s2['notified_at'] ? '✓ выдано' : 'Сформировать' ?></a>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$schedule): ?><tr><td colspan="6" class="muted">Заявок на <?= (int)$year ?> год пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($isHead || $isApprover): ?>
<section class="panel">
    <h2>Запретные зоны дат (автоотказ)</h2>
    <form method="post" action="/vacations/blackout" class="grid-form">
        <?= csrf_field() ?>
        <label>С<input type="date" name="start_date" required></label>
        <label>По<input type="date" name="end_date" required></label>
        <label>Подразделение
            <select name="department_id"><option value="">— все —</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Сотрудник (точечно)
            <select name="employee_id"><option value="">— все —</option>
                <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Причина<input type="text" name="reason" placeholder="пиковая нагрузка"></label>
        <button class="btn btn-primary">Добавить зону</button>
    </form>
    <table class="table">
        <tbody>
        <?php foreach ($blackouts as $b): ?>
            <tr>
                <td><?= e($b['start_date']) ?> — <?= e($b['end_date']) ?></td>
                <td><?= $b['full_name'] ? e($b['full_name']) : ($b['dept_name'] ? e($b['dept_name']) : 'все сотрудники') ?></td>
                <td class="muted"><?= e($b['reason']) ?></td>
                <td><form method="post" action="/vacations/blackout/<?= (int)$b['id'] ?>/delete" onsubmit="return confirm('Удалить зону?')">
                    <?= csrf_field() ?><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$blackouts): ?><tr><td class="muted">Зон нет — все даты разрешены.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
