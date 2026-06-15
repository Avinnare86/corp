<div class="chat-head" style="display:flex;gap:10px;align-items:center">
    <h1 style="margin:0;font-size:1.2rem">МИД: строки на доработке</h1>
    <a class="btn btn-mini" href="/visas/opis/list">Описи / Указания →</a>
</div>

<section class="panel">
    <p class="muted" style="margin-top:0">Строки, по которым МИД отказал, переведены на повторную проверку. Распределите их специалистам —
        <strong>первоначальному проверяющему ту же строку назначить нельзя</strong>. Повторная проверка оплачивается как новая
        тому, кто её выполнит.</p>
    <div class="xfer-controls">
        <label>Поиск (№/фамилия)<input type="text" id="fSearch"></label>
        <label>Размер пачки<input type="number" id="batchSize" value="20" min="1" style="max-width:90px"></label>
    </div>
    <div class="xfer">
        <div class="xfer-col">
            <div class="xfer-head"><select id="srcOwner" class="owner-sel"></select><span class="muted" id="srcCount">—</span></div>
            <div class="xfer-mini">
                <button type="button" class="btn btn-mini" onclick="vbatch('src')">+ пачка</button>
                <button type="button" class="btn btn-mini" onclick="vall('src',true)">все</button>
                <button type="button" class="btn btn-mini" onclick="vall('src',false)">снять</button>
                <span class="muted" id="srcSel">выбрано 0</span>
            </div>
            <div class="xfer-list" id="srcList"></div>
        </div>
        <div class="xfer-mid">
            <button type="button" class="btn btn-primary" onclick="vmove('right')">Передать →</button>
            <button type="button" class="btn" onclick="vmove('left')">← Забрать</button>
        </div>
        <div class="xfer-col">
            <div class="xfer-head"><select id="dstOwner" class="owner-sel"></select><span class="muted" id="dstCount">—</span></div>
            <div class="xfer-mini">
                <button type="button" class="btn btn-mini" onclick="vbatch('dst')">+ пачка</button>
                <button type="button" class="btn btn-mini" onclick="vall('dst',true)">все</button>
                <button type="button" class="btn btn-mini" onclick="vall('dst',false)">снять</button>
                <span class="muted" id="dstSel">выбрано 0</span>
            </div>
            <div class="xfer-list" id="dstList"></div>
        </div>
    </div>
</section>

<script>
var CSRF=<?= json_encode($csrf) ?>;
var owners=[{key:'pool',name:'🗑 Не распределено'}<?php foreach($specialists as $s2): ?>,{key:'<?= (int)$s2['id'] ?>',name:<?= json_encode($s2['full_name'].' (доработка: '.(int)$s2['rework'].')', JSON_UNESCAPED_UNICODE) ?>}<?php endforeach; ?>];
var srcSel=document.getElementById('srcOwner'), dstSel=document.getElementById('dstOwner');
owners.forEach(function(o){ srcSel.add(new Option(o.name,o.key)); dstSel.add(new Option(o.name,o.key)); });
dstSel.selectedIndex = owners.length>1?1:0;
function filters(){ return 'q='+encodeURIComponent(document.getElementById('fSearch').value); }
function loadPanel(side){
  var owner=(side=='src'?srcSel:dstSel).value, listEl=document.getElementById(side+'List');
  listEl.innerHTML='<div class="muted" style="padding:10px">Загрузка…</div>';
  fetch('/visas/rework/items?owner='+owner+'&'+filters(),{headers:{'X-Requested-With':'fetch'}})
    .then(r=>r.json()).then(function(d){
      listEl.innerHTML='';
      d.items.forEach(function(it){
        var lab=document.createElement('label'); lab.className='xfer-item';
        lab.title=it.mid_refuse_note||'';
        lab.innerHTML='<input type="checkbox" value="'+it.id+'"> <span class="mono">'+(it.out_no||('#'+it.id))+'</span> '+(it.surname_lat||'')+' <span class="muted">'+(it.citizenship||'')+'</span> <span class="tag off" title="повторная проверка после отказа МИД">🔁</span>';
        lab.querySelector('input').addEventListener('change',function(){ updSel(side); });
        listEl.appendChild(lab);
      });
      document.getElementById(side+'Count').textContent='всего '+d.total+(d.shown<d.total?', показано '+d.shown:'');
      updSel(side);
    });
}
function updSel(side){ document.getElementById(side+'Sel').textContent='выбрано '+document.querySelectorAll('#'+side+'List input:checked').length; }
function vbatch(side){ var n=parseInt(document.getElementById('batchSize').value)||20,d=0;
  document.querySelectorAll('#'+side+'List input').forEach(function(c){ if(!c.checked&&d<n){c.checked=true;d++;} }); updSel(side); }
function vall(side,v){ document.querySelectorAll('#'+side+'List input').forEach(function(c){c.checked=v;}); updSel(side); }
function vmove(dir){
  var from=dir=='right'?'src':'dst', to=dir=='right'?dstSel.value:srcSel.value;
  var ids=[].slice.call(document.querySelectorAll('#'+from+'List input:checked')).map(c=>c.value);
  if(!ids.length){ alert('Ничего не выбрано'); return; }
  fetch('/visas/rework/move',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
    body:'to='+encodeURIComponent(to)+'&_csrf='+encodeURIComponent(CSRF)+ids.map(i=>'&ids[]='+i).join('')})
    .then(r=>r.json()).then(function(d){ if(d&&d.message){ alert(d.message); } loadPanel('src'); loadPanel('dst'); });
}
document.getElementById('fSearch').addEventListener('keyup',function(e){ if(e.key==='Enter'){ loadPanel('src'); loadPanel('dst'); }});
srcSel.addEventListener('change',function(){ loadPanel('src'); });
dstSel.addEventListener('change',function(){ loadPanel('dst'); });
loadPanel('src'); loadPanel('dst');
</script>
