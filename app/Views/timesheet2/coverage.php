<h1>Покрытие табелями</h1>

<section class="panel">
    <form method="get" action="/timesheet2/coverage" class="form-inline">
        <label>Месяц<input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()"></label>
        <label>Половина
            <select name="half" onchange="this.form.submit()">
                <option value="1" <?= $half===1?'selected':'' ?>>1–15</option>
                <option value="2" <?= $half===2?'selected':'' ?>>16–конец</option>
            </select>
        </label>
    </form>
    <p class="muted">Для кадров и бухгалтерии: на кого из сотрудников есть подписанный табель за период, на кого нет.</p>
</section>

<section class="panel">
    <h2>По структуре — период <?= e($period) ?></h2>
    <table class="table">
        <thead><tr><th>Подразделение</th><th class="num">Сотрудников</th><th class="num">Покрыто</th><th style="width:240px">Прогресс</th><th>Без табеля</th></tr></thead>
        <tbody>
        <?php foreach ($deptRows as $dr): $p = $dr['total']>0 ? round($dr['covered']/$dr['total']*100) : 0; ?>
            <tr>
                <td><strong><?= e($dr['dept']['name']) ?></strong></td>
                <td class="num"><?= (int)$dr['total'] ?></td>
                <td class="num"><?= (int)$dr['covered'] ?></td>
                <td><div class="bar"><div class="bar-fill" style="width:<?= $p ?>%"></div><span class="bar-label"><?= $p ?>%</span></div></td>
                <td class="muted"><?= $dr['missing'] ? e(implode(', ', $dr['missing'])) : '<span class="tag ok">все покрыты</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$deptRows): ?><tr><td colspan="5" class="muted">Нет сотрудников.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Табели периода</h2>
    <table class="table">
        <thead><tr><th>Охват</th><th>Ревизия</th><th>Статус</th><th>Подписал</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($allTabels as $tb): ?>
            <tr>
                <td><?= e($tb['dept_name'] ?: 'Вся организация') ?></td>
                <td><?= (int)$tb['revision']===0?'первичный':'корр. №'.(int)$tb['revision'] ?></td>
                <td><?= $tb['status']==='signed' ? '<span class="st st-ok">Подписан ('.e($tb['sign_type']).')</span>' : '<span class="st st-wait">Черновик</span>' ?></td>
                <td class="muted"><?= e($tb['signer'] ?? '—') ?></td>
                <td><?php if ($tb['status']==='signed'): ?><a class="btn btn-mini" href="/timesheet2/<?= (int)$tb['id'] ?>/view">📄 PDF-вид</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$allTabels): ?><tr><td colspan="5" class="muted">Табелей нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
