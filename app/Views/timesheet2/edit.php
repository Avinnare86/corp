<?php
/** Редактирование обычного табеля (5/2) — презумпция явки: по умолчанию «Я» на рабочие дни,
 *  «В» на сб/вс, «О» на утверждённый отпуск. Табельщик меняет день только на отсутствие/иной код.
 *  Без часов — по ячейке выбирается код ОКУД. Бланк подписывается ЭП (становится листом 0504421). */
$weekend = fn($d) => in_array((int) date('N', strtotime($d)), [6, 7], true);
// варианты кодов: ходовые сверху, затем весь справочник ОКУД
$common = ['Я', 'В', 'О', 'Б', 'К', 'ОД', 'Р', 'ОЖ', 'ДО', 'У', 'Г', 'ПР', 'НН'];
$order = array_values(array_unique(array_merge($common, array_keys($okud))));
?>
<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <h1 style="margin:0">Табель <?= e($t['period']) ?><?= (int)$t['revision'] > 0 ? ' · корр. №' . (int)$t['revision'] : '' ?>
            <span class="tag">Обычный (5/2)</span></h1>
        <a class="btn" href="/timesheet2?month=<?= e(substr($t['period'],0,7)) ?>&half=<?= e((string)(int)substr($t['period'],8)) ?>">← К табелям</a>
    </div>
    <p class="muted">Подразделение: <b><?= e($t['dept_name'] ?: 'вся организация') ?></b>.
        Заполнение по <b>презумпции явки</b>: «Я» проставлена на рабочие дни, «В» — на сб/вс, «О» — на отпуск.
        Отметьте только отсутствия и иные коды. Часы для обычного табеля не учитываются.</p>
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/regenerate" style="margin:0">
        <?= csrf_field() ?>
        <button class="btn" onclick="return confirm('Заполнить табель заново по презумпции явки? Текущие правки будут потеряны.')">↻ Заполнить заново (презумпция явки)</button>
    </form>
</section>

<section class="panel">
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/save">
        <?= csrf_field() ?>
        <div class="table-wrap tbl-wide">
            <table class="table tabel-grid" style="min-width:820px">
                <thead>
                <tr>
                    <th style="width:28px">✓</th>
                    <th style="text-align:left;min-width:200px">Должность, Ф.И.О.</th>
                    <?php foreach ($dates as $d): ?>
                        <th class="<?= $weekend($d) ? 'we' : '' ?>"><?= (int)substr($d,8,2) ?></th>
                    <?php endforeach; ?>
                    <th style="width:54px">Дней</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= count($dates) + 3 ?>" class="muted">Нет сотрудников. Нажмите «Заполнить заново».</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): $eid = (int)$r['employee_id']; $cells = $r['cells_arr'] ?? []; ?>
                <tr>
                    <td style="text-align:center"><input type="checkbox" name="keep[]" value="<?= $eid ?>" checked></td>
                    <td style="text-align:left"><?= e($r['full_name']) ?><br><i class="muted" style="font-size:.85em"><?= e($r['position']) ?></i></td>
                    <?php foreach ($dates as $i => $d): $cur = (string)($cells[$i]['c'] ?? ''); ?>
                        <td class="<?= $weekend($d) ? 'we' : '' ?>">
                            <select name="mark[<?= $eid ?>][<?= $i ?>]" class="cellsel">
                                <option value="">·</option>
                                <?php foreach ($order as $code): if (!isset($okud[$code])) continue; ?>
                                    <option value="<?= e($code) ?>" title="<?= e($okud[$code][0]) ?>" <?= $cur === $code ? 'selected' : '' ?>><?= e($code) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php endforeach; ?>
                    <td style="text-align:center"><b class="dcount"><?= (int)$r['days'] ?></b></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn btn-primary">💾 Сохранить</button>
            <span class="muted">Снимите галочку слева, чтобы исключить сотрудника из табеля.</span>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Подписать электронной подписью</h2>
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/sign" class="grid-form">
        <?= csrf_field() ?>
        <label>Вид подписи
            <select name="sign_type">
                <?php foreach ($signTypes as $sv => $sl):
                    $hasCert = $sv === 'PEP' || array_filter($certs, fn($c) => $c['sign_type'] === $sv); ?>
                    <option value="<?= $sv ?>" <?= !$hasCert ? 'disabled' : '' ?>><?= e($sl) ?><?= !$hasCert ? ' — нет сертификата' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Пароль учётной записи (подтверждение)
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn btn-gold" onclick="return confirm('Подписать табель? После подписания изменения возможны только корректировочным табелем.')">🖋 Подписать</button>
    </form>
    <p class="muted">ПЭП выпускается системой автоматически при первом подписании. Для УНЭП/УКЭП сертификат
        регистрирует администратор (Оргструктура → Сертификаты ЭП). Подписанный табель попадает в расчёт ЗП
        и отображается листом 0504421 со штампом подписи.</p>
</section>

<style>
  table.tabel-grid th, table.tabel-grid td{padding:3px 4px;text-align:center;font-size:.9em}
  table.tabel-grid th.we, table.tabel-grid td.we{background:#f2f3f7}
  select.cellsel{padding:2px 1px;font-size:.92em;min-width:42px;border:1px solid #c7cbd6;border-radius:4px;background:#fff}
</style>
<script>
// живой пересчёт «Дней» по worked-кодам (OKUD_CODES: worked=true)
(function () {
  var worked = <?= json_encode(array_values(array_filter(array_keys($okud), fn($c) => $okud[$c][1])), JSON_UNESCAPED_UNICODE) ?>;
  var wset = {}; worked.forEach(function (c) { wset[c] = 1; });
  function recount(tr) {
    var n = 0;
    tr.querySelectorAll('select.cellsel').forEach(function (s) {
      var v = s.value; if (!v) return;
      if (wset[v.split('/')[0]]) n++;
    });
    var c = tr.querySelector('.dcount'); if (c) c.textContent = n;
  }
  document.querySelectorAll('table.tabel-grid tbody tr').forEach(function (tr) {
    tr.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('cellsel')) recount(tr); });
  });
})();
</script>
