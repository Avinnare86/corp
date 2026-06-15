<h1>Табель отработанных дней</h1>

<form method="get" action="/admin/timesheet" class="form-inline">
    <label>Период
        <input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()">
    </label>
</form>

<section class="panel">
    <form method="post" action="/admin/timesheet">
        <?= csrf_field() ?>
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <table class="table">
            <thead><tr><th>Сотрудник</th><th>Ставка</th><th>Норма дней</th><th>Отработано дней</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
                <tr>
                    <td><?= e($r['full_name']) ?> <span class="muted"><?= e($r['position']) ?></span></td>
                    <td><?= e($r['rate_volume']) ?></td>
                    <td><input type="number" name="norm_days[<?= $id ?>]" value="<?= e($r['norm_days'] ?? '') ?>" class="narrow"></td>
                    <td><input type="number" name="worked_days[<?= $id ?>]" value="<?= e($r['worked_days'] ?? '') ?>" class="narrow"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary">Сохранить табель</button>
    </form>
    <p class="muted">Оклад начисляется как: оклад × ставка × (отработано / норма).</p>
</section>
