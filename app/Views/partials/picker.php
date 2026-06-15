<?php /* Двухколоночный выбор причин-доработок. Использует $pickComments, $pickCategories, $pickTop.
        Страница должна определить window.applyComments(idsArray). */ ?>
<div class="modal-overlay" id="pickerModal">
    <div class="modal-box wide">
        <div class="modal-head">
            <strong>Доработка <span id="pickReg"></span></strong>
            <span class="muted" id="pickSelCount" style="margin-left:auto">выбрано 0</span>
            <button class="btn btn-mini" onclick="closePicker()">✕</button>
        </div>
        <input type="text" id="pickSearch" placeholder="Поиск по всем причинам…" autocomplete="off">
        <div class="picker2">
            <div class="picker-cats" id="pickCats"></div>
            <div class="picker-comments" id="pickCommentsBox"></div>
        </div>
        <div class="picker-foot">
            <button type="button" class="btn btn-gold" onclick="pickApply(true)">Без замечаний</button>
            <button type="button" class="btn btn-primary" onclick="pickApply(false)">Применить (<span id="pickN">0</span>)</button>
        </div>
    </div>
</div>

<script>
(function(){
  var COMMENTS=<?= json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'text'=>$c['text'],'cat'=>$c['category']], $pickComments), JSON_UNESCAPED_UNICODE) ?>;
  var CATS=<?= json_encode($pickCategories, JSON_UNESCAPED_UNICODE) ?>;
  var TOP=<?= json_encode(array_map(fn($t)=>(int)$t['id'], $pickTop)) ?>;
  var modal=document.getElementById('pickerModal');
  var selected=new Set(), curCat=null;

  function lower(s){ return (s||'').toLowerCase(); }
  function commentsFor(cat){
    if(cat==='__top__'){ return COMMENTS.filter(function(c){ return TOP.indexOf(c.id)>=0; }); }
    if(cat==='__all__'){ return COMMENTS; }
    return COMMENTS.filter(function(c){ return c.cat===cat; });
  }
  function renderCats(){
    var box=document.getElementById('pickCats'); box.innerHTML='';
    var list=[];
    if(TOP.length) list.push({k:'__top__',n:'⭐ ТОП причин'});
    list.push({k:'__all__',n:'Все причины'});
    CATS.forEach(function(c){ list.push({k:c,n:c}); });
    list.forEach(function(it){
      var d=document.createElement('div'); d.className='pcat'+(it.k===curCat?' active':''); d.textContent=it.n;
      d.onclick=function(){ document.getElementById('pickSearch').value=''; curCat=it.k; renderCats(); renderComments(commentsFor(it.k)); };
      box.appendChild(d);
    });
  }
  function renderComments(items){
    var box=document.getElementById('pickCommentsBox'); box.innerHTML='';
    if(!items.length){ box.innerHTML='<div class="muted" style="padding:12px">Нет причин в этой категории.</div>'; return; }
    items.forEach(function(c){
      var lab=document.createElement('label'); lab.className='pcomment';
      var ck=document.createElement('input'); ck.type='checkbox'; ck.checked=selected.has(c.id);
      ck.onchange=function(){ if(ck.checked) selected.add(c.id); else selected.delete(c.id); updCount(); };
      lab.appendChild(ck); lab.appendChild(document.createTextNode(' '+c.text));
      box.appendChild(lab);
    });
  }
  function updCount(){ document.getElementById('pickSelCount').textContent='выбрано '+selected.size; document.getElementById('pickN').textContent=selected.size; }

  document.getElementById('pickSearch').addEventListener('input',function(){
    var q=lower(this.value);
    if(q===''){ renderComments(commentsFor(curCat||'__all__')); return; }
    renderComments(COMMENTS.filter(function(c){ return lower(c.text).indexOf(q)>=0; }));
    document.querySelectorAll('#pickCats .pcat').forEach(function(d){ d.classList.remove('active'); });
  });

  window.openPicker=function(target, preIds){
    window.__pickTarget=target;
    selected=new Set((preIds||[]).map(Number));
    document.getElementById('pickSearch').value='';
    curCat = TOP.length ? '__top__' : '__all__';
    renderCats(); renderComments(commentsFor(curCat)); updCount();
    modal.classList.add('open');
  };
  window.closePicker=function(){ modal.classList.remove('open'); };
  modal.addEventListener('click',function(e){ if(e.target===modal) closePicker(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') closePicker(); });

  window.pickApply=function(noRemarks){
    var ids = noRemarks ? [] : Array.from(selected);
    closePicker();
    if(typeof window.applyComments==='function') window.applyComments(window.__pickTarget, ids);
  };
})();
</script>
