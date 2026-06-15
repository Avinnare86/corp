<h1>Операции (сделка кроме анкет)</h1>

<section class="panel">
    <h2>Добавить операцию</h2>
    <form method="post" action="/admin/operations" class="grid-form">
        <?= csrf_field() ?>
        <label>Название<input type="text" name="name" placeholder="Виза — этап 1" required></label>
        <label>Цена за шт, ₽<input type="number" step="0.01" name="unit_price" value="0" required></label>
        <label class="chk"><input type="checkbox" name="is_active" value="1" checked> Активна</label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <p class="muted">Анкеты/досье тарифицируются отдельно по группам стран. Здесь — визы по этапам и любая
        другая сдельная работа, которую сотрудники вводят количеством.</p>
</section>

<section class="panel">
    <table class="table">
        <thead><tr><th>Операция</th><th class="num">Цена/шт</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($operations as $o): ?>
            <tr>
                <td colspan="4">
                    <form method="post" action="/admin/operations" class="row-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <input type="text" name="name" value="<?= e($o['name']) ?>" style="min-width:220px">
                        <input type="number" step="0.01" name="unit_price" value="<?= e($o['unit_price']) ?>" class="narrow">
                        <label class="chk"><input type="checkbox" name="is_active" value="1" <?= (int)$o['is_active']?'checked':'' ?>> активна</label>
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                    </form>
                    <form method="post" action="/admin/operations/<?= (int) $o['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить операцию?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-mini btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$operations): ?>
            <tr><td colspan="4" class="muted">Операций пока нет.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
