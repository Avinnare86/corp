<?php
function pct($checked, $total){ $total=(int)$total; return $total>0 ? round((int)$checked/$total*100) : 0; }
?>
<div class="chat-head">
    <h1 style="margin:0">Отчёт по проверке</h1>
    <a class="btn btn-gold" href="/manager/report/export?from=<?= e($from ?? '') ?>&to=<?= e($to ?? '') ?>">📊 Выгрузить в Excel</a>
</div>

<form method="get" action="/manager/report" class="form-inline" style="margin-bottom:12px">
    <label>С<br><input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
    <label>По<br><input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
    <button class="btn primary" type="submit">Показать</button>
    <?php if (($from ?? '') !== '' || ($to ?? '') !== ''): ?>
        <a class="btn" href="/manager/report">Сбросить</a>
    <?php endif; ?>
    <span class="muted" style="align-self:center">Фильтр — по дате поступления анкет (загрузки/назначения). Пусто — за всё время.</span>
</form>

<?php $oTotal=(int)$overall['total']; $oChecked=(int)$overall['checked']; $oUn=(int)$overall['unassigned']; ?>
<div class="cards">
    <div class="card"><div class="card-label">Всего досье</div><div class="card-value big"><?= $oTotal ?></div></div>
    <div class="card"><div class="card-label">Проверено</div><div class="card-value big"><?= $oChecked ?> <span class="muted" style="font-size:1rem"><?= pct($oChecked,$oTotal) ?>%</span></div></div>
    <div class="card"><div class="card-label">Не распределено</div><div class="card-value big"><?= $oUn ?></div></div>
    <div class="card"><div class="card-label">Осталось проверить</div><div class="card-value big"><?= $oTotal - $oChecked ?></div></div>
</div>

<section class="panel">
    <h2>По сотрудникам</h2>
    <table class="table">
        <thead><tr><th>Сотрудник</th><th class="num">Назначено</th><th class="num">Проверено</th><th class="num">Остаток</th><th style="width:240px">Прогресс</th></tr></thead>
        <tbody>
        <?php foreach ($byEmployee as $e): $p=pct($e['checked'],$e['assigned']); ?>
            <tr>
                <td><?= e($e['full_name']) ?></td>
                <td class="num"><?= (int)$e['assigned'] ?></td>
                <td class="num"><?= (int)$e['checked'] ?></td>
                <td class="num"><?= (int)$e['assigned']-(int)$e['checked'] ?></td>
                <td><div class="bar"><div class="bar-fill" style="width:<?= $p ?>%"></div><span class="bar-label"><?= $p ?>%</span></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byEmployee): ?><tr><td colspan="5" class="muted">Назначений нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>По спискам</h2>
    <table class="table">
        <thead><tr><th>Список</th><th class="num">Всего</th><th class="num">Проверено</th><th class="num">Не распред.</th><th style="width:240px">Прогресс</th></tr></thead>
        <tbody>
        <?php foreach ($byList as $l): $p=pct($l['checked'],$l['total']); ?>
            <tr>
                <td><?= e($l['name']) ?></td>
                <td class="num"><?= (int)$l['total'] ?></td>
                <td class="num"><?= (int)$l['checked'] ?></td>
                <td class="num"><?= (int)$l['unassigned'] ?></td>
                <td><div class="bar"><div class="bar-fill" style="width:<?= $p ?>%"></div><span class="bar-label"><?= $p ?>%</span></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byList): ?><tr><td colspan="5" class="muted">Списков нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
