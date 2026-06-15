<h1>Журнал действий</h1>

<section class="panel">
    <form method="get" action="/audit" class="form-inline">
        <label>Поиск<input type="text" name="q" value="<?= e($q) ?>" placeholder="действие, пользователь, детали…"></label>
        <label>Пользователь
            <select name="user" onchange="this.form.submit()">
                <option value="">все</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (string)$who===(string)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Показать</button>
        <a class="btn btn-gold" href="/audit/export?q=<?= urlencode($q) ?>&user=<?= urlencode((string)$who) ?>">📊 В Excel</a>
        <span class="muted">найдено: <?= (int)$total ?> (показаны последние 300)</span>
    </form>
</section>

<section class="panel">
    <table class="table">
        <thead><tr><th>Время</th><th>Пользователь</th><th>Роль</th><th>Действие</th><th>Детали</th><th>IP</th></tr></thead>
        <tbody>
        <?php
        $roleLabels = ['employee'=>'специалист','controller'=>'контролёр','manager'=>'менеджер','admin'=>'админ'];
        foreach ($rows as $r):
            $det = $r['details'];
            $detShort = $det ? mb_strimwidth((string)$det, 0, 90, '…') : '';
        ?>
            <tr>
                <td class="muted" style="white-space:nowrap"><?= e(substr((string)$r['created_at'],0,19)) ?></td>
                <td><?= e($r['user_name'] ?: '—') ?></td>
                <td><?= e($roleLabels[$r['role']] ?? $r['role']) ?></td>
                <td><?= e($r['label']) ?></td>
                <td class="muted" title="<?= e((string)$det) ?>" style="max-width:340px;overflow:hidden;text-overflow:ellipsis"><?= e($detShort) ?></td>
                <td class="muted mono"><?= e($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Записей нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
