<?php
$isFact = $mode === 'fact';
$wd = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
$link = fn($rg, $md) => '/shifts/edit?employee=' . (int)$emp['id'] . '&month=' . e($month) . '&range=' . $rg . '&mode=' . $md;
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/shifts?month=<?= e($month) ?>">← К списку</a>
    <h1 style="margin:0;font-size:1.15rem">График: <?= e($emp['full_name']) ?></h1>
    <span class="muted"><?= e($emp['dept_name'] ?? '—') ?> · ставка <?= money($rate) ?>/ч</span>
</div>

<section class="panel">
    <div class="form-inline" style="gap:14px;flex-wrap:wrap;margin-bottom:6px">
        <span>Режим:
            <a class="btn btn-mini <?= $isFact ? '' : 'btn-primary' ?>" href="<?= $link($range,'plan') ?>">План</a>
            <a class="btn btn-mini <?= $isFact ? 'btn-primary' : '' ?>" href="<?= $link($range,'fact') ?>">Факт</a>
        </span>
        <span>Период:
            <a class="btn btn-mini <?= $range==='h1'?'btn-primary':'' ?>" href="<?= $link('h1',$mode) ?>">1–15</a>
            <a class="btn btn-mini <?= $range==='h2'?'btn-primary':'' ?>" href="<?= $link('h2',$mode) ?>">16–конец</a>
            <a class="btn btn-mini <?= $range==='full'?'btn-primary':'' ?>" href="<?= $link('full',$mode) ?>">весь месяц</a>
        </span>
        <span class="muted"><?= e($month) ?></span>
    </div>
    <p class="muted" style="margin:0 0 8px">
        Вводите <strong>время начала и конца смены</strong> — дневные и ночные часы система считает сама по ночному окну
        <strong><?= e($nightStart) ?>–<?= e($nightEnd) ?></strong> (ТК ст.96). Смена через полночь (конец ≤ начало) относится к дню начала.
        Пустые дни — выходные.<?= $isFact ? ' Праздничные/сверхурочные часы — по ТК (оплата выше).' : '' ?>
    </p>

    <form method="post" action="/shifts/save">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="employee" value="<?= (int)$emp['id'] ?>">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <input type="hidden" name="range" value="<?= e($range) ?>">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <table class="table" id="shiftGrid" data-ns="<?= e($nightStart) ?>" data-ne="<?= e($nightEnd) ?>">
            <thead><tr>
                <th>Дата</th><th>День</th>
                <th>Начало</th><th>Конец</th><th class="num">Часы (дн/ночь)</th>
                <?php if ($isFact): ?><th class="num">Праздничные</th><th class="num">Сверхуроч.</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($dates as $dt): $row = $existing[$dt] ?? null;
                $start = $isFact ? ($row['fact_start'] ?? '') : ($row['plan_start'] ?? '');
                $end   = $isFact ? ($row['fact_end'] ?? '')   : ($row['plan_end'] ?? '');
                $dow = (int) date('w', strtotime($dt));
                $we = false;   // график 2/2: фиксированных выходных нет (воскресенье — рабочий день), не подсвечиваем
                $f = fn($v) => ($v === '' || (float)$v == 0) ? '' : rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');
            ?>
                <tr<?= $we ? ' style="background:#faf6ee"' : '' ?>>
                    <td class="mono"><?= e(date('d.m', strtotime($dt))) ?></td>
                    <td class="muted"><?= $wd[$dow] ?></td>
                    <td><input type="time" class="sh-start" name="d[<?= e($dt) ?>][start]" value="<?= e($start) ?>" style="width:108px"></td>
                    <td><input type="time" class="sh-end" name="d[<?= e($dt) ?>][end]" value="<?= e($end) ?>" style="width:108px"></td>
                    <td class="num sh-calc muted" style="white-space:nowrap;font-size:.85rem">—</td>
                    <?php if ($isFact): ?>
                        <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][holiday]" value="<?= e($f($row['holiday_hours'] ?? '')) ?>" style="width:72px;text-align:right"></td>
                        <td class="num"><input type="number" step="0.5" min="0" max="24" name="d[<?= e($dt) ?>][overtime]" value="<?= e($f($row['overtime_hours'] ?? '')) ?>" style="width:72px;text-align:right"></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="form-inline" style="margin-top:12px">
            <button class="btn btn-primary">💾 Сохранить <?= $isFact ? 'факт' : 'план' ?></button>
            <a class="btn" href="/shifts?month=<?= e($month) ?>">Готово</a>
        </div>
    </form>
</section>
<script>
(function(){
  var g = document.getElementById('shiftGrid'); if (!g) return;
  function m(t){ var x=/^(\d{1,2}):(\d{2})$/.exec((t||'').trim()); return x ? (+x[1])*60 + (+x[2]) : null; }
  function split(st, en, ns, ne){
    var s=m(st), e=m(en); if(s===null||e===null) return null;
    if(e<=s) e+=1440; var total=e-s; if(total<=0) return null;
    var nsM=m(ns), neM=m(ne); var iv=[];
    function push(a,b){ if(b>a) iv.push([a,b]); }
    if(neM<=nsM){ push(0,neM); push(nsM,1440+neM); push(nsM+1440,2*1440+neM); } else { push(nsM,neM); push(nsM+1440,neM+1440); }
    var night=0; iv.forEach(function(p){ night += Math.max(0, Math.min(e,p[1]) - Math.max(s,p[0])); });
    night=Math.min(night,total); var day=total-night;
    var fm=function(x){ return (Math.round(x/60*100)/100).toString(); };
    return {h:fm(total), d:fm(day), n:fm(night)};
  }
  var NS=g.getAttribute('data-ns'), NE=g.getAttribute('data-ne');
  function recalc(tr){
    var st=tr.querySelector('.sh-start').value, en=tr.querySelector('.sh-end').value;
    var cell=tr.querySelector('.sh-calc');
    var r=split(st,en,NS,NE);
    cell.textContent = r ? (r.h+' ч ('+r.d+'/'+r.n+')') : '—';
  }
  [].forEach.call(g.querySelectorAll('tbody tr'), function(tr){
    if(!tr.querySelector('.sh-start')) return;
    tr.querySelector('.sh-start').addEventListener('input', function(){recalc(tr);});
    tr.querySelector('.sh-end').addEventListener('input', function(){recalc(tr);});
    recalc(tr);
  });
})();
</script>
