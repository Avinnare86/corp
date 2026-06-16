<?php
// Сводная по стимулу: кто кому что назначил по месяцам + корректировки (снижение/отмена) вышестоящим.
$qs = $period !== '' ? '?period=' . rawurlencode($period) : '';
$tierBadge = ['director' => 'директор', 'deputy' => 'зам'];
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <h1 style="margin:0;font-size:1.2rem">Сводная по стимулу</h1>
    <a class="btn btn-primary" href="/memos/summary/export<?= e($qs) ?>">⬇ Выгрузить в Excel</a>
</div>

<section class="panel">
    <form method="get" action="/memos/summary" class="form-inline" style="gap:10px;align-items:flex-end">
        <label>Месяц
            <select name="period" onchange="this.form.submit()">
                <option value="">— все месяцы —</option>
                <?php foreach ($periods as $p): ?>
                    <option value="<?= e($p) ?>" <?= $period === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <span class="muted">Видны назначения ваших подчинённых (директор/бухгалтерия — все). Снизить/отменить можно только утверждённый стимул.</span>
    </form>
</section>

<section class="panel">
    <table class="table">
        <thead><tr>
            <th>Месяц</th><th>Отдел</th><th>Получатель</th><th>Вид</th>
            <th class="num">Назначено</th><th class="num">Итог</th><th>Назначил</th><th>Статус</th><th>№</th><th>Корректировка</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $reduced = $r['ov_amount'] !== null; ?>
            <tr>
                <td class="mono"><?= e($r['period']) ?></td>
                <td class="muted"><?= e($r['dept_name'] ?? '—') ?></td>
                <td><?= e($r['recipient']) ?></td>
                <td class="muted"><?= $r['pay_kind'] === 'onetime' ? 'единоврем.' : 'ежемес.' ?></td>
                <td class="num"><?= money($r['amount']) ?></td>
                <td class="num">
                    <strong><?= money($r['effective']) ?></strong>
                    <?php if ($reduced): ?><br><span class="minus" style="font-size:.74rem" title="<?= e($r['ov_reason'] ?? '') ?>"><?= (float)$r['ov_amount'] == 0.0 ? 'отменён' : 'снижен' ?>: <?= e($r['ov_by'] ?? '') ?></span><?php endif; ?>
                </td>
                <td class="muted"><?= e($r['author_name']) ?><?= $r['direct_tier'] ? ' <span class="tag off">прямое: ' . e($tierBadge[$r['direct_tier']] ?? $r['direct_tier']) . '</span>' : '' ?></td>
                <td><span class="tag <?= $r['status'] === 'approved' ? '' : 'off' ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
                <td class="mono"><?= e($r['number'] ?: ('#' . $r['line_id'])) ?></td>
                <td>
                    <?php if (!empty($r['can_override'])): ?>
                    <form method="post" action="/memos/line/<?= (int)$r['line_id'] ?>/override" class="form-inline" style="gap:5px;flex-wrap:wrap;align-items:center"
                          onsubmit="return this.reason.value.trim()!=='' || (alert('Укажите причину'),false)">
                        <?= csrf_field() ?>
                        <input type="text" name="new_amount" placeholder="новая ₽" style="width:90px;text-align:right" value="<?= e(number_format((float)$r['effective'], 2, '.', '')) ?>">
                        <input type="text" name="reason" placeholder="причина" style="width:150px">
                        <button class="btn btn-mini" title="Снизить до указанной суммы">снизить</button>
                        <button class="btn btn-mini btn-danger" name="cancel" value="1" title="Отменить стимул (0)"
                                onclick="return this.form.reason.value.trim()!=='' || (alert('Укажите причину'),false)">отменить</button>
                    </form>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="muted">Назначений по заданным условиям нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
