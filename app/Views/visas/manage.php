<h1>Визы: загрузка и распределение</h1>

<section class="panel">
    <h2>Загрузить ходатайства (Word)</h2>
    <form method="post" action="/visas/upload" enctype="multipart/form-data" class="form-inline">
        <?= csrf_field() ?>
        <label>Название партии<input type="text" name="name" placeholder="напр. СДН-010"></label>
        <label class="file-btn" style="align-self:flex-end">📎 Файлы .docx (можно несколько)
            <input type="file" name="files[]" accept=".docx" multiple required
                   onchange="var n=this.files.length; document.getElementById('visaFiles').textContent = n? (n===1? this.files[0].name : 'выбрано файлов: '+n) : '';"></label>
        <span id="visaFiles" class="muted" style="align-self:flex-end"></span>
        <button class="btn btn-primary">Загрузить и распознать</button>
    </form>
    <p class="muted">Каждая таблица-анкета в файле распознаётся в строку (по закладкам Word: №, ФИО, паспорт, адрес…).
        Рекомендуемый порядок: загрузка → 🤖 ИИ-подстановка адресов → распределение специалистам.</p>

    <details style="margin-top:6px">
        <summary style="cursor:pointer;font-weight:600">⚙ Базовые настройки бланка (для новых партий)</summary>
        <form method="post" action="/visas/defaults" class="form-inline" style="align-items:flex-end;margin-top:10px">
            <?= csrf_field() ?>
            <label>Дата письма («от …»)<input type="text" name="letter_date" value="<?= e($defaults['letter_date']) ?>" placeholder="02.05.25" style="max-width:120px"></label>
            <label>Въезд в Россию с<input type="text" name="entry_date" value="<?= e($defaults['entry_date']) ?>" placeholder="15.02.25" style="max-width:120px"></label>
            <label>Пребывание по<input type="text" name="stay_date" value="<?= e($defaults['stay_date']) ?>" placeholder="15.05.26" style="max-width:120px"></label>
            <label>Подписант (М.П. и Ф.И.О.)<input type="text" name="signer" value="<?= e($defaults['signer']) ?>" placeholder="В.В. СУЩИК" style="max-width:180px"></label>
            <button class="btn btn-primary">Сохранить базовые</button>
        </form>
        <p class="muted" style="margin:6px 0 0;font-size:.78rem">Эти значения подставляются в бланк каждой <strong>новой</strong> партии. Для отдельной партии их всегда можно переопределить кнопкой «⚙ Бланк» в её строке. Уже созданные партии не меняются.</p>
    </details>
</section>

<section class="panel">
    <h2>Партии</h2>
    <table class="table">
        <thead><tr><th>Партия</th><th class="num">Анкет</th><th class="num">Без ИИ-адреса</th><th class="num">Не распред.</th><th class="num">Проверено</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td><strong><?= e($b['name']) ?></strong><br><span class="muted" style="font-size:.76rem"><?= e(substr((string)$b['created_at'],0,16)) ?></span></td>
                <td class="num"><?= (int)$b['total'] ?></td>
                <td class="num <?= (int)$b['no_ai']?'minus':'' ?>"><?= (int)$b['no_ai'] ?></td>
                <td class="num"><?= (int)$b['unassigned'] ?></td>
                <td class="num"><?= (int)$b['checked'] ?></td>
                <td>
                    <?php if ((int)$b['no_ai'] && $aiReady): ?>
                        <button class="btn btn-mini btn-primary" onclick="runAi(<?= (int)$b['id'] ?>, this)">🤖 Подставить адреса</button>
                    <?php endif; ?>
                    <a class="btn btn-mini" href="/visas/batch/<?= (int)$b['id'] ?>/rows">📋 Строки</a>
                    <button type="button" class="btn btn-mini" onclick="var r=document.getElementById('bp<?= (int)$b['id'] ?>');r.style.display=r.style.display==='none'?'':'none'">⚙ Бланк</button>
                    <a class="btn btn-mini btn-gold" href="/visas/batch/<?= (int)$b['id'] ?>/zip">⬇ DOCX (ZIP)</a>
                    <a class="btn btn-mini btn-primary" href="/visas/batch/<?= (int)$b['id'] ?>/pdf">⬇ PDF (как в Word)</a>
                </td>
            </tr>
            <tr id="bp<?= (int)$b['id'] ?>" style="display:none">
                <td colspan="6" style="background:rgba(38,54,139,.04)">
                    <form method="post" action="/visas/batch/<?= (int)$b['id'] ?>/params" class="form-inline" style="align-items:flex-end">
                        <?= csrf_field() ?>
                        <label>Дата письма («от …»)<input type="text" name="letter_date" value="<?= e($b['letter_date'] ?? '') ?>" placeholder="02.05.25" style="max-width:120px"></label>
                        <label>Въезд в Россию с<input type="text" name="entry_date" value="<?= e($b['entry_date'] ?? '') ?>" placeholder="15.02.25" style="max-width:120px"></label>
                        <label>Пребывание по<input type="text" name="stay_date" value="<?= e($b['stay_date'] ?? '') ?>" placeholder="15.05.26" style="max-width:120px"></label>
                        <label>Подписант (М.П. и Ф.И.О.)<input type="text" name="signer" value="<?= e($b['signer'] ?? '') ?>" placeholder="В.В. СУЩИК" style="max-width:180px"></label>
                        <button class="btn btn-mini btn-primary">Сохранить бланк</button>
                    </form>
                    <p class="muted" style="margin:6px 0 0;font-size:.76rem">Подставляется в официальный бланк МИД при выгрузке DOCX — сам шаблон не изменяется. Подписант заменяется во всех местах бланка.</p>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?><tr><td colspan="6" class="muted">Партий нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if (!$aiReady): ?>
        <p class="muted">⚠ ИИ-подстановка недоступна: укажите ключ и модель OpenRouter в <a href="/admin/settings">настройках</a>.</p>
    <?php endif; ?>
    <div id="aiStatus" class="muted"></div>
