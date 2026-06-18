<?php
// Сводная таблица всех визовых анкет со статусом (для отслеживания) + выгрузка в Excel.
$qf = array_filter([
    'status'   => $filters['status'] ?? '',
    'batch_id' => $filters['batch_id'] ?? '',
    'country'  => $filters['country'] ?? '',
    'q'        => $filters['q'] ?? '',
    'from'     => $filters['from'] ?? '',
    'to'       => $filters['to'] ?? '',
    'checker'  => $filters['checker'] ?? '',
], fn($v) => $v !== '');
$qs = http_build_query($qf);
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
        <label>Кто проверяет
            <select name="checker">
                <option value="">— все —</option>
                <?php foreach ($checkers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)($filters['checker'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Загружены с<input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
        <label>по<input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
        <label>Поиск (№/фамилия)<input type="text" name="q" value="<?= e($filters['q'] ?? '') ?>"></label>
        <button class="btn">Показать</button>
        <a class="btn btn-mini" href="/visas/report/status">Сброс</a>
    </form>
</section>

<section class="panel">
    <?php $pageQs = $qs ? '&' . e($qs) : ''; ?>
    <div class="form-inline" style="margin-bottom:8px;gap:10px;flex-wrap:wrap">
        <span class="muted">Всего по фильтру: <strong><?= (int)$total ?></strong></span>
        <?php if (!$all && $pages > 1): ?>
            <?php if ($page > 1): ?><a class="btn btn-mini" href="/visas/report/status?page=<?= $page-1 . $pageQs ?>">← Назад</a><?php endif; ?>
            <span class="muted">стр. <?= (int)$page ?> из <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-mini" href="/visas/report/status?page=<?= $page+1 . $pageQs ?>">Вперёд →</a><?php endif; ?>
            <a class="btn btn-mini" href="/visas/report/status?all=1<?= $pageQs ?>">Показать все</a>
        <?php elseif ($all && $total > $perPage): ?>
            <a class="btn btn-mini" href="/visas/report/status?page=1<?= $pageQs ?>">← По страницам (<?= (int)$perPage ?>)</a>
        <?php endif; ?>
    </div>
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
