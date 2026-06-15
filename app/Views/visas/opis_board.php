<div class="chat-head" style="display:flex;gap:10px;align-items:center">
    <h1 style="margin:0;font-size:1.2rem">Формирование описей по странам</h1>
    <a class="btn btn-mini" href="/visas/opis/list">Описи / Указания →</a>
    <a class="btn btn-mini" href="/visas/rework">МИД: на доработке →</a>
</div>

<section class="panel">
    <p class="muted" style="margin-top:0">Берутся <strong>проверенные</strong> анкеты (по всем партиям), ещё не включённые в опись.
        Выберите страну, отметьте анкеты и сформируйте опись — будут готовы ОПИСЬ, ОПИСЬ с подписью и гарантийное письмо.
        Анкеты, уже попавшие в опись, в списке не показываются.</p>

    <?php if (!$countries): ?>
        <p class="flash flash-error" style="margin:0">Нет проверенных анкет, готовых к формированию. Сначала специалисты должны проверить строки.</p>
    <?php else: ?>
    <div class="xfer-controls" style="margin-bottom:8px">
        <label>Страна (гражданство)
            <select id="country">
                <?php foreach ($countries as $c => $n): ?><option value="<?= e($c) ?>"><?= e($c) ?> (<?= (int)$n ?>)</option><?php endforeach; ?>
            </select>
        </label>
        <label>Поиск (№/фамилия)<input type="text" id="q"></label>
    </div>

    <div class="xfer-mini" style="margin-bottom:6px">
        <button type="button" class="btn btn-mini" onclick="selAll(true)">выбрать все</button>
        <button type="button" class="btn btn-mini" onclick="selAll(false)">снять</button>
        <span class="muted" id="selInfo">выбрано 0</span>
    </div>
    <div class="xfer-list" id="candList" style="max-height:340px;overflow:auto"></div>

    <form id="opisForm" method="post" action="/visas/opis/create" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="country" id="fCountry">
        <div id="idsBox"></div>
        <div class="grid-form" style="max-width:680px">
            <label>ФИО подписанта<input type="text" name="signer_name" value="<?= e($signerName) ?>"></label>
            <label>Должность подписанта<input type="text" name="signer_position" value="<?= e($signerPosition) ?>"></label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:8px">Сформировать опись (<span id="selBtn">0</span>)</button>
    </form>
    <?php endif; ?>
</section>

<script>
var sel = document.getElementById('country'), q = document.getElementById('q'), listEl = document.getElementById('candList');
function load(){
  if(!sel) return;
  listEl.innerHTML='<div class="muted" style="padding:10px">Загрузка…</div>';
  fetch('/visas/opis/items?country='+encodeURIComponent(sel.value)+'&q='+encodeURIComponent(q.value),{headers:{'X-Requested-With':'fetch'}})
    .then(r=>r.json()).then(function(d){
      listEl.innerHTML='';
      if(!d.items.length){ listEl.innerHTML='<div class="muted" style="padding:10px">Нет кандидатов.</div>'; updSel(); return; }
      d.items.forEach(function(it){
        var lab=document.createElement('label'); lab.className='xfer-item';
        lab.innerHTML='<input type="checkbox" class="ck" value="'+it.id+'"> <span class="mono">'+(it.out_no||('#'+it.id))+'</span> '+(it.surname_lat||'')+' <span class="muted">'+(it.citizenship||'')+'</span>';
        lab.querySelector('input').addEventListener('change',updSel);
        listEl.appendChild(lab);
      });
      updSel();
    });
}
function selAll(v){ document.querySelectorAll('#candList .ck').forEach(function(c){c.checked=v;}); updSel(); }
function updSel(){ var n=document.querySelectorAll('#candList .ck:checked').length; document.getElementById('selInfo').textContent='выбрано '+n; document.getElementById('selBtn').textContent=n; }
if(sel){
  sel.addEventListener('change',load);
  q.addEventListener('keyup',function(e){ if(e.key==='Enter') load(); });
  document.getElementById('opisForm').addEventListener('submit',function(e){
    var ids=[].slice.call(document.querySelectorAll('#candList .ck:checked')).map(c=>c.value);
    if(!ids.length){ e.preventDefault(); alert('Отметьте хотя бы одну анкету.'); return; }
    document.getElementById('fCountry').value=sel.value;
    var box=document.getElementById('idsBox'); box.innerHTML='';
    ids.forEach(function(i){ var h=document.createElement('input'); h.type='hidden'; h.name='ids[]'; h.value=i; box.appendChild(h); });
  });
  load();
}
</script>
