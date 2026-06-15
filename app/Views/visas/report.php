<h1>Отчёт по визам</h1>

<div class="cards">
    <div class="card"><div class="card-label">Всего строк</div><div class="card-value big"><?= (int)$overall['total'] ?></div></div>
    <div class="card"><div class="card-label">Проверено</div><div class="card-value big"><?= (int)$overall['checked'] ?></div>
        <div class="muted"><?= (int)$overall['total']>0 ? round((int)$overall['checked']/(int)$overall['total']*100) : 0 ?>%</div></div>
    <div class="card"><div class="card-label">Не распределено</div><div class="card-value big"><?= (int)$overall['unassigned'] ?></div></div>
</div>

<section class="panel">
    <h2>По специалистам</h2>
    <table class="table">
        <thead><tr><th>Специалист</th><th class="num">Назначено</th><th class="num">Проверено</th><th class="num">Остаток</th><th class="num">Доработки</th><th class="num">Прогресс</th></tr></thead>
        <tbody>
        <?php foreach ($byEmployee as $e): $a=(int)$e['assigned']; $c=(int)$e['checked']; ?>
            <tr>
                <td><?= e($e['full_name']) ?></td>
                <td class="num"><?= $a ?></td>
                <td class="num"><?= $c ?></td>
                <td class="num"><?= $a-$c ?></td>
                <td class="num<?= (int)$e['reworks']?' minus':'' ?>"><?= (int)$e['reworks'] ?></td>
                <td class="num"><?= $a>0?round($c/$a*100):0 ?>%</td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byEmployee): ?><tr><td colspan="6" class="muted">Назначений нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>По партиям</h2>
    <table class="table">
        <thead><tr><th>Партия</th><th class="num">Всего</th><th class="num">Проверено</th><th class="num">Не распред.</th><th class="num">Прогресс</th></tr></thead>
        <tbody>
        <?php foreach ($byBatch as $b): $t=(int)$b['total']; $c=(int)$b['checked']; ?>
            <tr>
                <td><?= e($b['name']) ?></td>
                <td class="num"><?= $t ?></td>
                <td class="num"><?= $c ?></td>
                <td class="num"><?= (int)$b['unassigned'] ?></td>
                <td class="num"><?= $t>0?round($c/$t*100):0 ?>%</td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byBatch): ?><tr><td colspan="5" class="muted">Партий нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
