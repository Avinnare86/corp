<?php
use App\Controllers\AppealController;
$stCls = ['registered'=>'st-wait','work'=>'st-wait','extended'=>'st-rev','answered'=>'st-ok'];
$today = date('Y-m-d');
?>
<div class="chat-head">
    <h1 style="margin:0">Обращения граждан</h1>
    <?php if ($manage): ?><a class="btn btn-gold" href="/appeals?export=1<?= $st ? '&status='.e($st) : '' ?>">📊 В Excel</a><?php endif; ?>
    <form method="get" action="/appeals" style="margin-left:auto">
        <select name="status" onchange="this.form.submit()">
            <option value="">все статусы</option>
            <?php foreach (AppealController::STATUS as $sv=>$sl): ?><option value="<?= $sv ?>" <?= $st===$sv?'selected':'' ?>><?= e($sl) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($manage): ?>
<section class="panel">
    <h2>Зарегистрировать обращение</h2>
    <form method="post" action="/appeals" class="grid-form">
        <?= csrf_field() ?>
        <label>Заявитель (ФИО)<input type="text" name="applicant" required></label>
        <label>Контакт (email/телефон)<input type="text" name="contact"></label>
        <label>Источник
            <select name="source"><?php foreach (AppealController::SOURCE as $sv=>$sl): ?><option value="<?= $sv ?>"><?= e($sl) ?></option><?php endforeach; ?></select>
        </label>
        <label>Дата поступления<input type="date" name="received_at" value="<?= date('Y-m-d') ?>"></label>
        <label>Исполнитель
            <select name="assignee_id"><option value="">— назначить позже —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label style="grid-column:1/-1">Тема<input type="text" name="subject" required></label>
        <label style="grid-column:1/-1">Текст обращения<textarea name="body" rows="3"></textarea></label>
        <button class="btn btn-primary">Зарегистрировать (срок 30 дней)</button>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <table class="table">
        <thead><tr><th>№</th><th>Заявитель / тема</th><th>Поступило</th><th>Срок</th><th>Исполнитель</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $danger = $r['status']!=='answered' && $r['due_date'] < $today;
            $soon = $r['status']!=='answered' && !$danger && $r['due_date'] <= date('Y-m-d', strtotime('+5 days'));
        ?>
            <tr class="doc-row" onclick="location.href='/appeals/<?= (int)$r['id'] ?>'">
                <td class="mono"><?= e($r['number']) ?></td>
                <td><strong><?= e($r['applicant']) ?></strong><br><span class="muted"><?= e(mb_strimwidth($r['subject'],0,70,'…')) ?></span></td>
                <td class="muted"><?= e($r['received_at']) ?></td>
                <td class="<?= $danger?'minus':($soon?'':'muted') ?>" style="<?= $soon?'color:#8a6d00;font-weight:600':'' ?>">
                    <?= e($r['due_date']) ?><?= $danger?' ⚠':'' ?><?= $soon?' ⏰':'' ?></td>
                <td><?= e($r['assignee_name'] ?? '—') ?></td>
                <td><span class="st <?= $stCls[$r['status']] ?? '' ?>"><?= e(AppealController::STATUS[$r['status']] ?? $r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Обращений нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
