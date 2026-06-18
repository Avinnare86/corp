<h1>Импорт сотрудников из штатки</h1>
<p><a class="btn btn-mini" href="/admin/employees">← К сотрудникам</a></p>

<div class="cards">
    <div class="card"><div class="card-label">В файле</div><div class="card-value big"><?= (int)$total ?></div></div>
    <div class="card"><div class="card-label">Создано</div><div class="card-value big"><?= count($report['created']) ?></div></div>
    <div class="card"><div class="card-label">Уже были (пропущено)</div><div class="card-value big"><?= count($report['existing']) ?></div></div>
    <div class="card"><div class="card-label">Новых отделов / должностей</div><div class="card-value"><?= (int)$report['depts'] ?> / <?= (int)$report['positions'] ?></div></div>
</div>

<?php if ($report['creds']): ?>
<section class="panel" style="border-left:4px solid #1e7e34">
    <h2>Созданные учётные записи — логины и временные пароли</h2>
    <p class="flash flash-info">⚠ Пароли показываются <strong>один раз</strong>. Сохраните/раздайте их сотрудникам — при первом входе система потребует сменить пароль. После ухода со страницы пароли восстановить нельзя.</p>
    <table class="table">
        <thead><tr><th>ФИО</th><th>Логин</th><th>Временный пароль</th></tr></thead>
        <tbody>
        <?php foreach ($report['creds'] as $c): ?>
            <tr><td><?= e($c['fio']) ?></td><td class="mono"><?= e($c['login']) ?></td><td class="mono"><?= e($c['password']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if ($report['existing']): ?>
<section class="panel">
    <h2>Пропущены — уже есть в системе (<?= count($report['existing']) ?>)</h2>
    <p class="muted">Эти сотрудники не дублировались (совпадение по ФИО):</p>
    <ul style="columns:2">
        <?php foreach ($report['existing'] as $fio): ?><li><?= e($fio) ?></li><?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!$report['created'] && !$report['existing']): ?>
<section class="panel"><p class="muted">Ничего не импортировано.</p></section>
<?php endif; ?>
