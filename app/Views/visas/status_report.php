<?php
// Сводная таблица всех визовых анкет со статусом (для отслеживания) + выгрузка в Excel.
$qs = http_build_query(array_filter([
    'status'   => $filters['status'] ?? '',
    'batch_id' => $filters['batch_id'] ?? '',
    'country'  => $filters['country'] ?? '',
    'q'        => $filters['q'] ?? '',
], fn($v) => $v !== ''));
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;font-size:1.2rem">Сводный отчёт по визовым анкетам</h1>
    <a class="btn btn-primary" href="/visas/report/status/export<?= $qs ? '?' . e($qs) : '' ?>">⬇ Выгрузить в Excel</a>
</div>

<section class="panel">
    <form method="get" action="/visas/report/status" class="xfer-controls" style="flex-wrap:wrap;gap:10px;align-items:flex-end">
        <label>Статус
            <select name="status">
                <option value="">— все —</option>
                <?php foreach ($statusLabels as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Партия (загрузка)
            <select name="batch_id">
                <option value="">— все —</option>
                <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= (string)($filters['batch_id'] ?? '') === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Страна
            <select name="country">
                <option value="">— все —</option>
                <?php foreach ($countries as $c => $n): ?>
                    <option value="<?= e($c) ?>" <?= ($filters['country'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?> (<?= (int)$n ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Поиск (№/фамилия)<input type="text" name="q" value="<?= e($filters['q'] ?? '') ?>"></label>
        <button class="btn">Показать</button>
        <a class="btn btn-mini" href="/visas/report/status">Сброс</a>
    </form>
</section>

<section class="panel">
    <?php if ($truncated): ?>
        <p class="flash flash-error" style="margin-top:0">Показаны первые <?= (int)$maxRows ?> строк. Уточните фильтры или выгрузите полный список в Excel.</p>
    <?php endif; ?>
    <table class="table">
        <thead><tr>
            <th>Исх. №</th><th>Страна</th><th>Фамилия</th><th>Статус</th><th>Партия</th>
            <th>Кто проверяет</th><th>Опись</th><th>Указание</th><th>Повтор</th><th>Проверено</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $st = $r['status']; ?>
            <tr>
                <td class="mono"><?= e($r['out_no'] ?: ('#' . $r['id'])) ?></td>
                <td class="muted"><?= e($r['citizenship']) ?></td>
                <td><?= e(trim($r['surname_lat'] . ' ' . $r['names_lat'])) ?></td>
                <td>
                    <?php $cls = $st === 'instructed' ? 'background:#27ae60;color:#fff' : ($st === 'rework' ? 'background:#c0392b;color:#fff' : ''); ?>
                    <span class="tag<?= $cls ? '' : ' off' ?>" style="<?= $cls ?>"><?= e($statusLabels[$st] ?? $st) ?></span>
                </td>
                <td class="muted"><?= e($r['batch_name'] ?? '') ?></td>
                <td><?= e($r['checker'] ?: '—') ?></td>
                <td><?= $r['opis_id'] ? '<a href="/visas/opis/' . (int)$r['opis_id'] . '">#' . (int)$r['opis_id'] . '</a>' : '<span class="muted">—</span>' ?></td>
                <td class="muted"><?= $r['instruction_no'] ? e($r['instruction_no'] . ' от ' . $r['instruction_date']) : '—' ?></td>
                <td class="num"><?= ((int)$r['recheck'] || (int)$r['rework_count'] > 0) ? '<span class="tag off" title="' . e((string)$r['mid_refuse_note']) . '">🔁</span>' : '' ?></td>
                <td class="muted"><?= e(substr((string)$r['checked_at'], 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="muted">Анкет по заданным условиям нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
