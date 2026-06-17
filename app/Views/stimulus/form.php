<?php $isMgmt = ($kind ?? 'staff') === 'mgmt'; ?>
<h1><?= e($memo ? 'Служебка №' . ($memo['number'] ?: $memo['id']) : ($isMgmt ? 'Стимул заместителям / гл. бухгалтеру' : 'Новая служебка о стимуле')) ?></h1>

<?php if (!empty($forecast)): $f = $forecast; ?>
<section class="panel" style="border-left:4px solid <?= $f['remainder'] < 0 ? '#c0392b' : '#26368B' ?>">
    <h2 style="margin-top:0">Остаток средств ФОТ до конца <?= (int)$f['year'] ?> г.
        <span style="float:right;font-size:1.1rem;color:<?= $f['remainder'] < 0 ? '#c0392b' : '#1e7e34' ?>"><?= money($f['remainder']) ?></span>
    </h2>
    <?php if (!$f['has_budget']): ?>
        <p class="flash flash-error" style="margin:0 0 10px">Бюджет отдела на <?= (int)$f['year'] ?> г. не задан (<a href="/budget">раздел «Бюджет ФОТ»</a>). Остаток считается от нуля — он будет отрицательным, пока бюджет не внесён.</p>
    <?php endif; ?>
    <table class="table" style="max-width:560px">
        <tr><td>Бюджет ФОТ отдела (год)</td><td class="num"><?= money($f['budget']) ?></td></tr>
        <tr><td>− План окладной части (мес. <?= money($f['oklad_monthly']) ?> × 12)</td><td class="num">−<?= money($f['oklad_year']) ?></td></tr>
        <tr><td>− Действующий ежемесячный стимул (мес. <?= money($f['stim_monthly']) ?> × 12)</td><td class="num">−<?= money($f['stim_year']) ?></td></tr>
        <tr><td>− Плановый резерв на отпуск (≈ оклад × <?= rtrim(rtrim(number_format($f['vac_coeff'],3,'.',''),'0'),'.') ?>)</td><td class="num">−<?= money($f['vacation']) ?></td></tr>
        <tr class="total"><td><strong>Остаток (можно распределить)</strong></td><td class="num"><strong><?= money($f['remainder']) ?></strong></td></tr>
    </table>
    <p class="muted" style="margin:8px 0 0">До конца года осталось месяцев: <strong><?= (int)$f['months_left'] ?></strong>, сотрудников в отделе: <strong><?= (int)$f['people'] ?></strong>.
        Остаток — это свободная часть годового бюджета после обязательных расходов; распределяйте новый стимул в его пределах.</p>
</section>
<?php endif; ?>

