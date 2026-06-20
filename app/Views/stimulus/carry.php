<?php
// Перенос выплат с прошлого месяца: справочно суммы прошлого месяца + отдельные кнопки ежемес./единовр.
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <h1 style="margin:0;font-size:1.2rem">Перенос выплат с прошлого месяца</h1>
    <form method="get" action="/memos/carry" class="form-inline" style="gap:8px">
        <label>Месяц<input type="month" name="period" value="<?= e($cur) ?>" onchange="this.form.submit()"></label>
    </form>
</div>

<section class="panel">
    <p class="muted" style="margin-top:0">Переносятся <strong>утверждённые</strong> выплаты за <strong><?= e($prev) ?></strong> →
        черновики на <strong><?= e($cur) ?></strong> (автор — вы; только видимые вам по вашей ветке). После переноса их нужно
        подписать. Получатели, у которых уже есть выплата этого вида за <?= e($cur) ?>, <strong>пропускаются</strong> (без задвоения).</p>

    <table class="table">
        <thead><tr><th>Вид выплат</th><th class="num">Строк за <?= e($prev) ?></th><th class="num">Сумма</th><th>Действие</th></tr></thead>
        <tbody>
        <tr>
            <td>Ежемесячные</td>
            <td class="num"><?= (int) $sum['monthly']['cnt'] ?></td>
            <td class="num" style="white-space:nowrap"><?= money($sum['monthly']['amt']) ?></td>
            <td>
                <form method="post" action="/memos/carry" onsubmit="return confirm('Перенести ежемесячные выплаты на <?= e($cur) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="monthly">
                    <input type="hidden" name="period" value="<?= e($cur) ?>">
                    <button class="btn btn-primary btn-mini" <?= $sum['monthly']['cnt'] ? '' : 'disabled' ?>>↧ Перенести ежемесячные</button>
                </form>
            </td>
        </tr>
        <tr>
            <td>Единовременные (премии)</td>
            <td class="num"><?= (int) $sum['onetime']['cnt'] ?></td>
            <td class="num" style="white-space:nowrap"><?= money($sum['onetime']['amt']) ?></td>
            <td>
                <form method="post" action="/memos/carry" onsubmit="return confirm('Перенести единовременные выплаты на <?= e($cur) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="onetime">
                    <input type="hidden" name="period" value="<?= e($cur) ?>">
                    <button class="btn btn-primary btn-mini" <?= $sum['onetime']['cnt'] ? '' : 'disabled' ?>>↧ Перенести единовременные</button>
                </form>
            </td>
        </tr>
        </tbody>
    </table>
    <?php if (!$memos): ?><p class="muted">За <?= e($prev) ?> утверждённых выплат, видимых вам, нет — переносить нечего.</p><?php endif; ?>
</section>
