<?php
$back = '/admin/employees/' . (int)$u['id'];
$roleMap = array_column($rolesCatalog, 'name', 'slug');
$rate = rtrim(rtrim(number_format((float)$u['rate_volume'], 2, '.', ''), '0'), '.');
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/admin/employees">← К списку</a>
    <h1 style="margin:0;font-size:1.2rem"><?= e($u['full_name']) ?></h1>
    <span class="muted mono"><?= e($u['login']) ?></span>
    <?php if (!(int)$u['is_active']): ?><span class="tag off">не активен</span><?php endif; ?>
    <?php if ($u['role']==='admin'): ?><span class="tag">админ</span><?php endif; ?>
    <?php if ($payroll): ?><span style="margin-left:auto">Прогноз ЗП: <strong><?= money($payroll['total']) ?></strong></span><?php endif; ?>
</div>

<!-- Сводка -->
<div class="cards">
    <div class="card"><div class="card-label">Отдел</div><div class="card-value"><?= e($u['dept_name'] ?? '—') ?></div></div>
    <div class="card"><div class="card-label">Должность</div><div class="card-value"><?= e($u['position'] ?: '—') ?></div></div>
    <div class="card"><div class="card-label">Объём ставки</div><div class="card-value big"><?= e($rate) ?></div></div>
    <?php if ($canSeeAllowance): ?>
        <div class="card"><div class="card-label">Надбавка, ₽/мес</div><div class="card-value big"><?= money($u['allowance'] ?? 0) ?></div>
            <div class="muted" style="font-size:.74rem">устанавливает бухгалтерия</div></div>
    <?php endif; ?>
</div>

