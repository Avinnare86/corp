<h1>Рейтинг специалистов</h1>

<form method="get" action="/rating" class="form-inline" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
    <label>Месяц<br><input type="month" name="period" value="<?= e($period) ?>"></label>
    <label>или с<br><input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
    <label>по<br><input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
    <button class="btn primary" type="submit">Показать</button>
</form>
<p class="muted" style="margin-top:4px;font-size:.85rem">Если задан диапазон дат (с/по) — он имеет приоритет над выбором месяца.</p>

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
