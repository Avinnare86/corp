<?php
$isFact = $mode === 'fact';
$wd = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
$link = fn($rg, $md) => '/shifts/edit?employee=' . (int)$emp['id'] . '&month=' . e($month) . '&range=' . $rg . '&mode=' . $md;
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/shifts?month=<?= e($month) ?>">← К списку</a>
    <h1 style="margin:0;font-size:1.15rem">График: <?= e($emp['full_name']) ?></h1>
    <span class="muted"><?= e($emp['dept_name'] ?? '—') ?> · ставка <?= money($rate) ?>/ч</span>
</div>

<section class="panel">
    <div class="form-inline" style="gap:14px;flex-wrap:wrap;margin-bottom:6px">
        <span>Режим:
            <a class="btn btn-mini <?= $isFact ? '' : 'btn-primary' ?>" href="<?= $link($range,'plan') ?>">План</a>
            <a class="btn btn-mini <?= $isFact ? 'btn-primary' : '' ?>" href="<?= $link($range,'fact') ?>">Факт</a>
        </span>
        <span>Период:
            <a class="btn btn-mini <?= $range==='h1'?'btn-primary':'' ?>" href="<?= $link('h1',$mode) ?>">1–15</a>
            <a class="btn btn-mini <?= $range==='h2'?'btn-primary':'' ?>" href="<?= $link('h2',$mode) ?>">16–конец</a>
            <a class="btn btn-mini <?= $range==='full'?'btn-primary':'' ?>" href="<?= $link('full',$mode) ?>">весь месяц</a>
        </span>
        <span class="muted"><?= e($month) ?></span>
    </div>
    <p class="muted" style="margin:0 0 8px">
        <?= $isFact
            ? 'Факт: отработано часов за день, из них ночных; праздничные и сверхурочные часы — по ТК (оплата выше).'
            : 'План: запланировано часов в смену, из них ночных (с 22:00 до 06:00). Незаполненные/нулевые дни — выходные.' ?>
    </p>

    <form method="post" action="/shifts/save">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="employee" value="<?= (int)$emp['id'] ?>">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <input type="hidden" name="range" value="<?= e($range) ?>">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <table class="table">
            <thead><tr>
                <th>Дата</th><th>День</th>
                <th class="num">Часы</th><th class="num">Ночные</th>
                <?php if ($isFact): ?><th class="num">Праздничные</th><th class="num">Сверхуроч.</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($dates as $dt): $row = $existing[$dt] ?? null;
                $hours = $isFact ? ($row['fact_hours'] ?? '') : ($row['plan_hours'] ?? '');
                $night = $isFact ? ($row['fact_night'] ?? '') : ($row['plan_night'] ?? '');
                $dow = (int) date('w', strtotime($dt));
                $we = ($dow === 0 || $dow === 6);
                $f = fn($v) => ($v === '' || (float)$v == 0) ? '' : rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');
            ?>
                <tr<?= $we ? ' style="background:#faf6ee"' : '' ?>>
                    <td class="mono"><?= e(date('d.m', strtotime($dt))) ?></td>
                    <td class="muted"><?= $wd[$dow] ?></td>
                    <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][hours]" value="<?= e($f($hours)) ?>" style="width:72px;text-align:right"></td>
                    <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][night]" value="<?= e($f($night)) ?>" style="width:72px;text-align:right"></td>
                    <?php if ($isFact): ?>
                        <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][holiday]" value="<?= e($f($row['holiday_hours'] ?? '')) ?>" style="width:72px;text-align:right"></td>
                        <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][overtime]" value="<?= e($f($row['overtime_hours'] ?? '')) ?>" style="width:72px;text-align:right"></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="form-inline" style="margin-top:12px">
            <button class="btn btn-primary">💾 Сохранить <?= $isFact ? 'факт' : 'план' ?></button>
            <a class="btn" href="/shifts?month=<?= e($month) ?>">Готово</a>
        </div>
    </form>
</section>
