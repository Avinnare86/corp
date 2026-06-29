<?php
$money = fn($v) => number_format((float) $v, 2, ',', ' ');
$kindLabel = ['gz' => 'госзадание', 'subsidy' => 'субсидия', 'vneb' => 'внебюджет'];
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Менеджер финансов задаёт бюджет командировок по отделам и источникам, ставки суточных
    (РФ/зарубеж по каждому источнику) и справочник дополнительных расходов. До факта из бюджета списывается план, после — факт.</p>

<form method="get" action="/trip-finance" class="panel flt" style="margin-bottom:16px">
    <label>Год<br><input type="number" name="year" value="<?= (int) $year ?>" min="2020" max="2100" style="width:110px"></label>
    <button class="btn primary">Показать</button>
</form>

<section class="panel">
    <h2 style="margin-top:0">Бюджет командировок <?= (int) $year ?> (отдел × источник)</h2>
    <form method="post" action="/trip-finance/budget">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="year" value="<?= (int) $year ?>">
        <div style="overflow:auto">
        <table class="table tbl-wide">
            <thead><tr><th>Отдел</th>
                <?php foreach ($sources as $s): ?><th class="num"><?= e($s['name']) ?><br><span class="muted" style="font-weight:400"><?= e($kindLabel[$s['kind']] ?? $s['kind']) ?></span></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($departments as $d): $did = (int) $d['id']; ?>
                <tr>
                    <td><?= e($d['name']) ?></td>
                    <?php foreach ($sources as $s): $sid = (int) $s['id'];
                        $amt = $budget[$did][$sid] ?? 0; $com = $committed[$did][$sid] ?? 0; ?>
                        <td class="num">
                            <input type="text" name="amount[<?= $did ?>][<?= $sid ?>]" value="<?= $amt ? rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.') : '' ?>" style="width:120px;text-align:right" placeholder="0">
                            <?php if ($com > 0): ?><br><span class="muted" style="font-size:.78rem">занято <?= $money($com) ?> · ост. <?= $money($amt - $com) ?></span><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$departments): ?><tr><td colspan="<?= count($sources) + 1 ?>" class="muted">Нет отделов.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить бюджет</button>
    </form>
</section>

<section class="panel">
    <h2>Суточные (₽/день): источник × место</h2>
    <form method="post" action="/trip-finance/per-diem">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <table class="table tbl-cards">
            <thead><tr><th>Источник</th><?php foreach ($locLabels as $lk => $lv): ?><th class="num"><?= e($lv) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($sources as $s): $sid = (int) $s['id']; ?>
                <tr>
                    <td data-label="Источник"><?= e($s['name']) ?> <span class="muted" style="font-size:.8rem">(<?= e($kindLabel[$s['kind']] ?? $s['kind']) ?>)</span></td>
                    <?php foreach ($locLabels as $lk => $lv): $r = $perDiem[$sid][$lk] ?? 0; ?>
                        <td class="num" data-label="<?= e($lv) ?>"><input type="text" name="rate[<?= $sid ?>][<?= e($lk) ?>]" value="<?= $r ? rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.') : '' ?>" style="width:100px;text-align:right" placeholder="0"></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sources): ?><tr><td colspan="3" class="muted">Сначала заведите источники финансирования в разделе «Бюджет ФОТ».</td></tr><?php endif; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить суточные</button>
    </form>
</section>

<section class="panel">
    <h2>Справочник дополнительных расходов</h2>
    <form method="post" action="/trip-finance/kind" class="grid-form" style="margin-bottom:12px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="grow">Новый вид расхода<input type="text" name="name" maxlength="150" placeholder="Например: Виза, Страховка" required></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <table class="table">
        <thead><tr><th>Вид расхода</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($kinds as $k): ?>
            <tr>
                <td><?= e($k['name']) ?></td>
                <td><?= $k['is_active'] ? '<span class="tag ok">активен</span>' : '<span class="tag">скрыт</span>' ?></td>
                <td><form method="post" action="/trip-finance/kind/<?= (int) $k['id'] ?>/delete" onsubmit="return confirm('Удалить/скрыть вид расхода?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
