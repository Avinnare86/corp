<?php $weekHdr = $rows ? $rows[0]['norm']['weeks'] : []; ?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;font-size:1.2rem">Норматив проверки анкет</h1>
    <form method="get" action="/norm/report" style="margin:0">
        <input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()">
    </form>
</div>

<section class="panel">
    <p class="muted" style="margin-top:0">Норматив — сколько анкет в неделю сотрудник проверяет «за оклад и надбавку».
        Доплата по тарифу начисляется только за анкеты <strong>сверх норматива</strong> (норматив 0 = всё по тарифу, чистый сдельщик;
        пусто = классическая модель max(оклад, сделка)). Месячное покрытие = норматив × отработанные дни ÷ рабочих дней в неделе.
        Недели в отчёте — справочно (дни 1–7, 8–14, …); оплата считается по месячному покрытию.</p>

    <?php if (!$rows): ?>
        <p class="muted" style="margin:0">Нет активных проверяющих анкеты.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Сотрудник</th>
                <th>Норматив/нед</th>
                <?php foreach ($weekHdr as $w): ?><th class="num" title="дни <?= e($w['label']) ?>"><?= e($w['label']) ?></th><?php endforeach; ?>
                <th class="num">Проверено</th>
                <th class="num">Покрыто</th>
                <th class="num">Сверх</th>
                <th class="num">Доплата</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $n = $r['norm']; $u = $r['u']; ?>
            <tr>
                <td><a href="/admin/employees/<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></a></td>
                <td>
                    <?php if ($canEdit): ?>
                    <form method="post" action="/norm/set" class="form-inline" style="gap:4px;margin:0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="period" value="<?= e($period) ?>">
                        <input type="number" name="anketa_norm" min="0" style="width:72px"
                               value="<?= $u['anketa_norm']===null ? '' : (int)$u['anketa_norm'] ?>" placeholder="—">
                        <button class="btn btn-mini">OK</button>
                    </form>
                    <?php else: ?>
                        <?= $u['anketa_norm']===null ? '<span class="muted">—</span>' : (int)$u['anketa_norm'] ?>
                    <?php endif; ?>
                </td>
                <?php foreach ($n['weeks'] as $w): ?>
                    <td class="num"<?= ($w['target'] && $w['checked'] < $w['target']) ? ' style="color:#c0392b"' : '' ?>>
                        <?= (int)$w['checked'] ?><?php if ($w['target']!==null): ?><span class="muted">/<?= (int)$w['target'] ?></span><?php endif; ?>
                    </td>
                <?php endforeach; ?>
                <td class="num"><strong><?= (int)$n['checked'] ?></strong></td>
                <td class="num"><?= $n['has_norm'] ? (int)$n['covered'] : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= $n['has_norm'] ? (int)$n['above_count'] : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= $n['has_norm'] ? money($n['above_sum']) : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="font-size:.82rem">«Покрыто» — анкеты в пределах месячного норматива (оплачены окладом+надбавкой);
        «Сверх» и «Доплата» — анкеты и тариф сверх норматива (идут к выплате дополнительно).</p>
    <?php endif; ?>
</section>
