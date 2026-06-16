<h1>Распределение проверки</h1>

<section class="panel">
    <h2>Загрузить список</h2>
    <form id="uploadForm" class="form-inline">
        <label>Название списка<input type="text" id="upName" placeholder="напр. Вьетнам"></label>
        <label class="file-btn" style="align-self:flex-end">📎 Выбрать файл (docx/xlsx/csv)
            <input type="file" id="upFile" accept=".docx,.xlsx,.csv,.txt,.xls" required></label>
        <span id="upFileName" class="muted">файл не выбран</span>
        <button class="btn btn-primary" id="upBtn" type="submit">Загрузить</button>
    </form>
    <div id="upProgressWrap" style="display:none;margin-top:10px">
        <progress id="upProgress" value="0" max="100" style="width:100%"></progress>
        <div class="muted" id="upStatus">Загрузка…</div>
    </div>
    <div id="upResult" style="margin-top:8px"></div>

    <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:12px">
        <h3 style="margin:0 0 6px">Или ввести вручную</h3>
        <form method="post" action="/manager/manual">
            <?= csrf_field() ?>
            <label style="max-width:320px">Название списка
                <input type="text" name="name" placeholder="напр. Ручной ввод">
            </label>
            <label style="display:block;margin-top:8px">Рег. номера
                <textarea name="regs" rows="3" style="font-family:monospace"
                    placeholder="RUS-0001/26 - RUS-0009/26&#10;RUS-00001/26, RUS-00005/26, RUS-00007/26"></textarea>
            </label>
            <p class="muted" style="margin:4px 0 8px">Формат: <code>КОД-НОМЕР/ГОД</code>. Диапазон — через тире:
                <code>RUS-0001/26 - RUS-0009/26</code> (9 анкет). Перечисление — через запятую или с новой строки.
                Дубликаты пропускаются.</p>
            <button class="btn btn-primary" type="submit">Добавить анкеты</button>
        </form>
    </div>

    <?php if ($lists): ?>
    <table class="table" style="margin-top:12px">
        <thead><tr><th>Список</th><th class="num">Всего</th><th class="num">Не распред.</th><th class="num">Проверено</th></tr></thead>
        <tbody>
        <?php foreach ($lists as $l): ?>
            <tr><td><?= e($l['name']) ?></td><td class="num"><?= (int)$l['total'] ?></td><td class="num"><?= (int)$l['unassigned'] ?></td><td class="num"><?= (int)$l['checked'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Перераспределение</h2>
    <div class="xfer-controls">
        <label>Фильтр — список
            <select id="fList"><option value="">все списки</option>
                <?php foreach ($lists as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Страна
            <select id="fCountry"><option value="">все</option>
                <?php foreach ($allCountries as $c): ?><option value="<?= e($c['country_code']) ?>"><?= e($c['country_code']) ?> <?= e($c['name'] ?? '') ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Поиск номера<input type="text" id="fSearch" placeholder="напр. VNM-105"></label>
        <label>Размер пачки<input type="number" id="batchSize" value="20" min="1" style="max-width:90px"></label>
    </div>

    <div class="xfer">
        <!-- ИСТОЧНИК -->
        <div class="xfer-col">
            <div class="xfer-head">
                <select id="srcOwner" class="owner-sel"></select>
                <span class="muted" id="srcCount">—</span>
            </div>
            <div class="xfer-mini">
                <button type="button" class="btn btn-mini" onclick="batch('src')">+ пачка</button>
                <button type="button" class="btn btn-mini" onclick="allNone('src',true)">все</button>
                <button type="button" class="btn btn-mini" onclick="allNone('src',false)">снять</button>
                <span class="muted" id="srcSel">выбрано 0</span>
            </div>
            <div class="xfer-list" id="srcList"></div>
        </div>

        <!-- КНОПКИ -->
        <div class="xfer-mid">
            <button type="button" class="btn btn-primary" onclick="move('right')" title="Передать выбранные в назначение">Передать →</button>
            <button type="button" class="btn" onclick="move('left')" title="Забрать выбранные из назначения">← Забрать</button>
        </div>

        <!-- НАЗНАЧЕНИЕ -->
        <div class="xfer-col">
            <div class="xfer-head">
                <select id="dstOwner" class="owner-sel"></select>
                <span class="muted" id="dstCount">—</span>
            </div>
            <div class="xfer-mini">
                <button type="button" class="btn btn-mini" onclick="batch('dst')">+ пачка</button>
                <button type="button" class="btn btn-mini" onclick="allNone('dst',true)">все</button>
                <button type="button" class="btn btn-mini" onclick="allNone('dst',false)">снять</button>
                <span class="muted" id="dstSel">выбрано 0</span>
            </div>
            <div class="xfer-list" id="dstList"></div>
        </div>
    </div>
    <p class="muted">Слева — источник (общая корзина или сотрудник), справа — назначение. Отмечайте галочками или
        кнопкой «+ пачка» (размер задаётся) и переносите. Проверенные досье не переносятся.</p>
</section>

<script>
(function(){
  var CSRF=<?= json_encode($csrf) ?>;
  var owners=[{key:'pool',name:'🗑 Не распределено'}<?php foreach($employees as $e): ?>,{key:'<?= (int)$e['id'] ?>',name:<?= json_encode($e['full_name']) ?>}<?php endforeach; ?>];
  var srcSel=document.getElementById('srcOwner'), dstSel=document.getElementById('dstOwner');
  owners.forEach(function(o){
    srcSel.add(new Option(o.name,o.key));
    dstSel.add(new Option(o.name,o.key));
  });
  dstSel.selectedIndex = owners.length>1?1:0; // по умолчанию первый сотрудник

  function filters(){ return 'list_id='+(document.getElementById('fList').value)+'&country='+encodeURIComponent(document.getElementById('fCountry').value)+'&q='+encodeURIComponent(document.getElementById('fSearch').value); }
  function ownerLabel(key,total){ var o=owners.find(function(x){return x.key==key;}); return (o?o.name:key)+' ('+total+')'; }

  function loadPanel(side){
    var sel=side=='src'?srcSel:dstSel;
    var owner=sel.value;
    var listEl=document.getElementById(side+'List');
    var countEl=document.getElementById(side+'Count');
    listEl.innerHTML='<div class="muted" style="padding:10px">Загрузка…</div>';
    fetch('/manager/items?owner='+owner+'&'+filters()+'&limit=400',{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();})
      .then(function(d){
        listEl.innerHTML='';
        d.items.forEach(function(it){
          var lab=document.createElement('label'); lab.className='xfer-item';
          lab.innerHTML='<input type="checkbox" value="'+it.id+'"> <span class="mono">'+it.reg_number+'</span> <span class="muted">'+it.country_code+'</span>'
            +(parseInt(it.recheck)?' <span class="tag off" title="повторная проверка после брака">🔁 повторная</span>':'');
          lab.querySelector('input').addEventListener('change',function(){ updSel(side); });
          listEl.appendChild(lab);
        });
        countEl.textContent='всего '+d.total+(d.shown<d.total?(', показано '+d.shown):'');
        // обновить подпись в селекте
        var opt=[].slice.call(sel.options).find(function(o){return o.value==owner;});
        if(opt){ opt.text=ownerLabel(owner,d.total); }
        updSel(side);
      });
  }
  function updSel(side){ var n=document.querySelectorAll('#'+side+'List input:checked').length; document.getElementById(side+'Sel').textContent='выбрано '+n; }
  window.batch=function(side){ var n=parseInt(document.getElementById('batchSize').value)||20; var done=0;
    document.querySelectorAll('#'+side+'List input').forEach(function(c){ if(!c.checked && done<n){ c.checked=true; done++; } }); updSel(side); };
  window.allNone=function(side,val){ document.querySelectorAll('#'+side+'List input').forEach(function(c){ c.checked=val; }); updSel(side); };
  window.move=function(dir){
    var from=dir=='right'?'src':'dst', to=dir=='right'?dstSel.value:srcSel.value;
    var ids=[].slice.call(document.querySelectorAll('#'+from+'List input:checked')).map(function(c){return c.value;});
    if(!ids.length){ alert('Ничего не выбрано'); return; }
    var body='to='+encodeURIComponent(to)+'&_csrf='+encodeURIComponent(CSRF)+ids.map(function(i){return '&ids[]='+i;}).join('');
    fetch('/manager/move',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body:body})
      .then(function(r){return r.json();}).then(function(){ loadPanel('src'); loadPanel('dst'); });
  };

  ['fList','fCountry','fSearch'].forEach(function(id){ document.getElementById(id).addEventListener('change',function(){ loadPanel('src'); loadPanel('dst'); }); });
  document.getElementById('fSearch').addEventListener('keyup',function(e){ if(e.key==='Enter'){ loadPanel('src'); loadPanel('dst'); }});
  srcSel.addEventListener('change',function(){ loadPanel('src'); });
  dstSel.addEventListener('change',function(){ loadPanel('dst'); });
  loadPanel('src'); loadPanel('dst');

  // ---- Загрузка с прогрессом ----
  var fileInput=document.getElementById('upFile');
  fileInput.addEventListener('change',function(){ document.getElementById('upFileName').textContent = this.files.length? this.files[0].name : 'файл не выбран'; });
  document.getElementById('uploadForm').addEventListener('submit',function(e){
    e.preventDefault();
    if(!fileInput.files.length){ alert('Выберите файл'); return; }
    var fd=new FormData(); fd.append('_csrf',CSRF); fd.append('name',document.getElementById('upName').value); fd.append('file',fileInput.files[0]);
    var xhr=new XMLHttpRequest(); xhr.open('POST','/manager/upload'); xhr.setRequestHeader('X-Requested-With','fetch');
    document.getElementById('upProgressWrap').style.display='block';
    document.getElementById('upBtn').disabled=true;
    xhr.upload.onprogress=function(ev){ if(ev.lengthComputable){ var p=Math.round(ev.loaded/ev.total*100); document.getElementById('upProgress').value=p; document.getElementById('upStatus').textContent='Загрузка файла… '+p+'%'; if(p>=100){ document.getElementById('upStatus').textContent='Обработка списка на сервере…'; } } };
    xhr.onload=function(){
      document.getElementById('upBtn').disabled=false;
      try{ var d=JSON.parse(xhr.responseText);
        document.getElementById('upResult').innerHTML='<div class="flash flash-'+(d.ok?'success':'error')+'">'+(d.message||'Готово')+'</div>';
      }catch(err){ document.getElementById('upResult').innerHTML='<div class="flash flash-error">Ошибка загрузки</div>'; }
      document.getElementById('upProgressWrap').style.display='none';
      setTimeout(function(){ location.reload(); }, 1200);
    };
    xhr.onerror=function(){ document.getElementById('upBtn').disabled=false; document.getElementById('upResult').innerHTML='<div class="flash flash-error">Ошибка сети</div>'; };
    xhr.send(fd);
  });
})();
</script>
