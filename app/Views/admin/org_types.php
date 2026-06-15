<h1>Типы документов</h1>
<?php include __DIR__ . '/../partials/org_nav.php'; ?>

<section class="panel">
    <h2>Добавить тип</h2>
    <form method="post" action="/admin/org/type" class="form-inline">
        <?= csrf_field() ?>
        <label>Название<input type="text" name="name" placeholder="Служебная записка" required></label>
        <label>Префикс №<input type="text" name="prefix" placeholder="СЗ" class="narrow"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <h2>Справочник</h2>
    <table class="table">
        <thead><tr><th>Тип</th><th>Префикс</th><th>Индекс дела</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($types as $t): ?>
            <tr>
                <td colspan="4">
                    <form method="post" action="/admin/org/type" class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <input type="text" name="name" value="<?= e($t['name']) ?>" style="min-width:220px">
                        <input type="text" name="prefix" value="<?= e($t['prefix']) ?>" class="narrow" title="префикс">
                        <input type="text" name="journal_index" value="<?= e($t['journal_index'] ?? '') ?>" class="narrow" placeholder="индекс дела" title="нумератор: 01-15/3-2026">
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                        <a class="btn btn-mini btn-danger" href="#" onclick="if(confirm('Удалить тип?')){this.nextElementSibling.submit()}return false">×</a>
                    </form>
                    <form method="post" action="/admin/org/type/<?= (int)$t['id'] ?>/delete" style="display:none"><?= csrf_field() ?></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted">Рег. номер присваивается автоматически после полного согласования: ПРЕФИКС-N/год (например СЗ-3/26).</p>
</section>
