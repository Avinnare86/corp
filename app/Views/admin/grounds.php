<h1>Основания стимула (раздел 4)</h1>

<section class="panel">
    <h2>Потолок % на одну служебку</h2>
    <form method="post" action="/admin/grounds" class="form-inline">
        <?= csrf_field() ?>
        <label>Максимальный % (сумма / оклад×ставка). 0 = без жёсткого лимита, только показывать
            <input type="number" step="1" name="cap" value="<?= e(rtrim(rtrim(number_format($cap,2,'.',''),'0'),'.')) ?>" style="width:120px">
        </label>
        <button class="btn btn-primary">Сохранить</button>
    </form>
    <p class="muted">Если % строки превышает потолок — служебку нужно разнести на несколько с разными основаниями.</p>
</section>

<section class="panel">
    <h2>Добавить основание</h2>
    <form method="post" action="/admin/grounds" class="form-inline">
        <?= csrf_field() ?>
        <label class="grow">Текст основания<input type="text" name="text" required placeholder="За достижение высоких результатов…"></label>
        <label>Категория<input type="text" name="category" placeholder="Результативность"></label>
        <label>Норматив, %<input type="number" step="1" name="percent" value="0" style="width:90px"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <h2>Перечень</h2>
    <table class="table">
        <thead><tr><th>Основание</th><th>Категория</th><th>%</th><th>Активно</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($grounds as $g): ?>
            <tr<?= (int)$g['is_active'] ? '' : ' class="emp-off"' ?>>
                <td colspan="5">
                    <form method="post" action="/admin/grounds" class="row-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                        <input type="text" name="text" value="<?= e($g['text']) ?>" style="min-width:340px">
                        <input type="text" name="category" value="<?= e($g['category']) ?>" class="narrow">
                        <input type="number" step="1" name="percent" value="<?= e(rtrim(rtrim(number_format((float)($g['percent']??0),1,'.',''),'0'),'.')) ?>" style="width:70px" title="Норматив %">
                        <label class="chk"><input type="checkbox" name="is_active" value="1" <?= (int)$g['is_active']?'checked':'' ?>> актив.</label>
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                    </form>
                    <?php if ((int)$g['is_active']): ?>
                    <form method="post" action="/admin/grounds/<?= (int)$g['id'] ?>/delete" class="inline" onsubmit="return confirm('Отключить основание?')">
                        <?= csrf_field() ?><button class="btn btn-mini btn-danger">×</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
