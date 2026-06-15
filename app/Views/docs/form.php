<?php
$isEdit = $doc !== null;
// существующий маршрут → структура этапов для JS
$existing = [];
foreach ($route as $r) {
    $sn = (int) $r['step_no'];
    if (!isset($existing[$sn])) { $existing[$sn] = ['type' => $r['stage_type'], 'parallel' => (int) $r['parallel'], 'users' => []]; }
    $existing[$sn]['users'][] = ['id' => (int) $r['user_id'], 'name' => $r['full_name']];
}
$tplJson = [];
foreach ($templates as $t) {
    $st = [];
    foreach ($tplSteps[$t['id']] ?? [] as $s2) {
        $sn = (int) $s2['step_no'];
        if (!isset($st[$sn])) { $st[$sn] = ['type' => $s2['stage_type'], 'parallel' => (int) $s2['parallel'], 'users' => []]; }
        $st[$sn]['users'][] = ['id' => (int) $s2['user_id'], 'name' => $s2['full_name']];
    }
    $tplJson[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'stages' => array_values($st)];
}
?>
<h1><?= $isEdit ? 'Редактирование документа' : 'Новый документ' ?></h1>

<form method="post" action="<?= $isEdit ? '/docs/' . (int)$doc['id'] . '/update' : '/docs' ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <section class="panel">
        <h2>Реквизиты</h2>
        <?php $dir = $isEdit ? ($doc['direction'] ?? 'internal') : 'internal'; ?>
        <div class="form-inline" style="margin-bottom:10px">
            <label class="chk"><input type="radio" name="direction" value="internal" <?= $dir==='internal'?'checked':'' ?> onclick="dirToggle()"> Внутренний</label>
            <label class="chk"><input type="radio" name="direction" value="incoming" <?= $dir==='incoming'?'checked':'' ?> onclick="dirToggle()"> Входящий</label>
            <label class="chk"><input type="radio" name="direction" value="outgoing" <?= $dir==='outgoing'?'checked':'' ?> onclick="dirToggle()"> Исходящий</label>
        </div>
        <div class="grid-form" id="corrBox" style="<?= $dir==='internal'?'display:none':'' ?>;border:1px solid var(--line);border-radius:8px;padding:10px;margin-bottom:10px">
            <label>Корреспондент (из справочника)
                <select name="correspondent_id" onchange="if(this.value){document.querySelector('[name=correspondent_name]').value='';}">
                    <option value="">— новый (ввести ниже) —</option>
                    <?php foreach ($correspondents as $cr): ?>
                        <option value="<?= (int)$cr['id'] ?>" <?= $isEdit && (int)($doc['correspondent_id']??0)===(int)$cr['id']?'selected':'' ?>><?= e($cr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>…или новый корреспондент<input type="text" name="correspondent_name" value="<?= $isEdit && empty($doc['correspondent_id']) ? e($doc['correspondent_name']??'') : '' ?>" placeholder="наименование / ФИО"></label>
            <label>Вид
                <select name="corr_kind"><option value="org">Организация</option><option value="gov">Орган власти</option><option value="citizen">Гражданин</option></select>
            </label>
            <label id="incNoBox">Исх. № корреспондента<input type="text" name="incoming_number" value="<?= e($doc['incoming_number']??'') ?>"></label>
            <label id="incDateBox">Дата документа корреспондента<input type="date" name="incoming_date" value="<?= e($doc['incoming_date']??'') ?>"></label>
            <label>Способ доставки/отправки<input type="text" name="delivery" value="<?= e($doc['delivery']??'') ?>" placeholder="почта / email / нарочно"></label>
        </div>
        <div class="grid-form">
            <label>Тип документа
                <select name="type_id" required>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $isEdit && (int)$doc['type_id']===(int)$t['id'] ? 'selected':'' ?>><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Гриф
                <select name="grif">
                    <option value="">Общий</option>
                    <option value="ДСП" <?= $isEdit && $doc['grif']==='ДСП' ? 'selected':'' ?>>ДСП (ограниченный доступ)</option>
                </select>
            </label>
            <label>Ответ на документ
                <select name="reply_to_id">
                    <option value="">—</option>
                    <?php foreach ($linkable as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= $isEdit && (int)$doc['reply_to_id']===(int)$l['id'] ? 'selected':'' ?>>
                            <?= e(($l['reg_number'] ? '№' . $l['reg_number'] . ' ' : '#' . $l['id'] . ' ') . mb_strimwidth($l['title'],0,40,'…')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label style="margin-top:10px">Заголовок<input type="text" name="title" value="<?= $isEdit ? e($doc['title']) : '' ?>" required></label>
        <label>Текст документа<textarea name="body" rows="8"><?= $isEdit ? e($doc['body']) : '' ?></textarea></label>
        <label class="file-btn" style="display:inline-flex;align-items:center;gap:6px">📎 Вложение (до 20 МБ)<input type="file" name="file"></label>
        <?php if ($isEdit && $doc['file_orig']): ?><span class="muted">текущее: <?= e($doc['file_orig']) ?></span><?php endif; ?>
    </section>

    <section class="panel">
        <h2>Маршрут</h2>
        <div class="form-inline">
            <label>Шаблон маршрута
                <select id="tplPick">
                    <option value="">— построить вручную —</option>
                    <?php foreach ($tplJson as $i => $t): ?><option value="<?= $i ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="btn" onclick="applyTpl()">Применить шаблон</button>
            <span style="margin-left:auto"></span>
            <label>Тип этапа
                <select id="stType">
                    <?php foreach ($stageLabels as $sv => $sl): ?><option value="<?= $sv ?>"><?= e($sl) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="chk"><input type="checkbox" id="stPar"> параллельно</label>
            <button type="button" class="btn btn-primary" onclick="addStage()">+ Этап</button>
        </div>
        <div id="stagesBox"></div>
        <p class="muted">Этапы выполняются по порядку. Внутри этапа участники визируют последовательно либо параллельно.
            «Ознакомление» идёт после регистрации и не может отклонить документ.</p>
    </section>

    <div class="form-inline">
        <button class="btn" name="action" value="save">Сохранить черновик</button>
        <button class="btn btn-primary" name="action" value="send"
                onclick="return document.querySelectorAll('#stagesBox .stage').length>0 || (alert('Добавьте хотя бы один этап'),false)">Отправить по маршруту</button>
        <a class="btn" href="<?= $isEdit ? '/docs/' . (int)$doc['id'] : '/docs' ?>">Отмена</a>
    </div>
</form>

<script>
function dirToggle(){
  var d=document.querySelector('input[name=direction]:checked').value;
  document.getElementById('corrBox').style.display = d==='internal' ? 'none' : '';
  // исх.№ и дата документа корреспондента нужны для входящего
  document.getElementById('incNoBox').style.display = d==='incoming' ? '' : 'none';
  document.getElementById('incDateBox').style.display = d==='incoming' ? '' : 'none';
}
dirToggle();
var CANDIDATES = <?= json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'name'=>$c['full_name'].($c['heads']?' — рук. '.$c['heads']:($c['position']?' — '.$c['position']:''))], $candidates), JSON_UNESCAPED_UNICODE) ?>;
var TEMPLATES = <?= json_encode($tplJson, JSON_UNESCAPED_UNICODE) ?>;
var STAGE_LABELS = <?= json_encode($stageLabels, JSON_UNESCAPED_UNICODE) ?>;
var stageIdx = 0;

