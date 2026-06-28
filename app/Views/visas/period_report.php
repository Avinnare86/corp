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
        <div class="card" style="border:2px solid var(--primary)"><div class="card-label">✅ Проверено за период</div><div class="card-value big"><?= (int)($checkedTotal ?? 0) ?></div></div>
        <div class="card"><div class="card-label">Новых загружено за период</div><div class="card-value big"><?= (int)$newLoaded ?></div></div>
        <div class="card"><div class="card-label">Визовых указаний внесено</div><div class="card-value big"><?= (int)$instructions ?></div></div>
        <div class="card"><div class="card-label">Период</div><div class="card-value"><?= e($from) ?> — <?= e($to) ?></div></div>
    </div>

    <h2>Статусы по странам: на начало → на конец</h2>
    <?php if (!$matrix): ?>
        <p class="muted">Нет данных за выбранный период.</p>
    <?php else:
        $totS = 0; $totE = 0;
        foreach ($matrix as $bs) { foreach ($statuses as $st => $l) { $totS += (int)($bs[$st]['start'] ?? 0); $totE += (int)($bs[$st]['end'] ?? 0); } }
        $totD = $totE - $totS; ?>
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
        <tfoot>
            <tr style="font-weight:700;border-top:2px solid var(--line)">
                <td>Итого</td><td></td>
                <td class="num"><?= $totS ?></td>
                <td class="num"><?= $totE ?></td>
                <td class="num <?= $totD>0?'plus':($totD<0?'minus':'') ?>"><?= $totD>0?'+':'' ?><?= $totD ?></td>
            </tr>
        </tfoot>
    </table>
    <p class="muted" style="margin-top:6px">«На начало» — статус строк на 00:00 даты «С»; «На конец» — на 23:59 даты «По». Динамика по журналу смены статусов (для старых данных — приближённо по датам загрузки/проверки/доработки).</p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>✅ Проверено за период — сколько проверено специалистами</h2>
    <p class="muted" style="margin-top:0">Фактические числа по дате проверки (когда специалист отметил визу проверенной), не дельта статусов. Учитывает фильтры периода и страны.</p>
    <?php if ((int)($checkedTotal ?? 0) === 0): ?>
        <p class="muted">За выбранный период проверенных виз нет.</p>
    <?php else: ?>
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
        <div style="flex:1;min-width:280px">
            <h3 class="sub" style="margin-top:0">По специалистам</h3>
            <table class="table">
                <thead><tr><th>Специалист</th><th class="num">Проверено</th></tr></thead>
                <tbody>
                <?php foreach (($checkedByEmp ?? []) as $r): ?>
                    <tr><td><?= e($r['full_name']) ?></td><td class="num"><?= (int)$r['cnt'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)"><td>Итого</td><td class="num"><?= (int)$checkedTotal ?></td></tr></tfoot>
            </table>
        </div>
        <div style="flex:1;min-width:240px">
            <h3 class="sub" style="margin-top:0">По дням</h3>
            <table class="table">
                <thead><tr><th>Дата</th><th class="num">Проверено</th></tr></thead>
                <tbody>
                <?php foreach (($checkedByDay ?? []) as $r): ?>
                    <tr><td><?= e($r['d']) ?></td><td class="num"><?= (int)$r['cnt'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)"><td>Итого</td><td class="num"><?= (int)$checkedTotal ?></td></tr></tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</section>