</section>

<section class="panel">
    <h2>Распределение строк специалистам</h2>
    <div class="xfer-controls">
        <label>Партия
            <select id="fBatch"><option value="">все</option>
                <?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
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
var owners=[{key:'pool',name:'🗑 Не распределено'}<?php foreach($specialists as $s2): ?>,{key:'<?= (int)$s2['id'] ?>',name:<?= json_encode($s2['full_name'].' (ост. '.$s2['remaining'].')', JSON_UNESCAPED_UNICODE) ?>}<?php endforeach; ?>];
var srcSel=document.getElementById('srcOwner'), dstSel=document.getElementById('dstOwner');
owners.forEach(function(o){ srcSel.add(new Option(o.name,o.key)); dstSel.add(new Option(o.name,o.key)); });
dstSel.selectedIndex = owners.length>1?1:0;
function filters(){ return 'batch_id='+(document.getElementById('fBatch').value)+'&q='+encodeURIComponent(document.getElementById('fSearch').value); }
function loadPanel(side){
  var owner=(side=='src'?srcSel:dstSel).value, listEl=document.getElementById(side+'List');
  listEl.innerHTML='<div class="muted" style="padding:10px">Загрузка…</div>';
  fetch('/visas/items?owner='+owner+'&'+filters(),{headers:{'X-Requested-With':'fetch'}})
    .then(r=>r.json()).then(function(d){
      listEl.innerHTML='';
      d.items.forEach(function(it){
        var lab=document.createElement('label'); lab.className='xfer-item';
        lab.innerHTML='<input type="checkbox" value="'+it.id+'"> <span class="mono">'+(it.out_no||('#'+it.id))+'</span> '+(it.surname_lat||'')+' <span class="muted">'+(it.citizenship||'')+'</span>';
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
  fetch('/visas/move',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
    body:'to='+encodeURIComponent(to)+'&_csrf='+encodeURIComponent(CSRF)+ids.map(i=>'&ids[]='+i).join('')})
    .then(r=>r.json()).then(function(){ loadPanel('src'); loadPanel('dst'); });
}
['fBatch','fSearch'].forEach(function(id){ document.getElementById(id).addEventListener('change',function(){ loadPanel('src'); loadPanel('dst'); }); });
document.getElementById('fSearch').addEventListener('keyup',function(e){ if(e.key==='Enter'){ loadPanel('src'); loadPanel('dst'); }});
srcSel.addEventListener('change',function(){ loadPanel('src'); });
dstSel.addEventListener('change',function(){ loadPanel('dst'); });
loadPanel('src'); loadPanel('dst');

// ИИ-подстановка: цикл пакетов с прогрессом
function runAi(batchId, btn){
  btn.disabled=true;
  var st=document.getElementById('aiStatus');
  var total=0;
  function step(){
    fetch('/visas/ai-batch',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
      body:'_csrf='+encodeURIComponent(CSRF)+'&batch_id='+batchId})
      .then(r=>r.json()).then(function(d){
        if(!d.ok){ st.textContent='⚠ '+(d.message||'ошибка'); btn.disabled=false; return; }
        total+=d.processed;
        st.textContent='🤖 Обработано адресов: '+total+', осталось: '+d.remaining+'…';
        if(d.remaining>0 && d.processed>0){ setTimeout(step, 1500); }
        else { st.textContent='✓ ИИ-подстановка завершена: '+total+' адресов.'; setTimeout(function(){location.reload();},1200); }
      }).catch(function(){ st.textContent='⚠ Ошибка сети'; btn.disabled=false; });
  }
  st.textContent='🤖 Запуск…';
  step();
}
</script>
