<?php use App\Controllers\StimulusController; $st = StimulusController::STATUS;
$isMgmt = ($kind ?? 'staff') === 'mgmt';
$direct = $direct ?? ($memo['direct_tier'] ?? null);
$isCross = $isCross ?? false;
$batchId = $batchId ?? ($memo['batch_id'] ?? null);
$batchCount = $batchCount ?? 0;
$dirStep = ['director', 'Директор', $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? null];
$depStep = ['deputy', 'Зам (прямое назначение)', $memo['deputy_signed_at'] ?? null, $memo['deputy_sign_type'] ?? null];
if ($isMgmt) {
    $steps = [['director', 'Директор (утверждает приказом)', $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? null]];
} elseif ($direct === 'director') {
    $steps = [['director', 'Директор (прямое назначение)', $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? null]];
} elseif ($direct === 'deputy') {
    $steps = [$depStep, $dirStep];
} else {
    $steps = [
        ['head', $isCross ? 'Инициатор (вышестоящий)' : 'Начальник отдела', $memo['head_signed_at'] ?? null, $memo['head_sign_type'] ?? null],
        ['deputy', 'Курирующий зам', $memo['deputy_signed_at'] ?? null, $memo['deputy_sign_type'] ?? null],
        $dirStep,
    ];
}
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <?php if ($batchCount > 1): ?><a class="btn btn-mini" href="/memos/batch/<?= (int)$batchId ?>">⊞ Пакет (<?= (int)$batchCount ?>)</a><?php endif; ?>
    <h1 style="margin:0;font-size:1.2rem">Служебка №<?= e($memo['number'] ?: $memo['id']) ?></h1>
    <span class="st <?= ['approved'=>'st-ok','revision'=>'st-rev','rejected'=>'st-rev'][$memo['status']] ?? 'st-wait' ?>"><?= e($st[$memo['status']] ?? $memo['status']) ?></span>
</div>

<div class="doc-grid">
<div>
    <section class="panel">
        <h2>Реквизиты</h2>
        <table class="table">
            <tr><td class="muted" style="width:150px">Отдел</td><td><?= e($memo['dept_name'] ?? '—') ?></td></tr>
            <tr><td class="muted">Автор</td><td><?= e($memo['author_name']) ?></td></tr>
            <tr><td class="muted">Период</td><td><?= e($memo['period']) ?></td></tr>
            <tr><td class="muted">Вид выплаты</td><td><?= $memo['pay_kind']==='onetime' ? 'единовременная (полной суммой)' : 'ежемесячная (пропорц. отработке)' ?></td></tr>
            <?php if ($source): ?><tr><td class="muted">Источник</td><td><?= e($source['name']) ?><?= $source['detail']?' ('.e($source['detail']).')':'' ?></td></tr><?php endif; ?>
            <tr><td class="muted">Сумма по служебке</td><td><strong><?= money($total) ?></strong></td></tr>
        </table>
        <h3 class="sub">Основания (раздел 4) — перечень с нормативным %</h3>
        <?php if (!empty($groundRows)): ?>
            <table class="table">
                <thead><tr><th>Показатель</th><th class="num" style="white-space:nowrap">Норматив</th></tr></thead>
                <tbody>
                <?php foreach ($groundRows as $g): ?>
                    <tr><td><?= e($g['text']) ?> <span class="muted" style="font-size:.78rem"><?= e($g['category']) ?></span></td>
                        <td class="num" style="white-space:nowrap"><?= (float)$g['percent']>0 ? 'до '.e(rtrim(rtrim(number_format((float)$g['percent'],1,'.',''),'0'),'.')).'%' : '—' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="doc-body"><?= nl2br(e(str_replace('; ', "\n", (string)$memo['grounds']))) ?></div>
        <?php endif; ?>

        <?php if ($memo['status']==='revision' && $memo['reject_reason']): ?>
            <div class="flash flash-error" style="margin-top:10px">Возвращено на доработку: <?= e($memo['reject_reason']) ?></div>
        <?php endif; ?>

        <div class="form-inline" style="margin-top:12px">
            <?php if ($canEdit): ?><a class="btn btn-primary" href="/memos/<?= (int)$memo['id'] ?>/edit">✎ Редактировать</a>
                <form method="post" action="/memos/<?= (int)$memo['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить черновик?')"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-danger">Удалить</button></form>
            <?php endif; ?>
        </div>
    </section>

    </section>
