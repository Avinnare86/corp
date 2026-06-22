<h1>Бюджет ФОТ — <?= (int)$year ?></h1>

<form method="get" action="/budget" class="form-inline">
    <label>Год<input type="number" name="year" value="<?= (int)$year ?>" style="max-width:110px" onchange="this.form.submit()"></label>
</form>

<section class="panel">
    <h2>Бюджеты по отделам и источникам</h2>
    <form method="post" action="/budget/save">
        <?= csrf_field() ?>
        <input type="hidden" name="year" value="<?= (int)$year ?>">
        <table class="table">
            <thead><tr><th>Отдел</th>
                <?php foreach ($sources as $s): ?><th class="num"><?= e($s['name']) ?><?= $s['detail'] ? '<br><span class="muted" style="font-size:.7rem;text-transform:none">'.e(mb_strimwidth($s['detail'],0,30,'…')).'</span>' : '' ?></th><?php endforeach; ?>
                <th class="num">Итого бюджет</th><th class="num">План окладов ×12</th><th class="num">Факт сдельных</th><th class="num">База для премий</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['dept']['name']) ?></strong> <span class="muted">(<?= (int)$r['people'] ?> чел.)</span></td>
                    <?php foreach ($sources as $s): $si = $r['srcInfo'][$s['id']] ?? null; ?>
                        <td class="num"><input type="number" step="0.01" name="amount[<?= (int)$r['dept']['id'] ?>][<?= (int)$s['id'] ?>]"
                            value="<?= e($r['bySource'][$s['id']] ?? 0) ?>" style="max-width:120px;text-align:right">
                            <?php if ($si && ($si['budget'] > 0 || $si['committed'] > 0)): ?>
                            <div class="muted" style="font-size:.72rem;white-space:nowrap;margin-top:3px">занято <?= money($si['committed']) ?> · ост. <span style="color:<?= $si['available'] < 0 ? 'var(--bad)' : 'var(--ok)' ?>"><?= money($si['available']) ?></span></div>
                            <?php endif; ?></td>
                    <?php endforeach; ?>
                    <td class="num"><strong><?= money($r['budget']) ?></strong></td>
                    <td class="num minus">−<?= money($r['plan']) ?></td>
                    <td class="num minus">−<?= money($r['fact']) ?></td>
                    <td class="num"><strong style="color:<?= $r['base']>=0?'var(--ok)':'var(--bad)' ?>"><?= money($r['base']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total">
                <td>ИТОГО</td><td colspan="<?= count($sources) ?>"></td>
                <td class="num"><?= money($totals['budget']) ?></td>
                <td class="num">−<?= money($totals['plan']) ?></td>
                <td class="num">−<?= money($totals['fact']) ?></td>
                <td class="num"><?= money($totals['base']) ?></td>
            </tr>
            </tbody>
        </table>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить бюджеты</button>
    </form>
    <p class="muted">База для премий = бюджет года − плановая окладная часть (оклады×ставка + надбавки + фикс-доплаты, ×12 мес.)
        − фактически начисленные сдельные (анкеты по тарифам + операции) с начала года.</p>
</section>

<section class="panel">
    <h2>Источники выплат</h2>
    <form method="post" action="/budget/source" class="grid-form">
        <?= csrf_field() ?>
        <label>Название<input type="text" name="name" required></label>
        <label>Вид
            <select name="kind">
                <option value="gz">Госзадание</option>
                <option value="subsidy">Целевая субсидия</option>
                <option value="vneb">Внебюджет</option>
            </select>
        </label>
        <label>Уточнение (какая субсидия)<input type="text" name="detail"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <table class="table">
        <tbody>
        <?php $kinds=['gz'=>'госзадание','subsidy'=>'целевая субсидия','vneb'=>'внебюджет'];
        foreach ($sources as $s): ?>
            <tr>
                <td colspan="2">
                    <form method="post" action="/budget/source" class="row-form">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <input type="text" name="name" value="<?= e($s['name']) ?>" style="min-width:180px">
                        <select name="kind"><?php foreach ($kinds as $kv=>$kl): ?><option value="<?= $kv ?>" <?= $s['kind']===$kv?'selected':'' ?>><?= $kl ?></option><?php endforeach; ?></select>
                        <input type="text" name="detail" value="<?= e($s['detail']) ?>" placeholder="уточнение" style="min-width:220px">
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                    </form>
                    <form method="post" action="/budget/source/<?= (int)$s['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить источник?')">
                        <?= csrf_field() ?><button class="btn btn-mini btn-danger">×</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
