<?php /** @var array $departments limits groups members employees csrf */ ?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign">← к кампании</a> &nbsp; Контроль наложения отпусков:
    лимит одновременно отдыхающих по отделу и именные группы «не более N из набора». Условия действуют совместно — период
    нельзя записать, если он нарушает хотя бы одно правило.</p>

<form method="post" action="/vacation-campaign/save-dept-limits">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <section class="panel">
        <h2 style="margin-top:0">Лимит одновременных отпусков по отделам</h2>
        <p class="muted" style="margin-top:0">0 — без ограничения по отделу.</p>
        <table class="table tbl-cards">
            <thead><tr><th>Отдел</th><th>Максимум одновременно</th></tr></thead>
            <tbody>
            <?php foreach ($departments as $d): $id = (int) $d['id']; ?>
                <tr>
                    <td data-label="Отдел"><?= e($d['name']) ?></td>
                    <td data-label="Максимум"><input type="number" name="limit[<?= $id ?>]" min="0" max="99" value="<?= (int) ($limits[$id] ?? 0) ?>" style="width:80px"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить лимиты</button>
    </section>
</form>

<section class="panel">
    <h2 style="margin-top:0">Группы непересечения</h2>
    <form method="post" action="/vacation-campaign/groups" class="grid-form" style="margin-bottom:12px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Название группы <input type="text" name="name" maxlength="150" placeholder="напр. Ключевые специалисты" required></label>
        <label>Не более одновременно <input type="number" name="max" min="1" max="99" value="1" style="width:80px"></label>
        <button class="btn btn-primary">Добавить группу</button>
    </form>

    <?php foreach ($groups as $g): $gid = (int) $g['id']; $gm = $members[$gid] ?? []; ?>
        <div class="panel" style="background:#fafafa">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <strong><?= e($g['name']) ?></strong>
                <span class="tag">не более <?= (int) $g['max_simultaneous'] ?> одновременно</span>
                <form method="post" action="/vacation-campaign/groups/<?= $gid ?>/delete" onsubmit="return confirm('Удалить группу?')" style="margin-left:auto">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="btn btn-mini btn-danger">Удалить группу</button>
                </form>
            </div>
            <div style="margin:8px 0;display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach ($gm as $m): ?>
                    <span class="tag" style="display:inline-flex;align-items:center;gap:4px"><?= e($m['full_name']) ?>
                        <form method="post" action="/vacation-campaign/groups/<?= $gid ?>/members/<?= (int) $m['employee_id'] ?>/remove" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button class="btn-x" title="Убрать">×</button>
                        </form>
                    </span>
                <?php endforeach; ?>
                <?php if (!$gm): ?><span class="muted">участники не добавлены</span><?php endif; ?>
            </div>
            <form method="post" action="/vacation-campaign/groups/<?= $gid ?>/members" style="display:flex;gap:6px;flex-wrap:wrap">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <select name="employee_id" required>
                    <option value="">— добавить сотрудника —</option>
                    <?php foreach ($employees as $em): ?>
                        <option value="<?= (int) $em['id'] ?>"><?= e($em['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-mini">Добавить</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$groups): ?><p class="muted">Групп пока нет.</p><?php endif; ?>
</section>

<style>.btn-x{border:none;background:transparent;color:#b00;cursor:pointer;font-size:1rem;line-height:1;padding:0 2px}</style>