</div>

<div>
    <section class="panel">
        <h2>Маршрут подписания</h2>
        <?php $typeMap=['PEP'=>'ПЭП','UNEP'=>'УНЭП','UKEP'=>'УКЭП']; ?>
        <?php if (!empty($flexStamps)): // штампы проставлены админом — показываем фактический набор ?>
        <div class="stepper">
            <?php foreach ($flexStamps as $fs): ?>
                <div class="step ok">
                    <span class="step-ic">🖋</span>
                    <div class="step-body"><strong><?= e($fs['role_label']) ?></strong> — <?= e($fs['signer_name']) ?>
                        <span class="muted" style="font-size:.76rem">ЭП <?= e($typeMap[$fs['sign_type']] ?? $fs['sign_type']) ?> · <?= e(substr((string)$fs['signed_at'],0,16)) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="stepper">
            <?php foreach ($steps as [$k,$label,$at,$type]):
                $done = (bool)$at; $cls = $done ? 'ok' : 'wait'; ?>
                <div class="step <?= $cls ?>">
                    <span class="step-ic"><?= $done ? '🖋' : '○' ?></span>
                    <div class="step-body"><strong><?= e($label) ?></strong>
                        <?php if ($done): ?><span class="muted" style="font-size:.76rem">ЭП <?= e($typeMap[$type] ?? $type) ?> · <?= e(substr((string)$at,0,16)) ?></span>
                        <?php else: ?><span class="muted" style="font-size:.76rem">ожидает</span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($canHeadSign || $canDeputySign || $canDirectorSign || !empty($canMgmtSign) || !empty($canDirectSign)): ?>
        <h3 class="sub">Подписать (ЭП — подтверждение паролем)</h3>
        <form method="post" action="/memos/<?= (int)$memo['id'] ?>/sign">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Ваш пароль<input type="password" name="password" required></label>
            <?php if (!empty($dirOnBehalf)): ?>
            <label style="display:block;margin-top:8px">Дата и время подписи (от имени директора)
                <input type="datetime-local" name="sign_at" value="<?= date('Y-m-d\TH:i') ?>">
            </label>
            <p class="muted" style="margin:6px 0 0">Будет проставлена ЭП <strong>от имени директора</strong><?= !empty($dirDisplay) ? ' — ' . e($dirDisplay) : '' ?>; вы подписываете как администратор.</p>
            <?php endif; ?>
            <div class="form-inline" style="margin-top:8px">
                <button class="btn btn-primary">
                    <?= !empty($canDirectSign) ? e($directLabel)
                        : (!empty($canMgmtSign) ? '🖋 Утвердить приказом директора'
                        : ($canHeadSign ? '🖋 Подписать и направить заму'
                        : ($canDeputySign ? '🖋 Утвердить (заму)' : '🖋 Утвердить (директор)'))) ?>
                </button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($canReject): ?>
        <details style="margin-top:12px">
            <summary class="muted" style="cursor:pointer">Отклонить / вернуть на доработку…</summary>
            <form method="post" action="/memos/<?= (int)$memo['id'] ?>/reject" style="margin-top:8px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label>Причина<input type="text" name="reason" required></label>
                <button class="btn btn-danger" style="margin-top:6px">↩ Вернуть на доработку</button>
            </form>
            <?php if (in_array($memo['status'], ['head_signed','deputy_signed'], true)): ?>
            <form method="post" action="/memos/<?= (int)$memo['id'] ?>/reject-final" style="margin-top:12px;border-top:1px dashed #ccc;padding-top:10px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="muted" style="margin:0 0 6px;font-size:.84rem">Окончательное отклонение: служебка переносится в <b>архив</b> (без возврата на доработку).</p>
                <label>Причина<input type="text" name="reason" required></label>
                <button class="btn btn-danger" style="margin-top:6px" onclick="return confirm('Отклонить служебку ОКОНЧАТЕЛЬНО и перенести в архив?')">🗄 Отклонить → в архив</button>
            </form>
            <?php endif; ?>
        </details>
        <?php endif; ?>
    </section>
