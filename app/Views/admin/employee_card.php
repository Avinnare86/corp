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
    <?php if (\App\Core\Auth::isAdmin() && !\App\Core\Auth::impostorAdminId() && (int)$u['id'] !== (int)\App\Core\Auth::id() && (int)$u['is_active']): ?>
        <form method="post" action="/admin/login-as/<?= (int)$u['id'] ?>" style="margin:0" onsubmit="return confirm('Войти в систему как этот сотрудник? Вы сможете вернуться к админу кнопкой вверху.')">
            <input type="hidden" name="_csrf" value="<?= e(\App\Core\Auth::csrf()) ?>">
            <button class="btn btn-mini">👤 Войти как</button>
        </form>
    <?php endif; ?>
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
                    <option value="2_2" <?= ($u['schedule_type']??'')==='2_2'?'selected':'' ?>>2/2 Call-центр (часы)</option>
                </select>
                <?php if (($u['schedule_type']??'')==='2_2'): ?><span class="muted" style="font-size:.78rem">для 2/2 «оклад» = ставка ₽/час; ЗП — по сменному графику</span><?php endif; ?>
            </label>
            <label>Персональная надбавка, %<input type="number" step="0.1" name="hourly_bonus_pct" value="<?= e(rtrim(rtrim(number_format((float)($u['hourly_bonus_pct']??0),2,'.',''),'0'),'.')) ?>" title="надбавка по ТК, % от начисленного (для почасовиков)"></label>
            <label>…или фикс, ₽/мес<input type="number" step="0.01" name="hourly_bonus_rub" value="<?= e(rtrim(rtrim(number_format((float)($u['hourly_bonus_rub']??0),2,'.',''),'0'),'.')) ?>" title="если % не задан"></label>
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
        <h2 style="margin-top:0">Надбавка (через стимул)</h2>
        <p class="muted" style="margin-top:0;font-size:.82rem">Надбавка назначается на период и формирует ежемесячные служебки-проекты о стимуле (основания — из списка). Оплата идёт по утверждённым служебкам; ранее заданная плоская надбавка (<?= money($u['allowance'] ?? 0) ?>) в ЗП больше не учитывается.</p>
        <?php if (!empty($allowanceGrants)): ?>
        <table class="table" style="margin-bottom:10px">
            <thead><tr><th>Период</th><th class="num">₽/мес</th><th class="num">Проектов</th><th class="num">Утв.</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($allowanceGrants as $g): ?>
                <tr>
                    <td class="muted"><?= e(substr((string)$g['period_from'],0,7)) ?> — <?= e(substr((string)$g['period_to'],0,7)) ?></td>
                    <td class="num"><?= money($g['amount']) ?></td>
                    <td class="num"><?= (int)$g['memos'] ?></td>
                    <td class="num"><?= (int)$g['approved'] ?></td>
                    <td><span class="tag <?= $g['status']==='active'?'':'off' ?>"><?= $g['status']==='active'?'действует':'отменён' ?></span></td>
                    <td><?php if ($canEditAllowance && $g['status']==='active'): ?>
                        <form method="post" action="/admin/allowance-grants/<?= (int)$g['id'] ?>/cancel" class="inline" onsubmit="return confirm('Отменить назначение? Неутверждённые служебки-проекты будут удалены.')"><?= csrf_field() ?><button class="btn btn-mini btn-danger">Отменить</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($canEditAllowance): ?>
            <form method="post" action="/admin/employees/<?= (int)$u['id'] ?>/allowance-grant" class="grid-form">
                <?= csrf_field() ?>
                <label>Надбавка, ₽/мес<input type="number" step="0.01" name="amount" required></label>
                <label>Источник
                    <select name="source_id"><option value="">—</option>
                        <?php foreach (($paySources ?? []) as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Период с<input type="month" name="period_from" value="<?= e(date('Y-m')) ?>" required></label>
                <label>по<input type="month" name="period_to" value="<?= e(date('Y-m')) ?>" required></label>
                <label>Цель (для расчёта ЗП)
                    <select name="purpose">
                        <option value="other">за другое (гарантированная доплата)</option>
                        <option value="anketas">за анкеты (зарабатывается сделкой)</option>
                        <option value="visas">за визы (зарабатывается сделкой)</option>
                    </select>
                </label>
                <fieldset style="grid-column:1/-1;border:1px solid var(--line);border-radius:8px;padding:8px">
                    <legend class="muted" style="font-size:.8rem">Основания (из списка стимула) — их суммарный % должен покрывать % надбавки</legend>
                    <?php foreach (($stimGrounds ?? []) as $gr): $p = rtrim(rtrim(number_format((float)$gr['percent'],1,'.',''),'0'),'.'); ?>
                        <label class="chk" style="display:block"><input type="checkbox" name="grounds[]" value="<?= (int)$gr['id'] ?>">
                            <?= e($gr['text']) ?> <span class="muted">(<?= e($gr['category']) ?><?= (float)$gr['percent']>0 ? ', до '.e($p).'%' : '' ?>)</span></label>
                    <?php endforeach; ?>
                </fieldset>
                <div style="grid-column:1/-1"><button class="btn btn-primary">Назначить надбавку (создать служебки)</button></div>
            </form>
        <?php else: ?>
            <p class="muted" style="margin:6px 0 0;font-size:.78rem">Назначает бухгалтерия. Просмотр — для руководителей и директора.</p>
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
