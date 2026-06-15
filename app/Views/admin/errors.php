<h1>Типы ошибок</h1>

<section class="panel">
    <h2>Добавить тип ошибки</h2>
    <form method="post" action="/admin/errors" class="grid-form">
        <?= csrf_field() ?>
        <label>Название<input type="text" name="name" required></label>
        <label>Снижение, ₽<input type="number" step="0.01" name="penalty" value="0" required></label>
        <label class="chk"><input type="checkbox" name="is_active" value="1" checked> Активен</label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <p class="muted">Снижение указывается за первое срабатывание. Повторы того же типа у сотрудника
        умножаются на коэффициент эскалации (см. <a href="/admin/settings">Настройки</a>).</p>
</section>

<section class="panel">
    <table class="table">
        <thead><tr><th>Название</th><th class="num">Снижение</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($errors as $et): ?>
            <tr>
                <td colspan="4">
                    <form method="post" action="/admin/errors" class="row-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $et['id'] ?>">
                        <input type="text" name="name" value="<?= e($et['name']) ?>">
                        <input type="number" step="0.01" name="penalty" value="<?= e($et['penalty']) ?>" class="narrow">
                        <label class="chk"><input type="checkbox" name="is_active" value="1" <?= (int)$et['is_active']?'checked':'' ?>> активен</label>
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                    </form>
                    <form method="post" action="/admin/errors/<?= (int) $et['id'] ?>/delete" class="inline"
                          onsubmit="return confirm('Удалить тип ошибки?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-mini btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