</div>
</div>

<section class="panel">
    <h2>Работники и суммы</h2>
    <table class="table">
        <thead><tr><th style="min-width:220px">Работник</th><th class="num" style="white-space:nowrap">Сумма, ₽</th><th class="num" style="white-space:nowrap">Оклад×ставка</th><th class="num">%</th><th>Вид выплаты</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <tr>
                <td><?= e($l['full_name']) ?> <span class="muted" style="font-size:.78rem"><?= e($l['position']) ?></span></td>
                <td class="num" style="white-space:nowrap"><?= money($l['amount']) ?></td>
                <td class="num muted" style="white-space:nowrap"><?= money($l['oklad_load']) ?></td>
                <td class="num" style="white-space:nowrap"><strong><?= e(rtrim(rtrim(number_format((float)$l['percent'],1,'.',''),'0'),'.')) ?>%</strong></td>
                <td><?= $l['pay_kind']==='onetime' ? 'единовременная' : 'ежемесячная (пропорц. отработке)' ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total"><td>Итого по служебке</td><td class="num" style="white-space:nowrap"><strong><?= money($total) ?></strong></td><td colspan="3"></td></tr>
        </tbody>
    </table>
</section>

<?php if (!empty($isAdmin)): ?>
<section class="panel" id="stampBuilder">
    <h2>Штампы ЭП — проставление задним числом <span class="muted" style="font-size:.8rem">(только администратор)</span></h2>
    <p class="muted" style="margin-top:0">Если документ не подали вовремя — проставьте нужные штампы в любом порядке, у каждого своя дата и время.
        Это <strong>не влияет на обычный маршрут подписи</strong>. После «Проставить штампы» служебка станет утверждённой и попадёт в бухгалтерию как обычная.</p>
    <datalist id="roleLabels">
        <option value="Начальник отдела (составил)"></option>
        <option value="Курирующий заместитель директора (утвердил)"></option>
        <option value="Директор (утвердил)"></option>
    </datalist>
    <form method="post" action="/memos/<?= (int)$memo['id'] ?>/stamps" id="stampForm">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <table class="table" id="stampRows">
            <thead><tr><th style="width:230px">Роль (штамп)</th><th>Подписант</th><th>Должность</th><th>Вид</th><th>Дата и время</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
        <div class="form-inline" style="margin-top:8px;gap:8px">
            <button type="button" class="btn btn-mini" onclick="addStampRow()">+ Штамп</button>
            <button class="btn btn-primary" onclick="return confirm('Проставить штампы и утвердить служебку?')">🖋 Проставить штампы</button>
        </div>
    </form>
    <?php if (!empty($flexStamps)): ?>
    <form method="post" action="/memos/<?= (int)$memo['id'] ?>/stamps/clear" style="margin-top:8px" onsubmit="return confirm('Очистить гибкие штампы? Печать вернётся к стандартным подписям.')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-danger btn-mini">Очистить штампы</button>
    </form>
    <?php endif; ?>
