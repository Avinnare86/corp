<h1>Рейтинг специалистов</h1>

<form method="get" action="/rating" class="form-inline">
    <label>Период
        <input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()">
    </label>
</form>

<section class="panel">
    <table class="table">
        <thead>
        <tr>
            <th>#</th><th>Специалист</th><th class="num">Досье</th>
            <th class="num">Проверено</th><th class="num">Ошибок</th><th class="num">Качество</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($ranking as $r): ?>
            <tr class="<?= (int) $r['id'] === (int) $meId ? 'me' : '' ?>">
                <td><?= (int) $r['rank'] ?></td>
                <td><?= e($r['full_name']) ?> <span class="muted"><?= e($r['position']) ?></span></td>
                <td class="num"><?= (int) $r['dossiers'] ?></td>
                <td class="num"><?= (int) $r['checked'] ?></td>
                <td class="num"><?= (int) $r['errors'] ?></td>
                <td class="num"><?= $r['quality'] !== null ? $r['quality'] . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ranking): ?>
            <tr><td colspan="6" class="muted">Нет данных за период.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="muted">Сортировка по качеству (доля корректных анкет среди проверенных), затем по количеству досье.</p>
</section>