<div class="doc-grid">
<div>
    <?php if ($canManage): ?>
    <section class="panel">
        <h2 style="margin-top:0">Данные сотрудника</h2>
        <form method="post" action="/admin/employees/<?= (int)$u['id'] ?>/update" class="grid-form">
            <?= csrf_field() ?>
            <label>ФИО<input type="text" name="full_name" value="<?= e($u['full_name']) ?>"></label>
            <label>Тип учётной записи
                <select name="role">
                    <option value="employee" <?= $u['role']==='employee'?'selected':'' ?>>Сотрудник</option>
                    <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Администратор (все права)</option>
                    <?php if (!in_array($u['role'],['employee','admin'],true)): ?><option value="<?= e($u['role']) ?>" selected><?= e($u['role']) ?></option><?php endif; ?>
                </select>
            </label>
            <label>Должность (оклад)
                <select name="position_id">
                    <option value="">— без должности —</option>
                    <?php foreach ($allPositions as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)$u['position_id']===(int)$p['id']?'selected':'' ?>><?= e($p['title']) ?> — <?= money($p['oklad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Объём ставки<input type="number" step="0.01" name="rate_volume" value="<?= e($u['rate_volume']) ?>"></label>
            <label>Режим
                <select name="schedule_type">
                    <option value="5_2" <?= ($u['schedule_type']??'5_2')==='5_2'?'selected':'' ?>>5/2 (дни)</option>
                    <option value="2_2" <?= ($u['schedule_type']??'')==='2_2'?'selected':'' ?>>2/2 Call-центр (смены)</option>
                </select>
            </label>
            <label>Email<input type="email" name="email" value="<?= e($u['email'] ?? '') ?>" placeholder="user@org.ru"></label>
            <label>Новый пароль<input type="text" name="password" placeholder="оставьте пустым"></label>
            <label class="chk-field"><span>Активен</span><input type="checkbox" name="is_active" value="1" <?= (int)$u['is_active']?'checked':'' ?>></label>
            <div class="emp-actions"><button class="btn btn-primary">Сохранить</button></div>
        </form>
        <p class="muted" style="margin:6px 0 0;font-size:.78rem">Надбавку кадры не редактируют — её устанавливает бухгалтерия.</p>

        <details style="margin-top:8px">
            <summary class="muted" style="cursor:pointer">🔄 Перевод (должность / отдел / оклад / ставка) — с логом</summary>
            <form method="post" action="/admin/org/transfer" class="form-inline" style="margin-top:8px;align-items:flex-end">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <label>Дата перевода<input type="date" name="effective_on" value="<?= date('Y-m-d') ?>"></label>
                <label>Отдел
                    <select name="department_id">
                        <option value="">— вне подразделений —</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= (int)$u['department_id']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Должность
                    <select name="position_id">
                        <option value="">— без должности —</option>
                        <?php foreach ($allPositions as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$u['position_id']===(int)$p['id']?'selected':'' ?>><?= e($p['title']) ?> — <?= money($p['oklad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Ставка<input type="number" step="0.01" name="rate_volume" value="<?= e($u['rate_volume']) ?>" style="width:90px"></label>
                <label>Оклад (вручную)<input type="number" step="0.01" name="oklad" placeholder="из должности" style="width:120px"></label>
                <label class="grow">Основание<input type="text" name="reason" placeholder="приказ № … от …"></label>
                <button class="btn btn-mini btn-primary" onclick="return confirm('Оформить перевод?')">Оформить перевод</button>
            </form>
        </details>

        <form method="post" action="/admin/employees/<?= (int)$u['id'] ?>/delete" onsubmit="return confirm('Удалить/деактивировать сотрудника?')" style="margin-top:10px">
            <?= csrf_field() ?><button class="btn btn-mini btn-danger">Удалить / деактивировать</button>
        </form>
    </section>
    <?php else: ?>
    <section class="panel">
        <h2 style="margin-top:0">Данные сотрудника</h2>
        <table class="table">
            <tr><td class="muted" style="width:150px">Отдел</td><td><?= e($u['dept_name'] ?? '—') ?></td></tr>
            <tr><td class="muted">Должность</td><td><?= e($u['position'] ?: '—') ?></td></tr>
            <tr><td class="muted">Объём ставки</td><td><?= e($rate) ?></td></tr>
            <tr><td class="muted">Email</td><td><?= e($u['email'] ?: '—') ?></td></tr>
        </table>
        <p class="muted" style="margin:0;font-size:.78rem">Редактирование данных и ролей — у кадровой службы.</p>
    </section>
    <?php endif; ?>
</div>

<div>
    <?php if ($canSeeAllowance): ?>
    <section class="panel" style="border-left:4px solid #26368B">
        <h2 style="margin-top:0">Надбавка к окладу</h2>
        <?php if ($canEditAllowance): ?>
            <form method="post" action="/admin/employees/<?= (int)$u['id'] ?>/allowance" class="form-inline" style="align-items:flex-end">
                <?= csrf_field() ?>
                <label>Надбавка, ₽/мес<input type="number" step="0.01" name="allowance" value="<?= e($u['allowance'] ?? 0) ?>" style="width:150px"></label>
                <button class="btn btn-primary">Сохранить надбавку</button>
            </form>
            <p class="muted" style="margin:6px 0 0;font-size:.78rem">Надбавку устанавливает бухгалтерия. Она входит в гарантированную часть ЗП.</p>
        <?php else: ?>
            <p style="margin:0">Текущая надбавка: <strong><?= money($u['allowance'] ?? 0) ?></strong>/мес</p>
            <p class="muted" style="margin:6px 0 0;font-size:.78rem">Установлена бухгалтерией. Просмотр — для руководителей и директора.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <section class="panel">
        <h2 style="margin-top:0">Роли и доступы</h2>
        <p class="muted" style="margin-top:0">Текущие роли: <?php if ($myRoles): foreach ($myRoles as $s): ?><span class="tag" style="font-size:.74rem"><?= e($roleMap[$s] ?? $s) ?></span> <?php endforeach; else: ?><span class="muted">не назначены</span><?php endif; ?></p>
        <form method="post" action="/admin/org/access">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <fieldset class="role-group"><legend>Набор ролей</legend>
                <?php foreach ($rolesCatalog as $r): ?>
                    <label class="chk"><input type="checkbox" name="roles[]" value="<?= e($r['slug']) ?>" <?= in_array($r['slug'],$myRoles,true)?'checked':'' ?>>
                        <span><?= e($r['name']) ?></span>
                        <?php if (!empty($r['descr'])): ?><span class="muted" style="font-size:.74rem; display:block"><?= e($r['descr']) ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <h3 class="sub">Табельщик отдела</h3>
            <div class="form-inline" style="align-items:flex-end">
                <label>Табельщик отдела
                    <select name="timekeeper_dept_id">
                        <option value="">—</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= (int)($u['timekeeper_dept_id']??0)===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="muted" style="font-size:.8rem">Замещение в СЭД настраивается в разделе «Документы → Замещение (СЭД)».</span>
            </div>
            <div style="margin-top:12px"><button class="btn btn-primary">💾 Сохранить роли и доступы</button></div>
        </form>
    </section>
    <?php endif; ?>
</div>
</div>