</section>
<script>
var STAMP_EMP = <?= json_encode($employees ?? [], JSON_UNESCAPED_UNICODE) ?>;
var STAMP_PREFILL = <?= json_encode($flexStamps ?: [], JSON_UNESCAPED_UNICODE) ?>;
var STAMP_LEGACY = <?= json_encode($legacyStamps ?? [], JSON_UNESCAPED_UNICODE) ?>;
var stampIdx = 0;
function sEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
function empOpts(sel){
  var o = '<option value="">— ввести вручную —</option>';
  STAMP_EMP.forEach(function(m){ o += '<option value="'+m.id+'"'+(String(m.id)===String(sel)?' selected':'')+'>'+sEsc(m.full_name)+(m.position?(' — '+sEsc(m.position)):'')+'</option>'; });
  return o;
}
function addStampRow(d){
  d = d || {};
  var i = stampIdx++;
  var tb = document.querySelector('#stampRows tbody');
  var tr = document.createElement('tr');
  var types = [['PEP','ПЭП'],['UNEP','УНЭП'],['UKEP','УКЭП']];
  var topt = types.map(function(t){ return '<option value="'+t[0]+'"'+((d.sign_type||'PEP')===t[0]?' selected':'')+'>'+t[1]+'</option>'; }).join('');
  tr.innerHTML =
    '<td><input type="text" name="srow['+i+'][role_label]" list="roleLabels" value="'+sEsc(d.role_label)+'" style="width:100%" placeholder="напр. Директор (утвердил)"></td>'
    + '<td><select class="s-emp" name="srow['+i+'][signer_user_id]" onchange="stampEmpPick(this)">'+empOpts(d.signer_user_id)+'</select>'
    + '<input type="text" class="s-name" name="srow['+i+'][signer_name]" value="'+sEsc(d.signer_name)+'" style="width:100%;margin-top:4px" placeholder="ФИО подписанта"></td>'
    + '<td><input type="text" class="s-pos" name="srow['+i+'][signer_position]" value="'+sEsc(d.signer_position)+'" style="width:100%" placeholder="должность"></td>'
    + '<td><select name="srow['+i+'][sign_type]">'+topt+'</select></td>'
    + '<td><input type="datetime-local" name="srow['+i+'][signed_at]" value="'+sEsc((d.signed_at||'').replace(' ','T').substring(0,16))+'"></td>'
    + '<td style="white-space:nowrap">'
    + '<button type="button" class="btn btn-mini" title="выше" onclick="stampMove(this,-1)">↑</button> '
    + '<button type="button" class="btn btn-mini" title="ниже" onclick="stampMove(this,1)">↓</button> '
    + '<button type="button" class="btn btn-mini btn-danger" onclick="this.closest(\'tr\').remove()">×</button></td>';
  tb.appendChild(tr);
}
function stampEmpPick(sel){
  var tr = sel.closest('tr'); if(!sel.value){ return; }
  var m = STAMP_EMP.find(function(x){ return String(x.id)===String(sel.value); });
  if(m){ tr.querySelector('.s-name').value = m.full_name; tr.querySelector('.s-pos').value = m.position || ''; }
}
function stampMove(btn, dir){
  var tr = btn.closest('tr');
  if(dir<0 && tr.previousElementSibling){ tr.parentNode.insertBefore(tr, tr.previousElementSibling); }
  if(dir>0 && tr.nextElementSibling){ tr.parentNode.insertBefore(tr.nextElementSibling, tr); }
}
(function(){
  var seed = STAMP_PREFILL.length ? STAMP_PREFILL : STAMP_LEGACY;
  if(seed.length){ seed.forEach(function(s){ addStampRow(s); }); } else { addStampRow(); }
})();
</script>
<?php endif; ?>

<section class="panel">
    <h2>Сформированный документ
        <a class="btn btn-mini" style="float:right" href="/memos/<?= (int)$memo['id'] ?>/print" target="_blank">⤢ Открыть · печать / PDF</a>
    </h2>
    <iframe class="doc-preview" src="/memos/<?= (int)$memo['id'] ?>/print" title="Служебная записка"></iframe>
</section>
