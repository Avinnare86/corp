<h1>Сотрудники</h1>

<section class="panel">
    <h2>Добавить сотрудника</h2>
    <form method="post" action="/admin/employees" class="grid-form">
        <?= csrf_field() ?>
        <label>ФИО<input type="text" name="full_name" required></label>
        <label>Логин<input type="text" name="login" required></label>
        <label>Пароль<input type="text" name="password" required></label>
        <label>Тип учётной записи
            <select name="role">
                <option value="employee">Сотрудник</option>
                <option value="admin">Администратор (все права)</option>
            </select>
        </label>
        <label>Должность (оклад)
            <select name="position_id">
                <option value="">— без должности —</option>
                <?php foreach ($allPositions as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['title']) ?> — <?= money($p['oklad']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Объём ставки<input type="number" step="0.01" name="rate_volume" value="1"></label>
        <label>Режим
            <select name="schedule_type">
                <option value="5_2">5/2 (дни)</option>
                <option value="2_2">2/2 Call-центр (смены)</option>
            </select>
        </label>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <form method="get" action="/admin/employees" class="form-inline" style="margin-bottom:10px">
        <label class="grow">🔎 Поиск по ФИО<input type="text" name="q" value="<?= e($q) ?>" placeholder="введите фамилию…" autofocus></label>
        <button class="btn btn-primary">Найти</button>
        <?php if ($q !== '' || $letter !== ''): ?><a class="btn" href="/admin/employees">Сброс</a><?php endif; ?>
    </form>
    <div class="form-inline" style="gap:6px;flex-wrap:wrap">
        <span class="muted">По буквам:</span>
        <?php foreach ($groups as $g): $c = $counts[$g] ?? 0; ?>
            <a class="btn btn-mini <?= $letter===$g?'btn-primary':'' ?>" href="/admin/employees?letter=<?= urlencode($g) ?>"><?= e($g) ?> <span class="muted">(<?= $c ?>)</span></a>
        <?php endforeach; ?>
        <?php if (!empty($counts['Прочее'])): ?><a class="btn btn-mini <?= $letter==='Прочее'?'btn-primary':'' ?>" href="/admin/employees?letter=<?= urlencode('Прочее') ?>">Прочее (<?= (int)$counts['Прочее'] ?>)</a><?php endif; ?>
        <a class="btn btn-mini <?= $letter==='all'?'btn-primary':'' ?>" href="/admin/employees?letter=all">Все (<?= (int)$total ?>)</a>
    </div>
</section>

<section class="panel">
    <?php if ($q === '' && $letter === ''): ?>
        <p class="muted" style="margin:0">Всего сотрудников: <strong><?= (int)$total ?></strong>. Выберите букву или воспользуйтесь поиском — список загрузится только по выбранному.</p>
    <?php elseif (!$shown): ?>
        <p class="muted" style="margin:0">Ничего не найдено.</p>
    <?php else: ?>
        <p class="muted" style="margin:0 0 8px">Показано: <strong><?= count($shown) ?></strong>. Нажмите на сотрудника — откроется карточка с подробностями и ролями.</p>
        <table class="table">
            <thead><tr><th>ФИО</th><th>Отдел</th><th>Должность</th><th class="num">Ставка</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($shown as $u): ?>
                <tr<?= (int)$u['is_active'] ? '' : ' class="emp-off"' ?>>
                    <td><a href="/admin/employees/<?= (int)$u['id'] ?>"><strong><?= e($u['full_name']) ?></strong></a>
                        <?php if (!(int)$u['is_active']): ?> <span class="tag off">не активен</span><?php endif; ?>
                        <?php if ($u['role']==='admin'): ?> <span class="tag">админ</span><?php endif; ?></td>
                    <td><?= e($u['dept_name'] ?? '—') ?></td>
                    <td><?= e($u['position'] ?: '—') ?></td>
                    <td class="num"><?= e(rtrim(rtrim(number_format((float)$u['rate_volume'],2,'.',''),'0'),'.')) ?></td>
                    <td><a class="btn btn-mini" href="/admin/employees/<?= (int)$u['id'] ?>">Открыть →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
