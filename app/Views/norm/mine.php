<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;font-size:1.2rem">Мой норматив проверки</h1>
    <form method="get" action="/norm" style="margin:0">
        <input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()">
    </form>
</div>

<?php if (!$norm['has_norm']): ?>
<section class="panel">
    <p class="muted" style="margin:0">Норматив проверки анкет не задан — оплата по классической модели
        (бо́льшее из оклада и сделки). За <?= e($period) ?> проверено анкет: <strong><?= (int)$norm['checked'] ?></strong>.</p>
</section>
<?php else: ?>
<div class="cards">
    <div class="card"><div class="card-label">Недельный норматив</div><div class="card-value big"><?= (int)$norm['weekly_norm'] ?></div></div>
    <div class="card"><div class="card-label">Проверено за месяц</div><div class="card-value big"><?= (int)$norm['checked'] ?></div></div>
    <div class="card"><div class="card-label">Покрыто окладом</div><div class="card-value big"><?= (int)$norm['covered'] ?></div>
        <div class="muted">норматив × отработка</div></div>
    <div class="card"><div class="card-label">Сверх норматива</div><div class="card-value big"><?= (int)$norm['above_count'] ?></div>
        <div class="muted">доплата <?= money($norm['above_sum']) ?></div></div>
</div>

<section class="panel">
    <h2 style="margin-top:0">По неделям</h2>
    <table class="table">
        <thead><tr><th>Неделя (дни)</th><th class="num">Проверено</th><th class="num">Ориентир</th><th class="num">Выполнение</th></tr></thead>
        <tbody>
        <?php foreach ($norm['weeks'] as $w): ?>
            <tr>
                <td><?= e($w['label']) ?></td>
                <td class="num"><strong><?= (int)$w['checked'] ?></strong></td>
                <td class="num"><?= $w['target']===null ? '—' : (int)$w['target'] ?></td>
                <td class="num"<?= ($w['pct']!==null && $w['pct']<100) ? ' style="color:#c0392b"' : '' ?>>
                    <?= $w['pct']===null ? '—' : (int)$w['pct'].'%' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="font-size:.82rem">Ориентир по неделям — справочный (норматив пропорц. длине недели);
        к оплате считается месячное покрытие (<?= (int)$norm['covered'] ?> анкет), доплата по тарифу — за анкеты сверх него.</p>
</section>
<?php endif; ?>
