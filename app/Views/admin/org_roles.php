<h1>Роли и замещение</h1>
<?php include __DIR__ . '/../partials/org_nav.php'; ?>

<section class="panel">
    <p class="muted" style="margin-top:0">У сотрудника может быть <strong>набор ролей</strong>. Доступы и пункты меню открываются <strong>по ролям</strong> — отдельных «проектов» больше нет.</p>
    <form method="get" action="/admin/org/roles" class="form-inline">
        <label>Поиск сотрудника<input type="text" name="q" value="<?= e($q) ?>" placeholder="ФИО"></label>
        <button class="btn">Найти</button>
        <?php if ($q): ?><a class="btn" href="/admin/org/roles">сброс</a><?php endif; ?>
        <span class="muted">найдено: <?= count($usersFull) ?></span>
    </form>
</section>

<?php
$roleGroups = [
    'Руководство и финансы' => ['director','deputy_director','dept_head','accountant','hr','timekeeper'],
    'Менеджеры проектов'    => ['anketa_manager','visa_manager','controller'],
    'Специалисты (сделка)'  => ['anketa_worker','visa_worker','piecework_worker'],
    'Визы — учёт и МИД'     => ['visa_mid'],
];
$roleName = [];
foreach ($rolesCatalog as $rc) { $roleName[$rc['slug']] = $rc['name']; }
?>
<section class="panel">
<?php foreach ($usersFull as $u): $up = $userProjects[$u['id']] ?? []; $ur = $userRoles[$u['id']] ?? []; ?>
    <details class="panel" style="padding:10px 12px;margin-bottom:8px"<?= $q !== '' ? ' open' : '' ?>>
        <summary style="cursor:pointer">
            <strong><?= e($u['full_name']) ?></strong>
            <span class="muted" style="font-size:.78rem"><?= e($u['dept_name'] ?? 'вне подразделений') ?> · <?= e($u['position']) ?></span>
            <?php if ($ur): ?><span class="muted" style="font-size:.74rem"> — роли: <?= e(implode(', ', array_map(fn($s)=>$roleName[$s]??$s, $ur))) ?></span><?php endif; ?>
        </summary>
        <form method="post" action="/admin/org/access" style="margin-top:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <div class="role-cols">
                <?php foreach ($roleGroups as $gname => $slugs): ?>
                    <fieldset class="role-group"><legend><?= e($gname) ?></legend>
                        <?php foreach ($slugs as $slug): if (!isset($roleName[$slug])) continue; ?>
                            <label class="chk"><input type="checkbox" name="roles[]" value="<?= e($slug) ?>" <?= in_array($slug,$ur,true)?'checked':'' ?>> <?= e($roleName[$slug]) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="form-inline" style="margin-top:8px;align-items:flex-end">
                <label>Табельщик отдела
                    <select name="timekeeper_dept_id">
                        <option value="">— не табельщик отдела —</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= (int)($u['timekeeper_dept_id']??0)===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-mini btn-primary">Сохранить роли</button>
            </div>
        </form>
    </details>
<?php endforeach; ?>
<?php if (!$usersFull): ?><p class="muted">Никого не найдено.</p><?php endif; ?>
</section>
