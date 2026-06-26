<?php
$unit = ($payroll['schedule_type'] ?? '5_2') === '2_2' ? 'смен' : 'дн.';
$doesAnketas = (int) ($user['does_anketas'] ?? 1) === 1;
$doesOps = (int) ($user['does_operations'] ?? 0) === 1;
$p = $payroll;
$normModel = !empty($p['norm_model']);
$isHourly = !empty($p['is_hourly']);
$over = round((float) $p['total'] - (float) $p['min_total'], 2); // сверх минимума (после снижения за ошибки)
$penaltyRows = $penaltyRows ?? [];
$hnum = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ' '), '0'), '.'); // часы без лишних нулей
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
        <div class="card-label">Ожидаемая ЗП за <?= e($p['period']) ?></div>
        <div class="card-value big"><?= money($p['total']) ?></div>
        <?php if ($isHourly): ?>
            <div class="muted"><?= ($p['used_basis'] ?? 'none') === 'fact' ? 'итог по факту' : (($p['used_basis'] ?? 'none') === 'plan' ? 'прогноз по плану' : 'график не заполнен') ?></div>
        <?php else: ?>
            <div class="muted">не ниже минимума <?= money($p['min_total']) ?></div>
        <?php endif; ?>
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
    <h2>Расчётный лист за <?= e($p['period']) ?></h2>

    <?php if ($isHourly): ?>
    <table class="table payslip" style="max-width:560px">
        <tr><td>Ставка за час</td><td class="num"><?= money($p['hourly_rate']) ?>/ч</td></tr>
        <tr><td>Смен по графику (план)</td><td class="num"><?= (int) $p['norm_days'] ?></td></tr>
        <tr><td>Отработано смен (факт)</td><td class="num"><?= (int) $p['worked_days'] ?></td></tr>
        <tr><td>Часы: план / факт</td><td class="num"><?= $hnum($p['plan_hours']) ?> / <?= $hnum($p['fact_hours']) ?> ч</td></tr>
    </table>
    <?php else: ?>
    <table class="table payslip" style="max-width:520px">
        <tr><td>Количество рабочих дней</td><td class="num"><?= (int) $p['norm_days'] ?> <?= $unit ?></td></tr>
        <tr><td>Количество дней отработано</td><td class="num"><?= (int) $p['worked_days'] ?> <?= $unit ?></td></tr>
    </table>
    <?php endif; ?>

    <?php // ----- Детализация сделки (как есть): анкеты/операции + подработки ----- ?>
    <?php if (!$isHourly && ($doesAnketas || $doesOps)): ?>
    <h3 class="sub">Сделка — детализация</h3>
    <?php if ($normModel): ?>
        <p class="muted" style="margin:.2rem 0 .4rem">Анкеты — только <strong>сверх норматива</strong>: проверено
            <?= (int)$p['anketa_checked'] ?>, покрыто окладом <?= (int)$p['anketa_covered'] ?>,
            к доплате <?= (int)$p['anketa_above_count'] ?> (недельный норматив <?= (int)$p['anketa_norm_weekly'] ?>).</p>
    <?php endif; ?>
    <table class="table">
        <thead><tr><th>Работа</th><th class="num">Кол-во</th><th class="num">Цена</th><th class="num">Сумма</th></tr></thead>
        <tbody>
        <?php if ($doesAnketas): foreach ($p['anketa_breakdown'] as $b): ?>
            <tr><td>Анкеты · <?= e($b['title']) ?></td><td class="num"><?= (int) $b['count'] ?></td><td class="num"><?= money($b['price']) ?></td><td class="num"><?= money($b['subtotal']) ?></td></tr>
        <?php endforeach; endif; ?>
        <?php if ($doesOps): foreach ($p['ops_breakdown'] as $b): ?>
            <tr><td><?= e($b['name']) ?></td><td class="num"><?= (int) $b['count'] ?></td><td class="num"><?= money($b['price']) ?></td><td class="num"><?= money($b['subtotal']) ?></td></tr>
        <?php endforeach; endif; ?>
        <?php if (!$p['anketa_breakdown'] && !$p['ops_breakdown']): ?>
            <tr><td colspan="4" class="muted">За период сделка не введена.</td></tr>
        <?php endif; ?>
        <tr class="total"><td>Итого сделка</td><td></td><td></td><td class="num"><?= money($p['piecework']) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($p['fix_breakdown']): ?>
    <h3 class="sub">Подработки (фикс, пропорц. времени)</h3>
    <table class="table">
        <thead><tr><th>Работа</th><th class="num">За месяц</th><th class="num">Начислено</th></tr></thead>
        <tbody>
        <?php foreach ($p['fix_breakdown'] as $f): ?>
            <tr><td><?= e($f['name']) ?></td><td class="num"><?= money($f['monthly']) ?></td><td class="num"><?= money($f['amount']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Итого подработки</td><td></td><td class="num"><?= money($p['fix_sum']) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php // ===================== Начисления по видам ===================== ?>
    <h3 class="sub">Начислено</h3>
    <table class="table payslip">
        <?php if ($isHourly): ?>
        <!-- Почасовая оплата (2/2) -->
        <tr class="pay-head"><td><strong>Оплата по часам</strong> <span class="muted" style="font-weight:400">(<?= ($p['used_basis'] ?? 'none') === 'fact' ? 'факт' : (($p['used_basis'] ?? 'none') === 'plan' ? 'план/прогноз' : 'график не заполнен') ?>)</span></td><td class="num"><strong><?= money($p['base_pay']) ?></strong></td></tr>
        <tr><td class="sub-row"><?= $hnum($p['hours_paid']) ?> ч × <?= money($p['hourly_rate']) ?>/ч</td><td class="num"><?= money($p['base_pay']) ?></td></tr>
        <?php if (($p['night_pay'] ?? 0) > 0): ?>
        <tr><td class="sub-row">+ ночные (<?= $hnum($p['night_hours']) ?> ч × +<?= rtrim(rtrim(number_format((float)$p['night_pct'],1,'.',''),'0'),'.') ?>%)</td><td class="num plus">+<?= money($p['night_pay']) ?></td></tr>
        <?php endif; ?>
        <?php if (($p['holiday_pay'] ?? 0) > 0): ?>
        <tr><td class="sub-row">+ праздничные (<?= $hnum($p['holiday_hours']) ?> ч, ×<?= rtrim(rtrim(number_format((float)$p['holiday_mult'],2,'.',''),'0'),'.') ?>)</td><td class="num plus">+<?= money($p['holiday_pay']) ?></td></tr>
        <?php endif; ?>
        <?php if (($p['overtime_pay'] ?? 0) > 0): ?>
        <tr><td class="sub-row">+ сверхурочные (<?= $hnum($p['overtime_hours']) ?> ч, ×<?= rtrim(rtrim(number_format((float)$p['overtime_mult'],2,'.',''),'0'),'.') ?>)</td><td class="num plus">+<?= money($p['overtime_pay']) ?></td></tr>
        <?php endif; ?>
        <?php if (($p['personal_bonus'] ?? 0) > 0): ?>
        <tr><td class="sub-row">+ персональная надбавка<?= ($p['bonus_pct'] ?? 0) > 0 ? ' (' . rtrim(rtrim(number_format((float)$p['bonus_pct'],2,'.',''),'0'),'.') . '%)' : '' ?></td><td class="num plus">+<?= money($p['personal_bonus']) ?></td></tr>
        <?php endif; ?>
        <tr class="pay-head"><td><strong>Начислено всего</strong></td><td class="num"><strong><?= money($p['gross']) ?></strong></td></tr>
        <?php if (($p['used_basis'] ?? 'none') === 'none'): ?>
        <tr><td colspan="2" class="muted">График на этот месяц не заполнен — ожидаемая ЗП 0. Заполните сменный график.</td></tr>
        <?php endif; ?>
        <?php else: ?>
        <!-- Блок 1: оклад -->
        <tr class="pay-head"><td><strong>Начислено оклад</strong></td><td class="num"><strong><?= money($p['oklad_cap']) ?></strong></td></tr>
        <?php if ($normModel): ?>
            <tr><td class="sub-row">в т.ч. оклад за выполнение норматива</td><td class="num"><?= money($p['b1_dopl']) ?></td></tr>
        <?php else: ?>
            <tr><td class="sub-row">в т.ч. сделка</td><td class="num"><?= money($p['b1_sdelka']) ?></td></tr>
            <tr><td class="sub-row">в т.ч. доплата до минимума</td><td class="num"><?= money($p['b1_dopl']) ?></td></tr>
        <?php endif; ?>

        <!-- Блок 2: ежемесячные стимулирующие -->
        <?php if (($p['stim_guaranteed'] ?? 0) > 0): ?>
        <tr class="pay-head"><td><strong>Ежемесячные стимулирующие выплаты</strong></td><td class="num"><strong><?= money($p['stim_guaranteed']) ?></strong></td></tr>
            <?php if (($p['g_ank'] ?? 0) > 0): ?>
            <tr><td class="sub-row">за анкеты — в т.ч. сделка</td><td class="num"><?= money($p['b2_ank_sdelka']) ?></td></tr>
            <tr><td class="sub-row">за анкеты — доплата до минимума</td><td class="num"><?= money($p['b2_ank_dopl']) ?></td></tr>
            <?php endif; ?>
            <?php if (($p['g_viz'] ?? 0) > 0): ?>
            <tr><td class="sub-row">за визы — в т.ч. сделка</td><td class="num"><?= money($p['b2_viz_sdelka']) ?></td></tr>
            <tr><td class="sub-row">за визы — доплата до минимума</td><td class="num"><?= money($p['b2_viz_dopl']) ?></td></tr>
            <?php endif; ?>
            <?php if (($p['g_oth'] ?? 0) > 0): ?>
            <tr><td class="sub-row">за другое — доплата (гарантировано)</td><td class="num"><?= money($p['b2_oth_dopl']) ?></td></tr>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Блок 3: единовременные -->
        <?php if (($p['b3_total'] ?? 0) > 0): ?>
        <tr class="pay-head"><td><strong>Единовременные выплаты</strong></td><td class="num"><strong><?= money($p['b3_total']) ?></strong></td></tr>
            <?php if (($p['b3_leftover'] ?? 0) > 0): ?>
            <tr><td class="sub-row">в т.ч. сделка (сверх оклада и ежемес. целей)</td><td class="num"><?= money($p['b3_leftover']) ?></td></tr>
            <?php endif; ?>
            <?php if (($p['b3_onetime'] ?? 0) > 0): ?>
            <tr><td class="sub-row">в т.ч. разовый стимул</td><td class="num"><?= money($p['b3_onetime']) ?></td></tr>
            <?php endif; ?>
            <?php if (($p['b3_fix'] ?? 0) > 0): ?>
            <tr><td class="sub-row">в т.ч. подработки</td><td class="num"><?= money($p['b3_fix']) ?></td></tr>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; /* isHourly / 5-2 */ ?>

        <!-- Снижение за ошибки -->
        <tr>
            <td>Снижение за ошибки
                <?php if (!empty($p['penalty_capped'])): ?>
                    <br><span class="muted">начислено −<?= money($p['penalties']) ?>, удержано −<?= money($p['penalty_effective']) ?>: итог не ниже минимума</span>
                <?php endif; ?>
                <?php if ($penaltyRows): ?>
                    <details style="margin-top:4px">
                        <summary class="muted" style="cursor:pointer">какие ошибки вошли в расчёт (<?= count($penaltyRows) ?>)</summary>
                        <table class="table" style="margin-top:6px">
                            <thead><tr><th>Дата</th><th>Объект</th><th>Причина</th><th class="num">−₽</th></tr></thead>
                            <tbody>
                            <?php foreach ($penaltyRows as $pr): ?>
                                <tr>
                                    <td class="muted"><?= e($pr['date']) ?></td>
                                    <td><?= e($pr['title']) ?></td>
                                    <td class="muted"><?= e($pr['reason']) ?></td>
                                    <td class="num minus">−<?= money($pr['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </td>
            <td class="num minus">−<?= money($p['penalty_effective']) ?></td>
        </tr>
        <?php if (!empty($p['penalty_carry_in']) && $p['penalty_carry_in'] > 0): ?>
        <tr><td class="muted">включая перенос штрафа из прошлого месяца (после 25-го)</td><td class="num muted">−<?= money($p['penalty_carry_in']) ?></td></tr>
        <?php endif; ?>

        <!-- ИТОГО -->
        <tr class="total">
            <td>ИТОГО к выплате <?php if (!$isHourly): ?><span class="muted" style="font-weight:400">(минимум <?= money($p['min_total']) ?> + сверх минимума <?= money($over) ?>)</span><?php else: ?><span class="muted" style="font-weight:400">(<?= ($p['used_basis'] ?? 'none') === 'fact' ? 'итог по факту' : (($p['used_basis'] ?? 'none') === 'plan' ? 'прогноз по плану' : 'график не заполнен') ?>)</span><?php endif; ?></td>
            <td class="num"><strong><?= money($p['total']) ?></strong></td>
        </tr>
    </table>

    <?php $sdEff = round((float) ($p['piece_settled'] ?? 0) + (float) ($p['piece_carry_in'] ?? 0), 2); ?>
    <?php if (($p['piece_carry'] ?? 0) > 0 || ($p['piece_settled'] ?? 0) > 0 || ($p['piece_carry_in'] ?? 0) > 0): ?>
    <p class="muted" style="margin-top:8px">📅 <strong>Сделка по отсечке 25-го числа.</strong>
        В расчёт этого месяца учтено — <strong><?= money($sdEff) ?></strong>
        (этого месяца до 25-го <?= money($p['piece_settled']) ?><?php if (($p['piece_carry_in']??0)>0): ?> + перенос с прошлого месяца <?= money($p['piece_carry_in']) ?><?php endif; ?>).
        <?php if (($p['piece_carry']??0)>0): ?>Проверено после 25-го (перейдёт в следующий месяц) — <strong><?= money($p['piece_carry']) ?></strong>.<?php endif; ?>
        <br><span style="font-size:.92em">Таблица «Сделка — детализация» выше — за полный месяц (справочно); на начисление влияет учтённая сумма.</span>
        <?php if (($p['penalty_deferred']??0)>0): ?><br>⚠ Штраф −<?= money($p['penalty_deferred']) ?> зафиксирован после 25-го — учтётся в следующем месяце.<?php endif; ?>
    </p>
    <?php endif; ?>

    <p class="flash flash-info" style="margin-top:14px">⚠ Этот листок — <strong>предварительный прогноз</strong> и <strong>не является официальным документом</strong>.
        Официальный расчётный листок направляет бухгалтерия.</p>
</section>

<?php if ($unread): ?>
<div class="flash flash-info">У вас <?= (int) $unread ?> непрочитанных уведомлений. <a href="/notifications">Посмотреть</a></div>
<?php endif; ?>
