<?php
function pct($checked, $total){ $total=(int)$total; return $total>0 ? round((int)$checked/$total*100) : 0; }
$filt = ($from ?? '') !== '' || ($to ?? '') !== '';
?>
<div class="chat-head">
    <h1 style="margin:0">Отчёт по проверке</h1>
    <a class="btn btn-gold" href="/manager/report/export?from=<?= e($from ?? '') ?>&to=<?= e($to ?? '') ?>">📊 Выгрузить в Excel</a>
</div>

<form method="get" action="/manager/report" class="form-inline" style="margin-bottom:12px">
    <label>С<br><input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
    <label>По<br><input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
    <button class="btn primary" type="submit">Показать</button>
    <?php if ($filt): ?><a class="btn" href="/manager/report">Сбросить</a><?php endif; ?>
    <span class="muted" style="align-self:center">Дата влияет только на «<strong>проверено за период</strong>» (по дате проверки). «Назначено / остаток» — всего, включая прошлые дни.</span>
</form>

<?php $oTotal=(int)$overall['total']; $oCT=(int)$overall['checked_total']; $oCP=(int)$overall['checked_period']; $oUn=(int)$overall['unassigned']; ?>
<div class="cards">
    <div class="card"><div class="card-label">Всего досье</div><div class="card-value big"><?= $oTotal ?></div></div>
    <div class="card"<?= $filt?' style="border:2px solid var(--primary)"':'' ?>><div class="card-label"><?= $filt?'✅ Проверено за период':'Проверено' ?></div><div class="card-value big"><?= $filt?$oCP:$oCT ?> <span class="muted" style="font-size:1rem"><?= pct($filt?$oCP:$oCT,$oTotal) ?>%</span></div></div>
    <?php if ($filt): ?><div class="card"><div class="card-label">Всего проверено</div><div class="card-value big"><?= $oCT ?></div></div><?php endif; ?>
    <div class="card"><div class="card-label">Не распределено</div><div class="card-value big"><?= $oUn ?></div></div>
    <div class="card"><div class="card-label">Осталось проверить</div><div class="card-value big"><?= $oTotal - $oCT ?></div></div>
</div>

<section class="panel">
    <h2>По сотрудникам</h2>
    <table class="table">
        <thead><tr>
            <th>Сотрудник</th><th class="num">Назначено</th>
            <?php if ($filt): ?><th class="num">Проверено за период</th><?php endif; ?>
            <th class="num"><?= $filt?'Всего проверено':'Проверено' ?></th>
            <th class="num">Остаток</th><th style="width:220px">Прогресс</th>
        </tr></thead>
        <tbody>
        <?php $tA=0;$tP=0;$tC=0;$tR=0; foreach ($byEmployee as $e):
            $a=(int)$e['assigned']; $ct=(int)$e['checked_total']; $cp=(int)$e['checked_period']; $rem=$a-$ct; $p=pct($ct,$a);
            $tA+=$a;$tP+=$cp;$tC+=$ct;$tR+=$rem; ?>
            <tr>
                <td><?= e($e['full_name']) ?></td>
                <td class="num"><?= $a ?></td>
                <?php if ($filt): ?><td class="num"><strong><?= $cp ?></strong></td><?php endif; ?>
                <td class="num"><?= $ct ?></td>
                <td class="num"><?= $rem ?></td>
                <td><div class="bar"><div class="bar-fill" style="width:<?= $p ?>%"></div><span class="bar-label"><?= $p ?>%</span></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byEmployee): ?><tr><td colspan="<?= $filt?6:5 ?>" class="muted">Назначений нет.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($byEmployee): ?>
        <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
            <td>Итого</td><td class="num"><?= $tA ?></td>
            <?php if ($filt): ?><td class="num"><?= $tP ?></td><?php endif; ?>
            <td class="num"><?= $tC ?></td><td class="num"><?= $tR ?></td>
            <td class="num"><?= pct($tC,$tA) ?>%</td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</section>

<section class="panel">
    <h2>По спискам</h2>
    <table class="table">
        <thead><tr>
            <th>Список</th><th class="num">Всего</th>
            <?php if ($filt): ?><th class="num">Проверено за период</th><?php endif; ?>
            <th class="num"><?= $filt?'Всего проверено':'Проверено' ?></th>
            <th class="num">Не распред.</th><th style="width:220px">Прогресс</th>
        </tr></thead>
        <tbody>
        <?php $sT=0;$sP=0;$sC=0;$sU=0; foreach ($byList as $l):
            $t=(int)$l['total']; $ct=(int)$l['checked_total']; $cp=(int)$l['checked_period']; $u=(int)$l['unassigned']; $p=pct($ct,$t);
            $sT+=$t;$sP+=$cp;$sC+=$ct;$sU+=$u; ?>
            <tr>
                <td><?= e($l['name']) ?></td>
                <td class="num"><?= $t ?></td>
                <?php if ($filt): ?><td class="num"><strong><?= $cp ?></strong></td><?php endif; ?>
                <td class="num"><?= $ct ?></td>
                <td class="num"><?= $u ?></td>
                <td><div class="bar"><div class="bar-fill" style="width:<?= $p ?>%"></div><span class="bar-label"><?= $p ?>%</span></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byList): ?><tr><td colspan="<?= $filt?6:5 ?>" class="muted">Списков нет.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($byList): ?>
        <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
            <td>Итого</td><td class="num"><?= $sT ?></td>
            <?php if ($filt): ?><td class="num"><?= $sP ?></td><?php endif; ?>
            <td class="num"><?= $sC ?></td><td class="num"><?= $sU ?></td>
            <td class="num"><?= pct($sC,$sT) ?>%</td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</section>
