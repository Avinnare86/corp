<h1>Справочник должностей</h1>

<section class="panel">
    <h2>Добавить должность</h2>
    <form method="post" action="/admin/positions" class="grid-form">
        <?= csrf_field() ?>
        <label>Название должности<input type="text" name="title" required></label>
        <label>Оклад, ₽<input type="number" step="0.01" name="oklad" value="0" required></label>
        <label class="chk"><input type="checkbox" name="is_active" value="1" checked> Активна</label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <p class="muted">Оклад сотрудника берётся из назначенной должности (начисляется по отработанным дням).</p>
</section>

<section class="panel">
    <table class="table">
        <thead><tr><th>Должность</th><th class="num">Оклад</th><th class="num">Сотрудников</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($positions as $p): ?>
            <tr>
                <td colspan="5">
                    <form method="post" action="/admin/positions" class="row-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="text" name="title" value="<?= e($p['title']) ?>" style="min-width:240px">
                        <input type="number" step="0.01" name="oklad" value="<?= e($p['oklad']) ?>" class="narrow">
                        <span class="muted">сотрудников: <?= (int) $p['people'] ?></span>
                        <label class="chk"><input type="checkbox" name="is_active" value="1" <?= (int)$p['is_active']?'checked':'' ?>> активна</label>
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                    </form>
                    <form method="post" action="/admin/positions/<?= (int) $p['id'] ?>/delete" class="inline"
                          onsubmit="return confirm('Удалить должность?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-mini btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$positions): ?>
            <tr><td colspan="5" class="muted">Должностей пока нет.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
