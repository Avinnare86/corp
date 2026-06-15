<h1>Шаблоны маршрутов</h1>
<p><a href="/docs">← Документы</a></p>

<section class="panel">
    <h2>Новый шаблон</h2>
    <form method="post" action="/docs/templates">
        <?= csrf_field() ?>
        <div class="form-inline">
            <label class="grow">Название шаблона<input type="text" name="name" placeholder="напр. Приказ: рук. отдела → директор → ознакомление" required></label>
            <label>Тип этапа
                <select id="stType"><?php foreach ($stageLabels as $sv=>$sl): ?><option value="<?= $sv ?>"><?= e($sl) ?></option><?php endforeach; ?></select>
            </label>
            <label class="chk"><input type="checkbox" id="stPar"> параллельно</label>
            <button type="button" class="btn btn-primary" onclick="addStage()">+ Этап</button>
        </div>
        <div id="stagesBox"></div>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить шаблон</button>
    </form>
</section>

<section class="panel">
    <h2>Существующие</h2>
    <?php foreach ($templates as $t): ?>
        <div class="panel" style="padding:12px;margin-bottom:10px">
            <div class="xfer-mini">
                <strong><?= e($t['name']) ?></strong>
                <form method="post" action="/docs/templates/<?= (int)$t['id'] ?>/delete" style="margin-left:auto" onsubmit="return confirm('Удалить шаблон?')">
                    <?= csrf_field() ?><button class="btn btn-mini btn-danger">Удалить</button></form>
            </div>
            <?php $byStage=[]; foreach ($steps[$t['id']] ?? [] as $s2){ $byStage[(int)$s2['step_no']][]=$s2; } ?>
            <?php foreach ($byStage as $sn => $mem): ?>
                <div class="muted" style="font-size:.86rem;margin-top:4px">
                    Этап <?= $sn ?> — <?= e($stageLabels[$mem[0]['stage_type']] ?? '') ?> (<?= (int)$mem[0]['parallel'] ? 'параллельно' : 'последовательно' ?>):
                    <?= e(implode(', ', array_map(fn($m)=>$m['full_name'], $mem))) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$templates): ?><p class="muted">Шаблонов пока нет.</p><?php endif; ?>
</section>

<script>
var CANDIDATES = <?= json_encode(array_merge(
    array_map(fn($k,$v)=>['id'=>'role:'.$k,'name'=>'[Роль] '.$v], array_keys(\App\Controllers\DocumentController::ROLE_SLOTS), \App\Controllers\DocumentController::ROLE_SLOTS),
    array_map(fn($c)=>['id'=>(string)(int)$c['id'],'name'=>$c['full_name'].($c['heads']?' — рук. '.$c['heads']:'')], $candidates)
), JSON_UNESCAPED_UNICODE) ?>;
var STAGE_LABELS = <?= json_encode($stageLabels, JSON_UNESCAPED_UNICODE) ?>;
var stageIdx=0;
function addStage(){
  var type=document.getElementById('stType').value, parallel=document.getElementById('stPar').checked?1:0;
  var i=stageIdx++;
  var d=document.createElement('div'); d.className='stage panel'; d.style.cssText='padding:12px;margin:10px 0'; d.dataset.idx=i;
  d.innerHTML='<div class="xfer-mini"><strong>'+(STAGE_LABELS[type]||type)+(parallel?' (параллельно)':' (последовательно)')+'</strong>'
    +'<input type="hidden" name="stages['+i+'][type]" value="'+type+'">'
    +(parallel?'<input type="hidden" name="stages['+i+'][parallel]" value="1">':'')
    +'<button type="button" class="btn btn-mini" onclick="addMember(this)">+ участник</button>'
    +'<button type="button" class="btn btn-mini btn-danger" style="margin-left:auto" onclick="this.closest(\'.stage\').remove()">удалить</button></div><ol class="route-list"></ol>';
  document.getElementById('stagesBox').appendChild(d);
  addMember(d.querySelector('button'));
}
function addMember(btn){
  var stageEl=btn.closest('.stage'), i=stageEl.dataset.idx;
  var li=document.createElement('li'); li.className='route-step';
  var sel=document.createElement('select');
  CANDIDATES.forEach(function(c){ var o=document.createElement('option'); o.value=c.id; o.textContent=c.name; sel.appendChild(o); });
  var ok=document.createElement('button'); ok.type='button'; ok.className='btn btn-mini btn-primary'; ok.textContent='✓';
  ok.onclick=function(){
    var id=sel.value, name=sel.options[sel.selectedIndex].textContent.split(' — ')[0];
    li.innerHTML='<span class="route-name">'+name+'</span><input type="hidden" name="stages['+i+'][users][]" value="'+id+'">'
      +'<button type="button" class="btn btn-mini btn-danger" onclick="this.closest(\'li\').remove()">×</button>';
  };
  li.appendChild(sel); li.appendChild(ok);
  stageEl.querySelector('.route-list').appendChild(li);
}
</script>
