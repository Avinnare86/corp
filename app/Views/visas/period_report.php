<h1>Визы: динамика за период</h1>

<section class="panel">
    <form method="get" action="/visas/report/period" class="form-inline" style="margin-bottom:8px">
        <label>С<input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>По<input type="date" name="to" value="<?= e($to) ?>"></label>
        <label>Страна
            <select name="country">
                <option value="">— все —</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?= e($c) ?>" <?= $country === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Статус
            <select name="status">
                <option value="all" <?= ($status ?? 'checked') === 'all' ? 'selected' : '' ?>>Все статусы</option>
                <?php foreach ($statuses as $st => $label): ?>
                    <option value="<?= e($st) ?>" <?= ($status ?? 'checked') === $st ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Показать</button>
        <a class="btn" href="/visas/report/period/export?from=<?= e($from) ?>&to=<?= e($to) ?>&country=<?= e($country) ?>&status=<?= e($status ?? 'checked') ?>">⇩ Excel</a>
    </form>

    <div class="cards" style="margin-bottom:12px">
        <div class="card"><div class="card-label">Новых загружено за период</div><div class="card-value big"><?= (int)$newLoaded ?></div></div>
        <div class="card"><div class="card-label">Визовых указаний внесено</div><div class="card-value big"><?= (int)$instructions ?></div></div>
        <div class="card"><div class="card-label">Период</div><div class="card-value"><?= e($from) ?> — <?= e($to) ?></div></div>
    </div>

    <h2>Статусы по странам: на начало → на конец</h2>
    <?php if (!$matrix): ?>
        <p class="muted">Нет данных за выбранный период.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Страна</th><th>Статус</th><th class="num">На начало</th><th class="num">На конец</th><th class="num">Δ</th></tr></thead>
        <tbody>
        <?php foreach ($matrix as $c => $byStatus): $first = true; ?>
            <?php foreach ($statuses as $st => $label):
                $s = (int)($byStatus[$st]['start'] ?? 0); $en = (int)($byStatus[$st]['end'] ?? 0);
                if ($s === 0 && $en === 0) { continue; }
                $delta = $en - $s; ?>
                <tr>
                    <td><?= $first ? e($c) : '' ?><?php $first = false; ?></td>
                    <td><?= e($label) ?></td>
                    <td class="num"><?= $s ?></td>
                    <td class="num"><?= $en ?></td>
                    <td class="num <?= $delta>0?'plus':($delta<0?'minus':'') ?>"><?= $delta>0?'+':'' ?><?= $delta ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" style="margin-top:6px">«На начало» — статус строк на 00:00 даты «С»; «На конец» — на 23:59 даты «По». Динамика по журналу смены статусов (для старых данных — приближённо по датам загрузки/проверки/доработки).</p>
    <?php endif; ?>
</section>
