<h1>Отчёт по визам</h1>

<?php
    $today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day')); $d3 = date('Y-m-d', strtotime('-2 days'));
    $f = $from ?? ''; $t = $to ?? ''; $active = $f !== '' || $t !== '';
    $isToday = $f === $today && $t === $today;
    $isYest  = $f === $yest  && $t === $yest;
    $is3     = $f === $d3    && $t === $today;
?>
<form method="get" action="/visas/report" class="form-inline" style="margin:8px 0 14px;align-items:flex-end">
    <a class="btn <?= !$active ? 'btn-primary' : '' ?>" href="/visas/report">Весь период</a>
    <a class="btn <?= $isToday ? 'btn-primary' : '' ?>" href="/visas/report?from=<?= $today ?>&to=<?= $today ?>">Сегодня</a>
    <a class="btn <?= $isYest ? 'btn-primary' : '' ?>" href="/visas/report?from=<?= $yest ?>&to=<?= $yest ?>">Вчера</a>
    <a class="btn <?= $is3 ? 'btn-primary' : '' ?>" href="/visas/report?from=<?= $d3 ?>&to=<?= $today ?>">3 дня</a>
    <label style="margin-left:8px">С<br><input type="date" name="from" value="<?= e($f) ?>"></label>
    <label>По<br><input type="date" name="to" value="<?= e($t) ?>"></label>
    <button class="btn primary" type="submit">Показать</button>
    <span class="muted" style="align-self:center">Дата влияет только на «<strong>проверено за период</strong>» (по дате проверки). «Назначено / остаток» — всего, включая прошлые дни. По умолчанию — весь период.</span>
</form>

<?php $pct = fn($c,$t)=> (int)$t>0 ? round((int)$c/(int)$t*100) : 0;
      $vT=(int)$overall['total']; $vCT=(int)$overall['checked_total']; $vCP=(int)$overall['checked_period']; $vU=(int)$overall['unassigned']; ?>
<div class="cards">
    <div class="card"><div class="card-label">Всего строк</div><div class="card-value big"><?= $vT ?></div></div>
    <div class="card"<?= $active?' style="border:2px solid var(--primary)"':'' ?>><div class="card-label"><?= $active?'✅ Проверено за период':'Проверено' ?></div><div class="card-value big"><?= $active?$vCP:$vCT ?></div>
        <div class="muted"><?= $pct($active?$vCP:$vCT,$vT) ?>%</div></div>
    <?php if ($active): ?><div class="card"><div class="card-label">Всего проверено</div><div class="card-value big"><?= $vCT ?></div></div><?php endif; ?>
    <div class="card"><div class="card-label">Не распределено</div><div class="card-value big"><?= $vU ?></div></div>
    <div class="card"><div class="card-label">Осталось проверить</div><div class="card-value big"><?= $vT - $vCT ?></div></div>
</div>

<section class="panel">
    <h2>По специалистам</h2>
    <table class="table">
        <thead><tr>
            <th>Специалист</th><th class="num">Назначено</th>
            <?php if ($active): ?><th class="num">Проверено за период</th><?php endif; ?>
            <th class="num"><?= $active?'Всего проверено':'Проверено' ?></th>
            <th class="num">Остаток</th><th class="num">Доработки</th><th class="num">Прогресс</th>
        </tr></thead>
        <tbody>
        <?php $tA=0;$tP=0;$tC=0;$tR=0;$tW=0; foreach ($byEmployee as $e):
            $a=(int)$e['assigned']; $ct=(int)$e['checked_total']; $cp=(int)$e['checked_period']; $rem=$a-$ct; $w=(int)$e['reworks'];
            $tA+=$a;$tP+=$cp;$tC+=$ct;$tR+=$rem;$tW+=$w; ?>
            <tr>
                <td><?= e($e['full_name']) ?></td>
                <td class="num"><?= $a ?></td>
                <?php if ($active): ?><td class="num"><strong><?= $cp ?></strong></td><?php endif; ?>
                <td class="num"><?= $ct ?></td>
                <td class="num"><?= $rem ?></td>
                <td class="num<?= $w?' minus':'' ?>"><?= $w ?></td>
                <td class="num"><?= $pct($ct,$a) ?>%</td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byEmployee): ?><tr><td colspan="<?= $active?7:6 ?>" class="muted">Назначений нет.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($byEmployee): ?>
        <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
            <td>Итого</td><td class="num"><?= $tA ?></td>
            <?php if ($active): ?><td class="num"><?= $tP ?></td><?php endif; ?>
            <td class="num"><?= $tC ?></td><td class="num"><?= $tR ?></td><td class="num"><?= $tW ?></td><td class="num"><?= $pct($tC,$tA) ?>%</td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</section>

<section class="panel">
    <h2>По партиям</h2>
    <table class="table">
        <thead><tr>
            <th>Партия</th><th>Страна</th><th class="num">Всего</th>
            <?php if ($active): ?><th class="num">Проверено за период</th><?php endif; ?>
            <th class="num"><?= $active?'Всего проверено':'Проверено' ?></th>
            <th class="num">Не распред.</th><th class="num">Прогресс</th>
        </tr></thead>
        <tbody>
        <?php $sT=0;$sP=0;$sC=0;$sU=0; foreach ($byBatch as $b):
            $t=(int)$b['total']; $ct=(int)$b['checked_total']; $cp=(int)$b['checked_period']; $u=(int)$b['unassigned'];
            $sT+=$t;$sP+=$cp;$sC+=$ct;$sU+=$u; ?>
            <tr>
                <td><?= e($b['name']) ?></td>
                <td><?= e($b['country'] ?? '') ?: '<span class="muted">—</span>' ?></td>
                <td class="num"><?= $t ?></td>
                <?php if ($active): ?><td class="num"><strong><?= $cp ?></strong></td><?php endif; ?>
                <td class="num"><?= $ct ?></td>
                <td class="num"><?= $u ?></td>
                <td class="num"><?= $pct($ct,$t) ?>%</td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byBatch): ?><tr><td colspan="<?= $active?7:6 ?>" class="muted">Партий нет.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($byBatch): ?>
        <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
            <td>Итого</td><td></td><td class="num"><?= $sT ?></td>
            <?php if ($active): ?><td class="num"><?= $sP ?></td><?php endif; ?>
            <td class="num"><?= $sC ?></td><td class="num"><?= $sU ?></td><td class="num"><?= $pct($sC,$sT) ?>%</td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</section>
