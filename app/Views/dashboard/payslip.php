<?php
$unit = ($payroll['schedule_type'] ?? '5_2') === '2_2' ? 'смен' : 'дн.';
$doesAnketas = (int) ($user['does_anketas'] ?? 1) === 1;
$doesOps = (int) ($user['does_operations'] ?? 0) === 1;
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/">← Главная</a>
    <h1 style="margin:0;font-size:1.2rem">Расчётный листок</h1>
</div>

<div class="cards">
    <div class="card">
        <div class="card-label">Сотрудник</div>
        <div class="card-value"><?= e($user['full_name']) ?></div>
        <div class="muted"><?= e($user['position']) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Ожидаемая ЗП за <?= e($payroll['period']) ?></div>
        <div class="card-value big"><?= money($payroll['total']) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Место в рейтинге</div>
        <div class="card-value big"><?= $myRank ? '#' . $myRank['rank'] . ' из ' . (int) $totalEmployees : '—' ?></div>
        <div class="muted">Качество: <?= ($myRank && $myRank['quality'] !== null) ? $myRank['quality'] . '%' : 'нет проверок' ?></div>
    </div>
</div>

<!-- Явка -->
<section class="panel attendance">
    <div>
        <h2 style="margin:0">Рабочий день</h2>
        <?php if (!$today || empty($today['opened_at'])): ?>
            <p class="muted" style="margin:4px 0 0">Вы ещё не приступили к работе. Пока день не открыт, ввод работы недоступен;
                нажатие кнопки засчитает день в табель.</p>
        <?php elseif (empty($today['closed_at'])): ?>
            <p class="muted" style="margin:4px 0 0">Вы работаете с <?= e(substr($today['opened_at'], 11, 5)) ?>. В конце смены нажмите «Завершить работу».</p>
        <?php else: ?>
            <p class="muted" style="margin:4px 0 0">Работа завершена: <?= e(substr($today['opened_at'],11,5)) ?>–<?= e(substr($today['closed_at'],11,5)) ?>. Явка засчитана. Ввод работы закрыт.</p>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!$today || empty($today['opened_at'])): ?>
            <form method="post" action="/day/open"><?= csrf_field() ?><button class="btn btn-primary">▶ Приступить к работе</button></form>
        <?php elseif (empty($today['closed_at'])): ?>
            <form method="post" action="/day/close" onsubmit="return confirm('Завершить работу? Ввод работы будет закрыт.')">
                <?= csrf_field() ?><button class="btn btn-gold">⏹ Завершить работу</button></form>
        <?php else: ?>
            <form method="post" action="/day/open"><?= csrf_field() ?><button class="btn btn-mini">↻ Возобновить работу</button></form>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <h2>Расчётный лист за <?= e($payroll['period']) ?></h2>

    <?php if ($doesAnketas || $doesOps): ?>
    <h3 class="sub">Сделка</h3>
    <table class="table">
        <thead><tr><th>Работа</th><th class="num">Кол-во</th><th class="num">Цена</th><th class="num">Сумма</th></tr></thead>
        <tbody>
        <?php if ($doesAnketas): foreach ($payroll['anketa_breakdown'] as $b): ?>
            <tr>
                <td>Анкеты · <?= e($b['title']) ?></td>
                <td class="num"><?= (int) $b['count'] ?></td>
                <td class="num"><?= money($b['price']) ?></td>
                <td class="num"><?= money($b['subtotal']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        <?php if ($doesOps): foreach ($payroll['ops_breakdown'] as $b): ?>
            <tr>
                <td><?= e($b['name']) ?></td>
                <td class="num"><?= (int) $b['count'] ?></td>
                <td class="num"><?= money($b['price']) ?></td>
                <td class="num"><?= money($b['subtotal']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        <?php if (!$payroll['anketa_breakdown'] && !$payroll['ops_breakdown']): ?>
            <tr><td colspan="4" class="muted">За период сделка не введена.</td></tr>
        <?php endif; ?>
        <tr class="total"><td>Итого сделка</td><td></td><td></td><td class="num"><?= money($payroll['piecework']) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($payroll['fix_breakdown']): ?>
    <h3 class="sub">Подработки (фикс, пропорц. времени)</h3>
    <table class="table">
        <thead><tr><th>Работа</th><th class="num">За месяц</th><th class="num">Начислено</th></tr></thead>
        <tbody>
        <?php foreach ($payroll['fix_breakdown'] as $f): ?>
            <tr><td><?= e($f['name']) ?></td><td class="num"><?= money($f['monthly']) ?></td><td class="num"><?= money($f['amount']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Итого подработки</td><td></td><td class="num"><?= money($payroll['fix_sum']) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

    <h3 class="sub">Сколько начислено</h3>
    <table class="table payslip">
        <tr>
            <td>Заработано за месяц (сделка<?= $payroll['fix_sum']>0 ? ' + подработки' : '' ?>)</td>
            <td class="num"><?= money($payroll['earned']) ?></td>
        </tr>
        <tr>
            <td>Гарантированный минимум: оклад <?= money($payroll['oklad']) ?><?php if ($payroll['allowance']>0): ?> + надбавка <?= money($payroll['allowance']) ?><?php endif; ?>
                <br><span class="muted">за отработанное время <?= (int)$payroll['worked_days'] ?>/<?= (int)$payroll['norm_days'] ?> <?= $unit ?><?php if ($payroll['rate_volume']!=1): ?>, ставка <?= e($payroll['rate_volume']) ?><?php endif; ?></span></td>
            <td class="num"><?= money($payroll['floor']) ?></td>
        </tr>
        <tr class="payslip-verdict">
            <td>
                <?php if ($payroll['reached_level']): ?>
                    ✅ Начислено по заработку — он выше гарантии
                <?php else: ?>
                    ⓘ Заработок ниже гарантии — начислен гарантированный минимум
                    <?php if ($payroll['allowance']>0): ?><br><span class="muted">надбавка выплачивается, но сделкой пока не отработана</span><?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="num"><strong><?= money($payroll['gross']) ?></strong></td>
        </tr>
        <tr>
            <td>− Снижения за ошибки (повторы дороже)
                <?php if (!empty($payroll['penalty_capped'])): ?>
                    <br><span class="muted">начислено −<?= money($payroll['penalties']) ?>, но удержано только −<?= money($payroll['penalty_effective']) ?>: итог не опускается ниже гарантии</span>
                <?php endif; ?>
            </td>
            <td class="num minus">−<?= money($payroll['penalty_effective']) ?></td>
        </tr>
        <?php if (!empty($payroll['penalty_carry_in']) && $payroll['penalty_carry_in']>0): ?>
        <tr><td class="muted">включая перенос штрафа из прошлого месяца (зафиксирован после 25-го)</td><td class="num muted">−<?= money($payroll['penalty_carry_in']) ?></td></tr>
        <?php endif; ?>
        <?php if (($payroll['stim_total'] ?? 0) > 0): ?>
        <tr>
            <td>+ Стимул по утверждённым служебкам
                <?php if (($payroll['stim_monthly']??0)>0): ?><br><span class="muted">ежемесячный (пропорц. отработке): <?= money($payroll['stim_monthly']) ?></span><?php endif; ?>
                <?php if (($payroll['stim_onetime']??0)>0): ?><br><span class="muted">единовременный (полной суммой): <?= money($payroll['stim_onetime']) ?></span><?php endif; ?>
            </td>
            <td class="num plus">+<?= money($payroll['stim_total']) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="total"><td>ИТОГО к выплате (прогноз, не ниже гарантии <?= money($payroll['floor']) ?>)</td><td class="num"><?= money($payroll['total']) ?></td></tr>
    </table>
    <?php if (($payroll['piece_carry'] ?? 0) > 0 || ($payroll['piece_settled'] ?? 0) > 0): ?>
    <p class="muted" style="margin-top:8px">📅 <strong>Сделка по отсечке 25-го числа:</strong>
        к выплате в служебку этого месяца (до 25-го) — <strong><?= money($payroll['piece_settled']) ?></strong>;
        <?php if (($payroll['piece_carry']??0)>0): ?>проверено после 25-го (перейдёт в служебку следующего месяца) — <strong><?= money($payroll['piece_carry']) ?></strong>.<?php else: ?>после 25-го пока ничего.<?php endif; ?>
        <?php if (($payroll['penalty_deferred']??0)>0): ?><br>⚠ Штраф −<?= money($payroll['penalty_deferred']) ?> зафиксирован после 25-го — учтётся в следующем месяце.<?php endif; ?>
    </p>
    <?php endif; ?>
</section>

<?php if ($unread): ?>
<div class="flash flash-info">У вас <?= (int) $unread ?> непрочитанных уведомлений. <a href="/notifications">Посмотреть</a></div>
<?php endif; ?>
