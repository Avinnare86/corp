<h1>Подразделения и подчинённость</h1>
<?php include __DIR__ . '/../partials/org_nav.php'; ?>
<?php
$kinds = \App\Controllers\OrgController::KINDS;
$kindLabel = [
    'дирекция'    => 'Дирекция',
    'заместитель' => 'Заместитель директора',
    'советник'    => 'Советник',
    'управление'  => 'Управление',
    'центр'       => 'Центр',
    'отдел'       => 'Отдел',
];
$personKinds = \App\Controllers\OrgController::PERSON_KINDS;
?>

<section class="panel">
    <h2>Создать узел</h2>
    <form method="post" action="/admin/org/dept" class="form-inline" style="align-items:flex-end">
        <?= csrf_field() ?>
        <label>Название<input type="text" name="name" placeholder="Отдел / Центр / Зам. директора …" required style="min-width:240px"></label>
        <label>Вид узла
            <select name="kind"><?php foreach ($kinds as $k): ?><option value="<?= $k ?>"><?= e($kindLabel[$k] ?? $k) ?></option><?php endforeach; ?></select>
        </label>
        <label>Подчинён (родитель)
            <select name="parent_id">
                <option value="">— директору (верхний уровень) —</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e(($kindLabel[$d['kind']]??$d['kind']).': '.$d['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Руководитель / должностное лицо
            <select name="head_id"><option value="">— не назначен —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Создать</button>
    </form>
    <p class="muted">Узел может быть <strong>подразделением</strong> (отдел/центр/управление) или <strong>должностным лицом</strong>
        (заместитель директора, советник) — у последнего «Руководитель» = сам зам/советник, а в подчинение ему ставятся подразделения.
        Подчинённость: отдел → центр → зам директора → директор. Верхний уровень (родитель пуст) = в подчинении директора.</p>
</section>

<section class="panel">
    <h2>Все узлы (<?= count($departments) ?>)</h2>
    <?php foreach ($departments as $d): ?>
        <div class="panel" style="padding:12px;margin-bottom:8px">
            <form method="post" action="/admin/org/dept" class="form-inline" style="align-items:flex-end">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <label>Название<input type="text" name="name" value="<?= e($d['name']) ?>" style="min-width:200px"></label>
                <label>Вид узла
                    <select name="kind"><?php foreach ($kinds as $k): ?><option value="<?= $k ?>" <?= ($d['kind']??'отдел')===$k?'selected':'' ?>><?= e($kindLabel[$k] ?? $k) ?></option><?php endforeach; ?></select>
                </label>
                <label>Подчинён
                    <select name="parent_id">
                        <option value="">— директору —</option>
                        <?php foreach ($departments as $p): if ((int)$p['id']===(int)$d['id']) continue; ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)($d['parent_id']??0)===(int)$p['id']?'selected':'' ?>><?= e(($kindLabel[$p['kind']]??$p['kind']).': '.$p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?= in_array($d['kind']??'', $personKinds, true) ? 'Должностное лицо' : 'Начальник' ?>
                    <select name="head_id"><option value="">— нет —</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)($d['head_id']??0)===(int)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <span class="muted">сотр.: <?= (int)$d['members'] ?></span>
                <button class="btn btn-mini btn-primary">Сохранить</button>
            </form>
            <div class="form-inline" style="margin-top:6px;align-items:flex-end">
                <form method="post" action="/admin/org/curator" class="form-inline" style="align-items:flex-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>">
                    <label>Курирующий зам (маршрут служебки)
                        <select name="curator_id"><option value="">— не назначен —</option>
                            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)($d['curator_id']??0)===(int)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-mini">Сохранить куратора</button>
                </form>
                <span class="muted" style="font-size:.78rem"><?= $d['parent_name'] ? 'входит в: '.e($d['parent_name']) : 'в подчинении директора' ?></span>
                <span style="flex:1"></span>
                <form method="post" action="/admin/org/dept/<?= (int)$d['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить узел? Сотрудники и дочерние узлы открепятся.')">
                    <?= csrf_field() ?><button class="btn btn-mini btn-danger">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>
