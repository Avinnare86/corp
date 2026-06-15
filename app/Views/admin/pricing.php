<h1>Тарифы по группам стран</h1>

<section class="panel">
    <p class="muted">Стимул за каждое обработанное досье зависит от группы страны (5 групп).</p>
    <form method="post" action="/admin/pricing">
        <?= csrf_field() ?>
        <table class="table">
            <thead><tr><th>Группа</th><th>Название</th><th>Цена за досье, ₽</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): $no = (int) $g['group_no']; ?>
                <tr>
                    <td>Гр. <?= $no ?></td>
                    <td><input type="text" name="title[<?= $no ?>]" value="<?= e($g['title']) ?>"></td>
                    <td><input type="number" step="0.01" name="price[<?= $no ?>]" value="<?= e($g['price']) ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary">Сохранить тарифы</button>
    </form>
</section>
