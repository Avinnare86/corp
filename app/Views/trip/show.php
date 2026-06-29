<?php
use App\Controllers\TripController;
$money = fn($v) => number_format((float) $v, 2, ',', ' ');
$st = $t['status'];
$stLabel = $statuses[$st] ?? $st;
?>
<h1 style="margin-bottom:2px"><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">
    <?= $st === 'approved' ? '<span class="tag ok">' . e($stLabel) . '</span>' : ($st === 'rejected' ? '<span class="tag err">' . e($stLabel) . '</span>' : '<span class="tag">' . e($stLabel) . '</span>') ?>
    <a href="/trips">← к списку</a>
    <?php if (in_array($st, ['draft', 'revision'], true) && ((int) $t['author_id'] === (int) ($authUser['id'] ?? 0) || $isAdmin)): ?>
        · <a href="/trips/form/<?= (int) $t['id'] ?>">редактировать</a>
    <?php endif; ?>
    <?php if ($st === 'approved'): ?> · <a href="/trips/<?= (int) $t['id'] ?>/order" target="_blank">приказ о командировании</a><?php endif; ?>
</p>

<?php if ($st === 'revision' && $t['reject_reason']): ?>
    <div class="panel" style="border-left:4px solid #b00020"><strong>На доработке:</strong> <?= e($t['reject_reason']) ?></div>
<?php endif; ?>

