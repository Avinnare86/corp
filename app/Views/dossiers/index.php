<h1>Проверка досье</h1>

<div class="tabs">
    <a class="tab active" href="/dossiers">К проверке</a>
    <a class="tab" href="/dossiers/checked">Проверенные<?= $checkedTotal ? ' (' . (int)$checkedTotal . ')' : '' ?></a>
</div>

<div class="cards">
    <div class="card"><div class="card-label">Осталось проверить</div><div class="card-value big" id="cntLeft"><?= (int) $pendingTotal ?></div></div>
    <div class="card"><div class="card-label">Проверено сегодня</div><div class="card-value big" id="cntDone"><?= (int) $doneToday ?></div></div>
</div>

<?php if (!$working): ?>
<section class="panel attendance" style="border-left:4px solid var(--accent)">
    <div>
        <strong>Рабочий день не открыт</strong>
        <p class="muted" style="margin:4px 0 0">Чтобы отмечать досье, сначала приступите к работе — день засчитается в табель.</p>
    </div>
    <form method="post" action="/day/open"><?= csrf_field() ?><button class="btn btn-primary">▶ Приступить к работе</button></form>
</section>
<?php endif; ?>

<section class="panel">
    <p class="muted" style="margin-bottom:12px">«Без замечаний» — если всё корректно. «Причины» — выбрать одну или несколько доработок.
        Можно отмечать по одному или выбрать пачкой и применить массово.</p>

    <?php if ($pending && $working): ?>
    <div class="xfer-mini" style="margin-bottom:10px">
        Пачка <input type="number" id="batchSize" value="20" min="1" style="max-width:70px;display:inline-block;width:auto">
        <button type="button" class="btn btn-mini" onclick="batch()">+ пачка</button>
        <button type="button" class="btn btn-mini" onclick="allNone(true)">все</button>
        <button type="button" class="btn btn-mini" onclick="allNone(false)">снять</button>
        <span class="muted" id="selCount">выбрано 0</span>
        <span style="margin-left:auto"></span>
        <button type="button" class="btn btn-mini btn-gold" onclick="bulkOk()">✓ Без замечаний — выбранным</button>
        <button type="button" class="btn btn-mini btn-primary" onclick="bulkFix()">✎ Причины — выбранным</button>
    </div>
    <?php endif; ?>

    <?php if (!$pending): ?><p class="muted">Нет назначенных непроверенных досье. Ожидайте распределения от менеджера.</p><?php endif; ?>

    <table class="table" id="pendingTable">
        <thead><tr><th style="width:34px"></th><th>Рег. номер</th><th>Страна</th><th>План приема</th><th style="width:280px">Отметка</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $p): $id=(int)$p['id']; ?>
            <tr data-id="<?= $id ?>" data-reg="<?= e($p['reg_number']) ?>">
                <td><input type="checkbox" class="rowchk" onchange="updSel()"></td>
                <td class="mono"><strong><?= e($p['reg_number']) ?></strong></td>
                <td><?= e($p['country_name'] ?? $p['country_code']) ?></td>
                <?php $al = arrival_label($p['arrival_code'] ?? null, $p['arrival_detail'] ?? null); ?>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($al) ?>"><?= $al !== '' ? e($al) : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($working): ?>
                        <button class="btn btn-mini btn-gold" onclick="saveOne(<?= $id ?>,[])">✓ Без замечаний</button>
                        <button class="btn btn-mini btn-primary" onclick="openPicker({type:'one',id:<?= $id ?>},[])">✎ Причины</button>
                    <?php else: ?>
                        <span class="muted">день не открыт</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pendingTotal > count($pending)): ?>
        <p class="muted">Показаны первые <?= count($pending) ?> из <?= (int)$pendingTotal ?>.</p>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../partials/picker.php'; ?>
<div id="toast" class="toast"></div>

<script>
(function(){
  var CSRF=<?= json_encode(\App\Core\Auth::csrf()) ?>;
  window.updSel=function(){ document.getElementById('selCount').textContent='выбрано '+document.querySelectorAll('.rowchk:checked').length; };
  window.batch=function(){ var n=parseInt(document.getElementById('batchSize').value)||20,d=0;
    document.querySelectorAll('#pendingTable tbody tr').forEach(function(tr){ var c=tr.querySelector('.rowchk'); if(c&&!c.checked&&d<n){c.checked=true;d++;} }); updSel(); };
  window.allNone=function(v){ document.querySelectorAll('.rowchk').forEach(function(c){c.checked=v;}); updSel(); };
  function selRows(){ return [].slice.call(document.querySelectorAll('#pendingTable tbody tr')).filter(function(tr){var c=tr.querySelector('.rowchk');return c&&c.checked;}).map(function(tr){return parseInt(tr.dataset.id);}); }

  function toast(m){ var t=document.getElementById('toast'); t.textContent=m; t.classList.add('show'); setTimeout(function(){t.classList.remove('show');},2400); }
  function removeRow(tr){ tr.classList.add('row-saved'); setTimeout(function(){ tr.style.transition='opacity .4s'; tr.style.opacity='0'; setTimeout(function(){tr.remove();},400); },500); }
  function bump(n){ var l=document.getElementById('cntLeft'),d=document.getElementById('cntDone'); l.textContent=Math.max(0,parseInt(l.textContent)-n); d.textContent=parseInt(d.textContent)+n; }
  function label(st,reasons){ return st==='ok' ? 'Без замечаний' : reasons; }
  function body(ids){ return '_csrf='+encodeURIComponent(CSRF)+ids.map(function(i){return '&comment_id[]='+i;}).join(''); }

  window.saveOne=function(id, ids){
    fetch('/dossiers/'+id+'/check',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body:body(ids)})
      .then(function(r){return r.json();}).then(function(d){ if(!d.ok){toast(d.message||'Не сохранилось');return;}
        var tr=document.querySelector('tr[data-id="'+id+'"]'); if(tr){ tr.cells[3].innerHTML='<span class="saved-mark">✓ '+label(d.status,d.reasons)+'</span>'; removeRow(tr);} bump(1); toast('✓ '+d.reg+' — '+label(d.status,d.reasons)); });
  };
  window.bulkOk=function(){ doBulk([]); };
  window.bulkFix=function(){ if(!selRows().length){alert('Сначала выберите досье галочками');return;} openPicker({type:'bulk'},[]); };
  function doBulk(ids){
    var rows=selRows(); if(!rows.length){alert('Сначала выберите досье галочками');return;}
    var b=body(ids)+rows.map(function(i){return '&ids[]='+i;}).join('');
    fetch('/dossiers/bulk',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body:b})
      .then(function(r){return r.json();}).then(function(d){ if(!d.ok){toast(d.message||'Не сохранилось');return;}
        d.ids.forEach(function(id){ var tr=document.querySelector('tr[data-id="'+id+'"]'); if(tr){ tr.cells[3].innerHTML='<span class="saved-mark">✓ '+label(d.status,d.reasons)+'</span>'; removeRow(tr);} });
        bump(d.count); toast('✓ Отмечено: '+d.count+' — '+label(d.status,d.reasons)); });
  }
  // вызывается из picker
  window.applyComments=function(target, ids){
    if(target && target.type==='one'){ saveOne(target.id, ids); }
    else if(target && target.type==='bulk'){ doBulk(ids); }
  };
})();
</script>