<section class="panel">
    <?php if ($isMgmt): ?>
        <p class="muted" style="margin-top:0">Стимул <strong>заместителям директора и главному бухгалтеру</strong> (Приложение № 2 к Положению).
            Документ оформляет и утверждает <strong>директор</strong> единолично (одна подпись ЭП), после чего его видит бухгалтерия.
            <br>% = сумма / (оклад × ставка) × 100. По каждому работнику в одном периоде основания не должны повторяться.</p>
    <?php else: ?>
        <p class="muted" style="margin-top:0">Отдел: <strong><?= e($dept['name'] ?? '—') ?></strong>.
            Маршрут: начальник отдела → курирующий зам → директор. Бухгалтерия видит после подписи зама.
            <br>% = сумма / (оклад × ставка) × 100 — от номинального оклада на нагрузку, без учёта отработки.
            По каждому работнику в одном периоде основания не должны повторяться между служебками.</p>
    <?php endif; ?>

    <form method="post" action="/memos" id="memoForm">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)($memo['id'] ?? 0) ?>">
        <input type="hidden" name="kind" value="<?= e($kind ?? 'staff') ?>">
        <input type="hidden" name="department_id" value="<?= (int)($dept['id'] ?? 0) ?>">
        <?php if (!empty($direct)): ?>
        <input type="hidden" name="direct_tier" value="<?= e($direct) ?>">
        <div class="flash" style="background:#eef;color:#223;margin-bottom:10px">Прямое назначение
            (<?= $direct === 'director' ? 'директор — утверждается сразу' : 'зам — далее только подпись директора' ?>),
            без участия начальника отдела.</div>
        <?php if (!empty($deptOpts) && empty($memo['id'])): ?>
        <label style="max-width:420px;margin-bottom:10px">Подразделение
            <select onchange="location.href='/memos/direct/new?dept='+this.value">
                <?php foreach ($deptOpts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)($deptId ?? 0)===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php endif; ?>
        <div class="form-inline" style="align-items:flex-end">
            <label>Период<input type="month" name="period" value="<?= e($memo['period'] ?? date('Y-m')) ?>" required></label>
            <label>Вид выплаты по умолчанию
                <select name="pay_kind" id="defKind">
                    <option value="monthly" <?= ($memo['pay_kind']??'monthly')==='monthly'?'selected':'' ?>>ежемесячная (пропорц. отработке)</option>
                    <option value="onetime" <?= ($memo['pay_kind']??'')==='onetime'?'selected':'' ?>>единовременная (полной суммой)</option>
                </select>
            </label>
            <label>Источник выплат
                <select name="source_id">
                    <option value="">—</option>
                    <?php foreach ($sources as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)($memo['source_id']??0)===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?><?= $s['detail']?' ('.e($s['detail']).')':'' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <h3 class="sub">Основания (Положение) — перечень с нормативным %</h3>
        <p class="muted" style="margin:0 0 8px">Выберите достаточный набор показателей. Рядом — нормативный вес (%) показателя по Положению.</p>
        <div class="role-cols">
            <?php $byCat=[]; foreach ($grounds as $g) { $byCat[$g['category']][]=$g; } foreach ($byCat as $cat=>$gs): ?>
                <fieldset class="role-group"><legend><?= e($cat) ?></legend>
                    <?php foreach ($gs as $g): $p = rtrim(rtrim(number_format((float)$g['percent'],1,'.',''),'0'),'.'); ?>
                        <label class="chk"><input type="checkbox" name="grounds[]" value="<?= (int)$g['id'] ?>" <?= in_array((int)$g['id'],$selGrounds,true)?'checked':'' ?>>
                            <span><?= e($g['text']) ?></span>
                            <?php if ((float)$g['percent'] > 0): ?><span class="pill" style="margin-left:6px;background:#eef;border-radius:8px;padding:1px 7px;font-size:.78rem;white-space:nowrap">до <?= e($p) ?>%</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>

        <h3 class="sub"><?= $isMgmt ? 'Руководители и суммы' : 'Работники и суммы' ?></h3>
        <?php if ($showPiece): ?>
        <div class="form-inline" style="margin-bottom:8px;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn btn-mini" onclick="pullPiece('total')">↧ Перенести сделку из квоты и виз (к 25-му)</button>
            <button type="button" class="btn btn-mini" onclick="pullPiece('kvota')">↧ Только квота</button>
            <button type="button" class="btn btn-mini" onclick="pullPiece('visy')">↧ Только визы</button>
            <span class="muted" style="font-size:.82rem">Подставит работников отдела с начисленной сделкой за выбранный период (до 25 числа).</span>
        </div>
        <?php endif; ?>
        <table class="table" id="memoLines">
            <thead><tr><th>Работник</th><?php if ($showPiece): ?><th class="num">Квота</th><th class="num">Визы</th><?php endif; ?><th>Оклад×ставка</th><th class="num">Сумма стимула, ₽</th><th class="num">%</th><th>Вид</th><th>Цель</th><th>Основание</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
        <button type="button" class="btn btn-mini" onclick="addLine()">+ Добавить <?= $isMgmt ? 'руководителя' : 'работника' ?></button>
        <p style="margin:10px 0 0;text-align:right;font-size:1.05rem">Итого по служебке: <strong id="memoTotal">0 ₽</strong></p>
        <div class="form-inline" style="margin-top:14px">
            <button class="btn btn-primary">💾 Сохранить черновик</button>
            <a class="btn" href="/memos">Отмена</a>
        </div>
    </form>
</section>

<script>
var SHOW_PIECE = <?= $showPiece ? 'true' : 'false' ?>;
var MEMBERS = <?= json_encode(array_map(fn($m)=>['id'=>(int)$m['id'],'name'=>$m['full_name'].' — '.$m['position'],'load'=>(float)$m['oklad_load'],'kvota'=>(float)$m['kvota'],'visy'=>(float)$m['visy'],'total'=>(float)$m['piece']], $members), JSON_UNESCAPED_UNICODE) ?>;
var EXIST = <?= json_encode(array_map(fn($l)=>['user_id'=>(int)$l['user_id'],'amount'=>(float)$l['amount'],'kind'=>$l['pay_kind'],'purpose'=>$l['purpose']??'other','reason_id'=>$l['reason_id']!==null?(int)$l['reason_id']:null], $lines), JSON_UNESCAPED_UNICODE) ?>;
var REASONS = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'text'=>$r['text']], $reasons ?? []), JSON_UNESCAPED_UNICODE) ?>;
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
function opt(sel){ return MEMBERS.map(function(m){ return '<option value="'+m.id+'"'+(m.id==sel?' selected':'')+'>'+esc(m.name)+'</option>'; }).join(''); }
function reasonOpts(sel){ return '<option value="">—</option>'+REASONS.map(function(r){ return '<option value="'+r.id+'"'+(r.id==sel?' selected':'')+'>'+esc(r.text)+'</option>'; }).join(''); }
function purposeOpts(sel){ sel=sel||'other'; var o=[['other','за другое'],['anketas','за анкеты'],['visas','за визы']];
  return o.map(function(p){ return '<option value="'+p[0]+'"'+(p[0]===sel?' selected':'')+'>'+p[1]+'</option>'; }).join(''); }