<section class="panel">
    <table class="table tbl-cards">
        <tbody>
            <tr><td>Командируемый</td><td><strong><?= e($emp['full_name'] ?? '') ?></strong><?= $emp && $emp['position'] ? ' <span class="muted">(' . e($emp['position']) . ')</span>' : '' ?></td></tr>
            <tr><td>Отдел</td><td><?= e($deptName ?: '—') ?></td></tr>
            <tr><td>Куда</td><td><?= e($t['destination']) ?><?= $t['event'] ? ' — ' . e($t['event']) : '' ?></td></tr>
            <tr><td>Период</td><td><?= e(date('d.m.Y', strtotime($t['date_from']))) ?> — <?= e(date('d.m.Y', strtotime($t['date_to']))) ?></td></tr>
            <tr><td>Цель/задание</td><td><?= nl2br(e($t['purpose'] ?? '')) ?></td></tr>
            <tr><td>Источник</td><td><?= e($sourceName ?: '—') ?></td></tr>
            <tr><td>Автор записки</td><td><?= e($author['full_name'] ?? '') ?></td></tr>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2 style="margin-top:0">Сегменты пребывания</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Период</th><th>Место</th><th class="num">Дней</th></tr></thead>
        <tbody>
        <?php foreach ($segments as $s): ?>
            <tr><td data-label="Период"><?= e(date('d.m.Y', strtotime($s['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($s['end_date']))) ?></td>
                <td data-label="Место"><?= e($locLabels[$s['location']] ?? $s['location']) ?></td>
                <td data-label="Дней" class="num"><?= \App\Services\TripService::calDays($s['start_date'], $s['end_date']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$segments): ?><tr><td colspan="3" class="muted">Не заданы.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2 style="margin-top:0">Смета</h2>
    <table class="table">
        <thead><tr><th>Статья</th><th class="num">План, ₽</th><?php if ($factTotal !== null): ?><th class="num">Факт, ₽</th><?php endif; ?></tr></thead>
        <tbody>
            <tr><td>Суточные (РФ <?= (int) $estimate['days_rf'] ?> + зарубеж <?= (int) $estimate['days_abroad'] ?> дн.)</td><td class="num"><?= $money($estimate['per_diem']) ?></td><?php if ($factTotal !== null): ?><td class="num"><?= $money($t['fact_per_diem']) ?></td><?php endif; ?></tr>
            <tr><td>Проживание</td><td class="num"><?= $money($estimate['lodging']) ?></td><?php if ($factTotal !== null): ?><td class="num"><?= $money($t['fact_lodging']) ?></td><?php endif; ?></tr>
            <tr><td>Проезд/билеты</td><td class="num"><?= $money($estimate['travel']) ?></td><?php if ($factTotal !== null): ?><td class="num"><?= $money($t['fact_travel']) ?></td><?php endif; ?></tr>
            <tr><td>Доп.расходы</td><td class="num"><?= $money($estimate['extras']) ?></td><?php if ($factTotal !== null): ?><td class="num"><?= $money($t['fact_other']) ?></td><?php endif; ?></tr>
            <tr style="font-weight:700"><td>Итого</td><td class="num"><?= $money($st === 'approved' ? $t['plan_total'] : $estimate['total']) ?></td><?php if ($factTotal !== null): ?><td class="num"><?= $money($factTotal) ?></td><?php endif; ?></tr>
        </tbody>
    </table>
    <?php foreach ($extras as $ex): ?><p class="muted" style="margin:2px 0;font-size:.85rem"><?= e($ex['kind_name']) ?>: <?= $money($ex['amount']) ?> ₽<?= $ex['note'] ? ' — ' . e($ex['note']) : '' ?></p><?php endforeach; ?>
</section>

<section class="panel">
    <h2 style="margin-top:0">Вложения</h2>
    <ul style="margin:0">
        <?php foreach ($attachments as $a): ?><li><span class="muted"><?= e($attKinds[$a['kind']] ?? $a['kind']) ?>:</span> <a href="/trips/<?= (int) $t['id'] ?>/attachment/<?= (int) $a['id'] ?>"><?= e($a['orig_name']) ?></a></li><?php endforeach; ?>
        <?php if (!$attachments): ?><li class="muted">нет</li><?php endif; ?>
    </ul>
</section>

<?php if (in_array($st, ['on_approval', 'approved'], true)): ?>
<section class="panel" style="max-width:560px">
    <h2 style="margin-top:0">Электронная подпись</h2>
    <p style="margin:0;line-height:1.7">
        <?php if ($t['author_signed_at']): ?>Подал: <strong><?= e($author['full_name'] ?? '') ?></strong> (<?= e(TripController::SIGN_TYPES[$t['author_sign_type']] ?? $t['author_sign_type']) ?>, <?= e(substr((string) $t['author_signed_at'], 0, 16)) ?>)<br><?php endif; ?>
        <?php if ($t['director_signed_at']): ?>Согласовал: <strong><?= e($t['director_sign_name']) ?></strong><?= $t['director_sign_position'] ? ', ' . e($t['director_sign_position']) : '' ?> (<?= e(TripController::SIGN_TYPES[$t['director_sign_type']] ?? $t['director_sign_type']) ?>, <?= e(substr((string) $t['director_signed_at'], 0, 16)) ?>)<br>
        Сертификат: <span class="mono"><?= e($t['director_cert']) ?></span><br>
        <?php if (!empty($sig['fingerprint'])): ?>Отпечаток: <span class="mono" style="font-size:.8rem"><?= e($sig['fingerprint']) ?></span><br><?php endif; ?>
        <?php if (!empty($sig['sig_b64'])): ?><span class="tag ok">прикреплена усиленная подпись (.sig)</span><?php endif; ?>
        <?php endif; ?>
    </p>
</section>
<?php endif; ?>

<?php if ($st === 'on_approval' && $isDirector): ?>
<section class="panel" style="border-left:4px solid #1a7f37">
    <h2 style="margin-top:0">Согласование (директор)</h2>
    <form method="post" action="/trips/<?= (int) $t['id'] ?>/approve" class="grid-form" onsubmit="return confirm('Согласовать заявку? Бюджет будет проверен и план зарезервирован.')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Вид подписи<select name="sign_type"><?php foreach ($signTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label class="grow">Пароль<input type="password" name="password" required></label>
        <button class="btn btn-primary">Согласовать</button>
    </form>
    <form method="post" action="/trips/<?= (int) $t['id'] ?>/reject" class="grid-form" style="margin-top:10px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="grow">Причина возврата<input type="text" name="reason" maxlength="500"></label>
        <button class="btn btn-danger">Вернуть на доработку</button>
    </form>
</section>
<?php endif; ?>

<?php if ($st === 'approved' && $isAccountant): ?>
<section class="panel" style="border-left:4px solid #0b6">
    <h2 style="margin-top:0">Фактические расходы (бухгалтерия)</h2>
    <p class="muted" style="margin-top:0">По окончании командировки внесите фактические суммы — в бюджете план заменится фактом.</p>
    <form method="post" action="/trips/<?= (int) $t['id'] ?>/fact" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Суточные, ₽<input type="text" name="fact_per_diem" value="<?= $t['fact_at'] ? rtrim(rtrim(number_format((float) $t['fact_per_diem'], 2, '.', ''), '0'), '.') : '' ?>"></label>
        <label>Проживание, ₽<input type="text" name="fact_lodging" value="<?= $t['fact_at'] ? rtrim(rtrim(number_format((float) $t['fact_lodging'], 2, '.', ''), '0'), '.') : '' ?>"></label>
        <label>Проезд, ₽<input type="text" name="fact_travel" value="<?= $t['fact_at'] ? rtrim(rtrim(number_format((float) $t['fact_travel'], 2, '.', ''), '0'), '.') : '' ?>"></label>
        <label>Доп.расходы, ₽<input type="text" name="fact_other" value="<?= $t['fact_at'] ? rtrim(rtrim(number_format((float) $t['fact_other'], 2, '.', ''), '0'), '.') : '' ?>"></label>
        <button class="btn btn-primary"><?= $t['fact_at'] ? 'Обновить факт' : 'Внести факт' ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($t['archived_at'] === null && ($isAdmin || (int) $t['author_id'] === (int) ($authUser['id'] ?? 0) || $isDirector)): ?>
<form method="post" action="/trips/<?= (int) $t['id'] ?>/archive" onsubmit="return confirm('Переместить заявку в архив?')" style="margin-top:8px">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn">В архив</button>
</form>
<?php endif; ?>
