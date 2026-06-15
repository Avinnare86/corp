<h1>Рейтинг по визам</h1>

<section class="panel">
    <form method="get" action="/visas/rating" class="form-inline">
        <label>Период<input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()"></label>
    </form>
</section>

<section class="panel">
    <table class="table">
        <thead><tr><th class="num">#</th><th>Специалист</th><th class="num">Проверено</th><th class="num">Доработки</th><th class="num">Качество</th></tr></thead>
        <tbody>
        <?php foreach ($ranking as $r): ?>
            <tr<?= (int)$r['id']===(int)$meId ? ' class="total"' : '' ?>>
                <td class="num"><?= (int)$r['rank'] ?></td>
                <td><?= e($r['full_name']) ?><?= (int)$r['id']===(int)$meId ? ' <span class="tag">вы</span>' : '' ?></td>
                <td class="num"><strong><?= (int)$r['checked'] ?></strong></td>
                <td class="num<?= (int)$r['reworks']?' minus':'' ?>"><?= (int)$r['reworks'] ?></td>
                <td class="num"><?= $r['quality']!==null ? (int)$r['quality'].'%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ranking): ?><tr><td colspan="5" class="muted">За период нет проверенных виз.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="muted">Качество = доля проверенных без доработок. Сортировка: больше проверено и меньше доработок — выше.</p>
</section>