function memOf(id){ return MEMBERS.find(function(x){return x.id==id;}); }
function fmt(n){ return (Math.round(n*100)/100).toLocaleString('ru-RU'); }
function recalc(tr){
  var id=tr.querySelector('.m-user').value, amt=parseFloat((tr.querySelector('.m-amount').value||'0').replace(',','.'))||0;
  var m=memOf(id), load=m?m.load:0;
  tr.querySelector('.m-load').textContent=fmt(load);
  if (SHOW_PIECE){ tr.querySelector('.m-kvota').textContent=m?fmt(m.kvota):'—'; tr.querySelector('.m-visy').textContent=m?fmt(m.visy):'—'; }
  tr.querySelector('.m-pct').textContent=load>0?fmt(amt/load*100)+'%':'—';
  updTotal();
}
function updTotal(){
  var s=0; document.querySelectorAll('#memoLines .m-amount').forEach(function(i){ s+=parseFloat((i.value||'0').replace(',','.'))||0; });
  var el=document.getElementById('memoTotal'); if(el) el.textContent=fmt(s)+' ₽';
}
function addLine(uid, amount, kind, purpose, reasonId){
  var tb=document.querySelector('#memoLines tbody');
  var i=tb.children.length;
  var def=document.getElementById('defKind').value;
  var piece = SHOW_PIECE ? '<td class="m-kvota num muted">—</td><td class="m-visy num muted">—</td>' : '';
  var tr=document.createElement('tr');
  tr.innerHTML='<td><select class="m-user" name="row['+i+'][user_id]"><option value="">—</option>'+opt(uid)+'</select></td>'
    +piece
    +'<td class="m-load num">0</td>'
    +'<td><input class="m-amount" type="text" name="row['+i+'][amount]" value="'+(amount||'')+'" style="width:120px;text-align:right"></td>'
    +'<td class="m-pct num">—</td>'
    +'<td><select name="row['+i+'][pay_kind]"><option value="monthly"'+((kind||def)==='monthly'?' selected':'')+'>ежемес.</option><option value="onetime"'+((kind||def)==='onetime'?' selected':'')+'>единоврем.</option></select></td>'
    +'<td><select name="row['+i+'][purpose]" title="За анкеты/визы — сделка сверх оклада закрывает стимул; за другое — гарантированная доплата">'+purposeOpts(purpose)+'</select></td>'
    +'<td><select name="row['+i+'][reason_id]">'+reasonOpts(reasonId)+'</select></td>'
    +'<td><button type="button" class="btn btn-mini btn-danger" onclick="this.closest(\'tr\').remove();updTotal()">×</button></td>';
  tb.appendChild(tr);
  tr.querySelector('.m-user').addEventListener('change',function(){recalc(tr);});
  tr.querySelector('.m-amount').addEventListener('input',function(){recalc(tr);});
  recalc(tr);
  return tr;
}
/** Перенос сделки из квоты/виз: заполняет таблицу работниками с ненулевой сделкой. */
function pullPiece(kind){
  var added=0;
  MEMBERS.forEach(function(m){
    var v = kind==='kvota'?m.kvota : kind==='visy'?m.visy : m.total;
    if (v<=0) return;
    var row=null;
    document.querySelectorAll('#memoLines tbody tr').forEach(function(tr){ if(tr.querySelector('.m-user').value==m.id) row=tr; });
    if(!row){ row=addLine(m.id, '', document.getElementById('defKind').value); }
    row.querySelector('.m-amount').value = (Math.round(v*100)/100);
    recalc(row);
    added++;
  });
  if(!added){ alert('За выбранный период (до 25 числа) нет начисленной сделки по '+(kind==='kvota'?'квоте':kind==='visy'?'визам':'квоте и визам')+'.'); }
}
if (EXIST.length) { EXIST.forEach(function(l){ addLine(l.user_id, l.amount, l.kind, l.purpose, l.reason_id); }); } else { addLine(); }
updTotal();
</script>
