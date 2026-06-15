<?php use App\Controllers\NomenclatureController; $st = NomenclatureController::STATUS; ?>
<div class="chat-head">
    <h1 style="margin:0;font-size:1.2rem">Номенклатура дел</h1>
    <a class="btn" href="/nomenclature/archive">🗄 Архив дел</a>
    <form method="get" action="/nomenclature" style="margin-left:auto">
        <label>Год
            <select name="year" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
                <?php if (!in_array($year,$years,true)): ?><option value="<?= $year ?>" selected><?= $year ?></option><?php endif; ?>
            </select>
        </label>
    </form>
</div>

<?php if ($canManage): ?>
<section class="panel">
    <h2>Добавить дело</h2>
    <form method="post" action="/nomenclature" class="form-inline" style="align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="year" value="<?= (int)$year ?>">
        <label>Индекс<input type="text" name="index_code" placeholder="01-15" style="width:90px" required></label>
        <label class="grow">Заголовок дела<input type="text" name="title" required></label>
        <label>Подразделение
            <select name="department_id"><option value="">— общее —</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Срок хранения (текст)<input type="text" name="storage_term" placeholder="5 лет / постоянно" style="width:130px"></label>
        <label>лет (число)<input type="number" name="storage_years" placeholder="5" style="width:80px" title="пусто = постоянно"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <p class="muted">«Лет (число)» — для авторасчёта года уничтожения при закрытии дела. Пусто = постоянное хранение.</p>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Дела <?= (int)$year ?> года</h2>
    <table class="table">
        <thead><tr><th>Индекс</th><th>Заголовок</th><th>Подразделение</th><th>Срок хранения</th><th class="num">Док-в</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cases as $c): ?>
            <tr>
                <td class="mono"><a href="/nomenclature/<?= (int)$c['id'] ?>"><?= e($c['index_code']) ?></a></td>
                <td><?= e($c['title']) ?></td>
                <td class="muted"><?= e($c['dept_name'] ?: 'общее') ?></td>
                <td><?= e($c['storage_term']) ?></td>
                <td class="num"><?= (int)$c['docs'] ?></td>
                <td><span class="st <?= $c['status']==='open'?'st-ok':'st-wait' ?>"><?= e($st[$c['status']] ?? $c['status']) ?></span></td>
                <td><a class="btn btn-mini" href="/nomenclature/<?= (int)$c['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$cases): ?><tr><td colspan="7" class="muted">Дел за этот год нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
