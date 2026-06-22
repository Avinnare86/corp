<?php
$wd = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
$isFact = $mode === 'fact';
$link = fn($d, $m) => '/shifts?dept=' . (int)$d . '&month=' . e($month) . '&mode=' . $m;
?>
<h1>Сменный график (2/2 — колл-центр)</h1>
<p class="muted" style="margin-top:0">Задайте стандартные времена смен, затем в таблице кликом ставьте <b>Д</b> (дневная) / <b>Н</b> (ночная) / пусто (выходной).
    Часы и ночные считаются автоматически по ночному окну <b><?= e($nightStart) ?>–<?= e($nightEnd) ?></b>. Индивидуальный график сотрудника — по клику на его ФИО.</p>

<?php if ($canEdit): ?>
<section class="panel" style="padding-bottom:10px">
    <form method="post" action="/shifts/standard" class="form-inline" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="dept" value="<?= (int)$deptId ?>">
        <strong>Стандартные смены:</strong>
        <label>Дневная с<input type="time" name="day_start" value="<?= e($std['day'][0]) ?>" style="width:104px"></label>
        <label>по<input type="time" name="day_end" value="<?= e($std['day'][1]) ?>" style="width:104px"></label>
        <label>Ночная с<input type="time" name="night_start" value="<?= e($std['night'][0]) ?>" style="width:104px"></label>
        <label>по<input type="time" name="night_end" value="<?= e($std['night'][1]) ?>" style="width:104px"></label>
        <button class="btn btn-mini btn-primary">Сохранить стандарт</button>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <div class="form-inline" style="gap:14px;flex-wrap:wrap;margin-bottom:8px">
        <form method="get" action="/shifts" class="form-inline" style="gap:8px">
            <label>Месяц<input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()"></label>
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <label>Отдел
                <select name="dept" onchange="this.form.submit()">
                    <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id']===(int)$deptId?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </form>
        <span>Режим:
            <a class="btn btn-mini <?= $isFact?'':'btn-primary' ?>" href="<?= $link($deptId,'plan') ?>">План</a>
            <a class="btn btn-mini <?= $isFact?'btn-primary':'' ?>" href="<?= $link($deptId,'fact') ?>">Факт</a>
        </span>
        <a class="btn btn-mini" href="/shifts/grafik?dept=<?= (int)$deptId ?>&month=<?= e($month) ?>" target="_blank">📄 График сменности (печать)</a>
        <a class="btn btn-mini" href="/shifts/export?month=<?= e($month) ?>&range=full">⇩ Excel (часы)</a>
    </div>

    <?php if (!$depts): ?>
        <p class="muted">Нет отделов с сотрудниками на графике 2/2. Поставьте в карточке сотрудника режим «2/2 Call-центр».</p>
    <?php elseif (!$rows): ?>
        <p class="muted">В выбранном отделе нет активных сотрудников на графике 2/2.</p>
    <?php else: ?>
    <form method="post" action="/shifts/grid">
        <?= csrf_field() ?>
        <input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="dept" value="<?= (int)$deptId ?>"><input type="hidden" name="mode" value="<?= e($mode) ?>">
        <div class="table-scroll">
        <table class="table sg-grid" style="min-width:760px;font-size:.85rem">
            <thead><tr>
                <th style="min-width:180px">Сотрудник (<?= $isFact?'факт':'план' ?>)</th>
                <?php for ($d=1;$d<=$lastDay;$d++): $dow=(int)date('w', strtotime(sprintf('%s-%02d',$month,$d))); ?>
                    <th style="padding:3px 1px;text-align:center"><?= $d ?><br><span style="font-weight:400;font-size:.7rem;color:#888"><?= $wd[$dow] ?></span></th>
                <?php endfor; ?>
                <th class="num">Итого</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $eid=(int)$r['emp']['id']; ?>
                <tr>
                    <td><a href="/shifts/employee?id=<?= $eid ?>&month=<?= e($month) ?>"><?= e($r['emp']['full_name']) ?></a>
                        <br><span class="muted" style="font-size:.72rem"><?= e($r['emp']['position']) ?></span></td>
                    <?php for ($d=1;$d<=$lastDay;$d++): $dte=sprintf('%s-%02d',$month,$d); $c=$r['cells'][$dte]; $k=$c['kind']; ?>
                        <?php if (!empty($c['ro'])): ?>
                            <td class="sg-cell sg-o" title="Отпуск">О</td>
                        <?php elseif (!$canEdit): ?>
                            <td class="sg-cell sg-<?= e($k?:'off') ?>"><span class="sg-lbl"><?= ['day'=>'Д','night'=>'Н','ind'=>'И'][$k] ?? '' ?></span></td>
                        <?php else: ?>
                            <td class="sg-cell sg-<?= e($k?:'off') ?>" title="<?= $c['ind']!==''?e($c['ind']):'клик: Д → Н → выходной' ?>" onclick="sgCycle(this)"><span class="sg-lbl"><?= ['day'=>'Д','night'=>'Н','ind'=>'И'][$k] ?? '' ?></span><input type="hidden" name="g[<?= $eid ?>][<?= $dte ?>]" value="<?= e($k) ?>"></td>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <td class="num" style="white-space:nowrap"><strong><?= (int)$r['days'] ?></strong> дн<br><span class="muted" style="font-size:.74rem"><?= e(\App\Controllers\ShiftController::fmtHours((float)$r['hours'])) ?> ч<?= $r['night']>0?' / '.e(\App\Controllers\ShiftController::fmtHours((float)$r['night'])).' ноч':'' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($canEdit): ?>
        <div class="form-inline" style="margin-top:10px;gap:10px">
            <button class="btn btn-primary">💾 Сохранить <?= $isFact?'факт':'план' ?></button>
            <span class="muted">Д — дневная · Н — ночная · И — индивидуальное время (на странице сотрудника) · О — отпуск · пусто — выходной</span>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</section>

<style>
.sg-cell{text-align:center;padding:4px 2px;cursor:pointer;font-weight:700;user-select:none}
.sg-cell.sg-off{color:#cfcfcf;font-weight:400}
.sg-day{background:#e7f6ec;color:#1e7e34}
.sg-night{background:#e8eefc;color:#26368B}
.sg-ind{background:#fff3e0;color:#b9650f}
.sg-o{background:#f0f0f0;color:#777;cursor:default}
</style>
<script>
(function(){
  var LBL={'':'','day':'Д','night':'Н','ind':'И'}, ORDER=['','day','night'];
  window.sgCycle=function(td){
    var inp=td.querySelector('input'); if(!inp) return;
    var cur=inp.value, idx=ORDER.indexOf(cur), next=idx===-1?'day':ORDER[(idx+1)%ORDER.length];
    inp.value=next;
    var lbl=td.querySelector('.sg-lbl'); if(lbl) lbl.textContent=LBL[next];
    td.className='sg-cell sg-'+(next||'off');
    if(idx===-1) td.removeAttribute('title');
  };
})();
</script>
