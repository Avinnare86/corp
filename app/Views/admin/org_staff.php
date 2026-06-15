<h1>Сотрудники по подразделениям</h1>
<?php include __DIR__ . '/../partials/org_nav.php'; ?>

<section class="panel">
    <form method="get" action="/admin/org/staff" class="form-inline">
        <label>Показать
            <select name="dept" onchange="this.form.submit()">
                <option value="">все сотрудники</option>
                <option value="none" <?= $deptFilter==='none'?'selected':'' ?>>вне подразделений</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (string)$deptFilter===(string)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <span class="muted">Здесь — только распределение по отделам. Подчинённость отделов — в разделе «Подразделения».</span>
    </form>

    <table class="table">
        <thead><tr><th>Сотрудник</th><th>Подразделение</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['full_name']) ?> <span class="muted" style="font-size:.78rem"><?= e($u['position']) ?></span></td>
                <td>
                    <form method="post" action="/admin/org/assign" class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="back" value="<?= e((string)$deptFilter) ?>">
                        <select name="department_id" onchange="this.form.submit()">
                            <option value="">— вне подразделений —</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)$u['department_id']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="2" class="muted">Нет сотрудников по условию.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
