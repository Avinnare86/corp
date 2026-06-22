<?php
$isFact = $mode === 'fact';
$wd = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
$elink = fn($m) => '/shifts/employee?id=' . (int)$emp['id'] . '&month=' . e($month) . '&mode=' . $m;
$ind = fn($k) => (!empty($emp[$k]) && preg_match('/^\d{1,2}:\d{2}$/', (string)$emp[$k])) ? (string)$emp[$k] : '';
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/shifts?dept=<?= (int)($emp['department_id'] ?? 0) ?>&month=<?= e($month) ?>">← К графику</a>
    <h1 style="margin:0;font-size:1.15rem">Индивидуальный график: <?= e($emp['full_name']) ?></h1>
    <span class="muted"><?= e($emp['dept_name'] ?? '—') ?> · <?= e($emp['position'] ?? '') ?></span>
</div>

<section class="panel">
    <h2 style="margin-top:0">Индивидуальные времена смен</h2>
    <p class="muted" style="margin-top:0">Если у сотрудника свои часы смен — задайте их здесь. Тогда в общей таблице его «Д»/«Н» считаются по этим временам.
        Пусто — используются стандартные (<?= e($std['day'][0]) ?>–<?= e($std['day'][1]) ?> день, <?= e($std['night'][0]) ?>–<?= e($std['night'][1]) ?> ночь).</p>
    <form method="post" action="/shifts/employee/save" class="form-inline" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>"><input type="hidden" name="month" value="<?= e($month) ?>">
        <label>Дневная с<input type="time" name="day_start" value="<?= e($ind('shift_day_start')) ?>" placeholder="<?= e($std['day'][0]) ?>" style="width:104px"></label>
        <label>по<input type="time" name="day_end" value="<?= e($ind('shift_day_end')) ?>" style="width:104px"></label>
        <label>Ночная с<input type="time" name="night_start" value="<?= e($ind('shift_night_start')) ?>" style="width:104px"></label>
        <label>по<input type="time" name="night_end" value="<?= e($ind('shift_night_end')) ?>" style="width:104px"></label>
        <button class="btn btn-mini btn-primary">Сохранить времена</button>
    </form>
</section>

<section class="panel">
    <h2 style="margin-top:0">Переопределение по дням <span class="muted" style="font-size:.8rem">(<?= $isFact ? 'факт' : 'индивидуальный план' ?>)</span></h2>
    <div class="form-inline" style="gap:10px;margin-bottom:6px">
        <span>Режим:
            <a class="btn btn-mini <?= $isFact?'':'btn-primary' ?>" href="<?= $elink('plan') ?>">План (своё время дня)</a>
            <a class="btn btn-mini <?= $isFact?'btn-primary':'' ?>" href="<?= $elink('fact') ?>">Факт</a>
        </span>
        <span class="muted"><?= e($month) ?> · ночное окно <?= e($nightStart) ?>–<?= e($nightEnd) ?></span>
    </div>
    <p class="muted" style="margin:0 0 8px">
        <?= $isFact
            ? 'Факт: фактическое время начала/конца смены за день; праздничные и сверхурочные часы — по ТК. Пусто — факт не задан.'
            : 'Индивидуальное время на конкретный день (перекрывает «Д»/«Н» из общей таблицы и помечается «И»). Очистите время — день вернётся под управление общей таблицы.' ?>
    </p>
    <form method="post" action="/shifts/employee/days">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>"><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="mode" value="<?= e($mode) ?>">
        <table class="table" id="empGrid" data-ns="<?= e($nightStart) ?>" data-ne="<?= e($nightEnd) ?>">
            <thead><tr>
                <th>Дата</th><th>День</th><th>Начало</th><th>Конец</th><th class="num">Часы (дн/ночь)</th>
                <?php if ($isFact): ?><th class="num">Праздничные</th><th class="num">Сверхуроч.</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($dates as $dt): $row = $existing[$dt] ?? null;
                if ($isFact) { $start = $row['fact_start'] ?? ''; $end = $row['fact_end'] ?? ''; }
                else { $isInd = $row && ($row['plan_kind'] ?? '') === 'ind'; $start = $isInd ? ($row['plan_start'] ?? '') : ''; $end = $isInd ? ($row['plan_end'] ?? '') : ''; }
                $dow = (int) date('w', strtotime($dt));
                $f = fn($v) => ($v === '' || (float)$v == 0) ? '' : rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');
            ?>
                <tr>
                    <td class="mono"><?= e(date('d.m', strtotime($dt))) ?></td>
                    <td class="muted"><?= $wd[$dow] ?></td>
                    <td><input type="time" class="eg-start" name="d[<?= e($dt) ?>][start]" value="<?= e($start) ?>" style="width:108px"></td>
                    <td><input type="time" class="eg-end" name="d[<?= e($dt) ?>][end]" value="<?= e($end) ?>" style="width:108px"></td>
                    <td class="num eg-calc muted" style="white-space:nowrap;font-size:.85rem">—</td>
                    <?php if ($isFact): ?>
                        <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][holiday]" value="<?= e($f($row['holiday_hours'] ?? '')) ?>" style="width:72px;text-align:right"></td>
                        <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][overtime]" value="<?= e($f($row['overtime_hours'] ?? '')) ?>" style="width:72px;text-align:right"></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="form-inline" style="margin-top:12px">
            <button class="btn btn-primary">💾 Сохранить <?= $isFact?'факт':'индивидуальный план' ?></button>
            <a class="btn" href="/shifts?dept=<?= (int)($emp['department_id'] ?? 0) ?>&month=<?= e($month) ?>">Готово</a>
        </div>
    </form>
</section>
<script>
(function(){
  var g=document.getElementById('empGrid'); if(!g) return;
  function m(t){ var x=/^(\d{1,2}):(\d{2})$/.exec((t||'').trim()); return x?(+x[1])*60+(+x[2]):null; }
  function split(st,en,ns,ne){ var s=m(st),e=m(en); if(s===null||e===null) return null; if(e<=s)e+=1440; var total=e-s; if(total<=0) return null;
    var nsM=m(ns),neM=m(ne),iv=[]; function p(a,b){ if(b>a) iv.push([a,b]); }
    if(neM<=nsM){ p(0,neM); p(nsM,1440+neM); p(nsM+1440,2*1440+neM); } else { p(nsM,neM); p(nsM+1440,neM+1440); }
    var night=0; iv.forEach(function(q){ night+=Math.max(0,Math.min(e,q[1])-Math.max(s,q[0])); }); night=Math.min(night,total);
    var fm=function(x){ return (Math.round(x/60*100)/100).toString(); }; return {h:fm(total),d:fm(total-night),n:fm(night)}; }
  var NS=g.getAttribute('data-ns'), NE=g.getAttribute('data-ne');
  [].forEach.call(g.querySelectorAll('tbody tr'), function(tr){
    var s=tr.querySelector('.eg-start'), e=tr.querySelector('.eg-end'), c=tr.querySelector('.eg-calc'); if(!s) return;
    function rc(){ var r=split(s.value,e.value,NS,NE); c.textContent=r?(r.h+' ч ('+r.d+'/'+r.n+')'):'—'; }
    s.addEventListener('input',rc); e.addEventListener('input',rc); rc();
  });
})();
</script>
