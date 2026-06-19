<h1>Визы и операции</h1>

<div class="flash" style="background:#fff3cd;color:#5b4a00;border-left:4px solid #d9a400;margin-bottom:12px">
    Этапы 1 и 3 идут в расчёт ЗП <strong>только после акцепта менеджером виз</strong> (по итогам дня).
    Этап 2 (из грида проверки) учитывается сразу.
</div>

<?php if (!$working): ?>
<section class="panel attendance" style="border-left:4px solid var(--accent)">
    <div>
        <strong>Рабочий день не открыт</strong>
        <p class="muted" style="margin:4px 0 0">Чтобы вводить операции, сначала приступите к работе.</p>
    </div>
    <form method="post" action="/day/open"><?= csrf_field() ?><button class="btn btn-primary">▶ Приступить к работе</button></form>
</section>
<?php endif; ?>

<section class="panel" <?= !$working ? 'style="display:none"' : '' ?>>
    <h2>Добавить выполненную работу</h2>
    <form method="post" action="/piecework" class="form-inline">
        <?= csrf_field() ?>
        <label>Дата
            <input type="date" name="work_date" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <label>Операция
            <select name="operation_id" required>
                <?php foreach ($operations as $o): ?>
                    <option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?> — <?= money($o['unit_price']) ?>/шт</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Количество
            <input type="number" name="quantity" min="1" value="1" required>
        </label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <?php if (!$operations): ?>
        <p class="muted">Операции ещё не заведены администратором.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <form method="get" action="/piecework" class="form-inline">
        <label>Период
            <input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()">
        </label>
    </form>

    <table class="table">
        <thead>
        <tr><th>Дата</th><th>Операция</th><th class="num">Кол-во</th><th class="num">Цена</th><th class="num">Сумма</th><th>Статус</th><th></th></tr>
        </thead>
        <tbody>
        <?php $total = 0; ?>
        <?php foreach ($entries as $en): $sum = (int)$en['quantity'] * (float)$en['unit_price']; $total += $sum; ?>
            <tr>
                <td><?= e($en['work_date']) ?></td>
                <td><?= e($en['op_name']) ?></td>
                <td class="num"><?= (int) $en['quantity'] ?></td>
                <td class="num"><?= money($en['unit_price']) ?></td>
                <td class="num"><?= money($sum) ?></td>
                <td>
                    <?php if (in_array((int)($en['stage'] ?? 0), [1,3], true)): ?>
                        <?= !empty($en['accepted_at']) ? '<span class="tag ok">принято</span>' : '<span class="tag off">ожидает акцепта</span>' ?>
                    <?php else: ?>
                        <span class="muted">учтено</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/piecework/<?= (int) $en['id'] ?>/delete" onsubmit="return confirm('Удалить запись?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-mini btn-danger">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
            <tr><td colspan="7" class="muted">За период записей нет.</td></tr>
        <?php endif; ?>
        <?php if ($entries): ?>
            <tr class="total"><td colspan="4">Итого за период</td><td class="num"><?= money($total) ?></td><td colspan="2"></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
