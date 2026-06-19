<?php
// Менеджер виз: единая таблица за день — этап 2 (авто, readonly) + проставление/акцепт этапов 1 и 3.
// Формы вынесены под таблицу (id=ts<uid>), поля связаны через атрибут form=, чтобы не ломать <table>.
$p1 = $stageOps[1]['price'] ?? 0; $p2 = $stageOps[2]['price'] ?? 0; $p3 = $stageOps[3]['price'] ?? 0;
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;font-size:1.2rem">Учёт и акцепт работы (визы)</h1>
    <form method="get" action="/visas/timesheet" class="form-inline" style="gap:8px">
        <label>Дата<input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></label>
    </form>
</div>

<section class="panel">
    <p class="muted" style="margin-top:0">Этап 2 — автоматически из грида проверки (идёт в расчёт сразу).
        Этапы 1 и 3 — проставьте/исправьте количество и нажмите <strong>«Принять»</strong>.
        <strong>Пока работа не принята — этапы 1 и 3 не идут в расчёт ЗП.</strong>
        Цены/шт: этап 1 — <?= money($p1) ?>, этап 2 — <?= money($p2) ?>, этап 3 — <?= money($p3) ?>.</p>

    <table class="table">
        <thead><tr>
            <th>Специалист</th>
            <th class="num">Этап 1</th><th class="num">Этап 2 (авто)</th><th class="num">Этап 3</th>
            <th class="num">Сумма за день</th><th>Статус</th><th>Действие</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $sum = $r['s1']*$p1 + $r['s2']*$p2 + $r['s3']*$p3; $f = 'ts'.(int)$r['id']; ?>
            <tr class="<?= $r['accepted'] ? 'row-ok' : '' ?>">
                <td><?= e($r['name']) ?></td>
                <td class="num"><input form="<?= $f ?>" type="number" name="s1" min="0" value="<?= (int)$r['s1'] ?>" style="width:72px;text-align:right"></td>
                <td class="num muted"><?= (int)$r['s2'] ?></td>
                <td class="num"><input form="<?= $f ?>" type="number" name="s3" min="0" value="<?= (int)$r['s3'] ?>" style="width:72px;text-align:right"></td>
                <td class="num" style="white-space:nowrap"><?= money($sum) ?></td>
                <td>
                    <?php if ($r['accepted']): ?>
                        <span class="tag ok" title="<?= e(($r['accepted_by_name'] ? $r['accepted_by_name'].', ' : '').substr((string)$r['accepted_at'],0,16)) ?>">✓ принято</span>
                    <?php elseif ($r['has13'] > 0): ?>
                        <span class="tag off">⏳ не принято</span>
                    <?php else: ?>
                        <span class="muted">нет 1/3</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <button form="<?= $f ?>" class="btn btn-mini btn-primary">💾 Принять</button>
                    <?php if ($r['accepted']): ?>
                        <button form="<?= $f ?>r" class="btn btn-mini btn-danger" onclick="return confirm('Снять акцепт? Этапы 1 и 3 перестанут идти в расчёт.')">Снять</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Специалистов виз нет.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php foreach ($rows as $r): $f = 'ts'.(int)$r['id']; ?>
        <form id="<?= $f ?>" method="post" action="/visas/timesheet/save" style="display:none">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <input type="hidden" name="employee_id" value="<?= (int)$r['id'] ?>">
        </form>
        <?php if ($r['accepted']): ?>
        <form id="<?= $f ?>r" method="post" action="/visas/timesheet/revoke" style="display:none">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <input type="hidden" name="employee_id" value="<?= (int)$r['id'] ?>">
        </form>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