function candidateSelect(){
  var s=document.createElement('select');
  CANDIDATES.forEach(function(c){ var o=document.createElement('option'); o.value=c.id; o.textContent=c.name; s.appendChild(o); });
  return s;
}
function addStage(type, parallel, users){
  type = type || document.getElementById('stType').value;
  parallel = (parallel!==undefined) ? parallel : (document.getElementById('stPar').checked?1:0);
  var i = stageIdx++;
  var d=document.createElement('div'); d.className='stage panel'; d.style.cssText='padding:12px;margin:10px 0';
  d.innerHTML='<div class="xfer-mini"><strong>Этап: '+(STAGE_LABELS[type]||type)+(parallel?' (параллельно)':' (последовательно)')+'</strong>'
    +'<input type="hidden" name="stages['+i+'][type]" value="'+type+'">'
    +(parallel?'<input type="hidden" name="stages['+i+'][parallel]" value="1">':'')
    +'<button type="button" class="btn btn-mini" onclick="addMember(this)">+ участник</button>'
    +'<button type="button" class="btn btn-mini btn-danger" style="margin-left:auto" onclick="this.closest(\'.stage\').remove()">удалить этап</button></div>'
    +'<ol class="route-list"></ol>';
  d.dataset.idx=i;
  document.getElementById('stagesBox').appendChild(d);
  (users||[]).forEach(function(u){ appendMember(d, u.id, u.name); });
  if(!users) addMember(d.querySelector('button'));
  return d;
}
function appendMember(stageEl, id, name){
  var i=stageEl.dataset.idx;
  var li=document.createElement('li'); li.className='route-step';
  li.innerHTML='<span class="route-name">'+name+'</span><input type="hidden" name="stages['+i+'][users][]" value="'+id+'">'
    +'<button type="button" class="btn btn-mini btn-danger" onclick="this.closest(\'li\').remove()">×</button>';
  stageEl.querySelector('.route-list').appendChild(li);
}
function addMember(btn){
  var stageEl=btn.closest('.stage');
  var i=stageEl.dataset.idx;
  var li=document.createElement('li'); li.className='route-step';
  var sel=candidateSelect();
  var ok=document.createElement('button'); ok.type='button'; ok.className='btn btn-mini btn-primary'; ok.textContent='✓';
  ok.onclick=function(){
    var id=sel.value, name=sel.options[sel.selectedIndex].textContent.split(' — ')[0];
    li.remove(); appendMember(stageEl, id, name);
  };
  li.appendChild(sel); li.appendChild(ok);
  stageEl.querySelector('.route-list').appendChild(li);
}
function applyTpl(){
  var v=document.getElementById('tplPick').value;
  if(v==='') return;
  document.getElementById('stagesBox').innerHTML=''; stageIdx=0;
  TEMPLATES[v].stages.forEach(function(s){ addStage(s.type, s.parallel, s.users); });
}
// существующий маршрут при редактировании
<?php foreach (array_values($existing) as $st): ?>
addStage(<?= json_encode($st['type']) ?>, <?= (int)$st['parallel'] ?>, <?= json_encode($st['users'], JSON_UNESCAPED_UNICODE) ?>);
<?php endforeach; ?>
</script>
