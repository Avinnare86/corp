<h1>Причины доработок</h1>

<section class="panel">
    <h2>Добавить причину</h2>
    <form method="post" action="/admin/comments" class="grid-form">
        <?= csrf_field() ?>
        <label class="grow">Текст причины<input type="text" name="text" required></label>
        <label>Категория
            <input type="text" name="category" list="catList" placeholder="напр. Анкета">
            <datalist id="catList"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?></datalist>
        </label>
        <label class="chk"><input type="checkbox" name="is_active" value="1" checked> Активна</label>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <h2>Переименовать категорию</h2>
    <form method="post" action="/admin/comments/rename-category" class="form-inline">
        <?= csrf_field() ?>
        <label>Категория
            <select name="from"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?></select>
        </label>
        <label>Новое название<input type="text" name="to" required></label>
        <button class="btn">Переименовать</button>
    </form>
</section>

<section class="panel">
    <div class="form-inline">
        <form method="get" action="/admin/comments">
            <label>Категория
                <select name="cat" onchange="this.form.submit()">
                    <option value="">все</option>
                    <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>" <?= $cat===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
                </select>
            </label>
        </form>
        <span class="muted">показано: <?= count($list) ?></span>
    </div>
    <table class="table">
        <thead><tr><th>Причина</th><th>Категория</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $c): ?>
            <tr>
                <td colspan="4">
                    <form method="post" action="/admin/comments" class="row-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="text" name="text" value="<?= e($c['text']) ?>" style="min-width:340px;flex:1">
                        <input type="text" name="category" value="<?= e($c['category']) ?>" list="catList" style="min-width:150px">
                        <label class="chk"><input type="checkbox" name="is_active" value="1" <?= (int)$c['is_active']?'checked':'' ?>> акт.</label>
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                    </form>
                    <form method="post" action="/admin/comments/<?= (int)$c['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить причину?')">
                        <?= csrf_field() ?><button class="btn btn-mini btn-danger">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="4" class="muted">Нет причин.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
