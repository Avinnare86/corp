<?php /* Модальный выбор людей, сгруппированных по отделам, с чекбоксами и поиском.
        Использует $peoplePickList (id, full_name, dept_name). Страница должна определить
        window.applyPeople(target, idsArray) и вызывать window.openPeoplePicker(target, preIds, single).
        single=true — выбор одного человека (клик по другому снимает предыдущий). */ ?>
<div class="modal-overlay" id="peoplePickerModal">
    <div class="modal-box wide">
        <div class="modal-head">
            <strong id="ppTitle">Выбор людей</strong>
            <span class="muted" id="ppSelCount" style="margin-left:auto">выбрано 0</span>
            <button class="btn btn-mini" onclick="closePeoplePicker()">✕</button>
        </div>
        <input type="text" id="ppSearch" placeholder="Поиск по ФИО…" autocomplete="off">
        <div class="picker2">
            <div class="picker-cats" id="ppCats"></div>
            <div class="picker-comments" id="ppPeopleBox"></div>
        </div>
        <div class="picker-foot">
            <button type="button" class="btn btn-primary" id="ppApplyBtn" onclick="ppApply()">Применить (0)</button>
        </div>
    </div>
</div>

<script>
(function(){
  var PEOPLE=<?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['full_name'], 'dept' => $p['dept_name'] ?: 'Без отдела'], $peoplePickList ?? []), JSON_UNESCAPED_UNICODE) ?>;
  window.__peopleIndex = window.__peopleIndex || {};
  PEOPLE.forEach(function(p){ window.__peopleIndex[p.id] = p.name; });
  var DEPTS=[];
  PEOPLE.forEach(function(p){ if(DEPTS.indexOf(p.dept)===-1) DEPTS.push(p.dept); });
  DEPTS.sort(function(a,b){ return a.localeCompare(b,'ru'); });

  var modal=document.getElementById('peoplePickerModal');
  var selected=new Set(), curDept=null, curTarget=null, curSingle=false;

  function lower(s){ return (s||'').toLowerCase(); }
  function peopleFor(dept){
    if(dept==='__all__'){ return PEOPLE; }
    return PEOPLE.filter(function(p){ return p.dept===dept; });
  }
  function renderCats(){
    var box=document.getElementById('ppCats'); box.innerHTML='';
    var list=[{k:'__all__',n:'Все ('+PEOPLE.length+')'}];
    DEPTS.forEach(function(d){ list.push({k:d,n:d+' ('+peopleFor(d).length+')'}); });
    list.forEach(function(it){
      var d=document.createElement('div'); d.className='pcat'+(it.k===curDept?' active':''); d.textContent=it.n;
      d.onclick=function(){ document.getElementById('ppSearch').value=''; curDept=it.k; renderCats(); renderPeople(peopleFor(it.k)); };
      box.appendChild(d);
    });
  }
  function renderPeople(items){
    var box=document.getElementById('ppPeopleBox'); box.innerHTML='';
    if(!items.length){ box.innerHTML='<div class="muted" style="padding:12px">Никого не найдено.</div>'; return; }
    items.forEach(function(p){
      var lab=document.createElement('label'); lab.className='pcomment';
      var ck=document.createElement('input'); ck.type='checkbox'; ck.checked=selected.has(p.id);
      ck.onchange=function(){
        if (curSingle) {
          selected.clear();
          if (ck.checked) { selected.add(p.id); }
          renderPeople(items);
        } else {
          if (ck.checked) { selected.add(p.id); } else { selected.delete(p.id); }
        }
        updCount();
      };
      lab.appendChild(ck);
      lab.appendChild(document.createTextNode(' '+p.name+' '));
      var sub=document.createElement('span'); sub.className='muted'; sub.style.fontSize='.78rem'; sub.textContent=p.dept;
      lab.appendChild(sub);
      box.appendChild(lab);
    });
  }
  function updCount(){
    document.getElementById('ppSelCount').textContent='выбрано '+selected.size;
    document.getElementById('ppApplyBtn').textContent = curSingle ? 'Выбрать' : ('Применить (' + selected.size + ')');
  }

  document.getElementById('ppSearch').addEventListener('input',function(){
    var q=lower(this.value);
    if(q===''){ renderPeople(peopleFor(curDept||'__all__')); return; }
    renderPeople(PEOPLE.filter(function(p){ return lower(p.name).indexOf(q)>=0; }));
    document.querySelectorAll('#ppCats .pcat').forEach(function(d){ d.classList.remove('active'); });
  });

  window.openPeoplePicker=function(target, preIds, single){
    curTarget=target;
    curSingle=!!single;
    document.getElementById('ppTitle').textContent = curSingle ? 'Выбор человека' : 'Выбор людей';
    selected=new Set((preIds||[]).map(Number));
    document.getElementById('ppSearch').value='';
    curDept='__all__';
    renderCats(); renderPeople(peopleFor(curDept)); updCount();
    modal.classList.add('open');
  };
  window.closePeoplePicker=function(){ modal.classList.remove('open'); };
  modal.addEventListener('click',function(e){ if(e.target===modal) closePeoplePicker(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') closePeoplePicker(); });

  window.ppApply=function(){
    var ids=Array.from(selected);
    closePeoplePicker();
    if(typeof window.applyPeople==='function') window.applyPeople(curTarget, ids);
  };
})();
</script>
