<?php
use App\Controllers\DocumentController;
$dirLabel = ['incoming'=>'Входящий','outgoing'=>'Исходящий','internal'=>'Внутренний'];
$stages = [];
foreach ($route as $r) { $stages[(int)$r['step_no']][] = $r; }
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>РК <?= e($doc['reg_number'] ?: $doc['id']) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:12pt;color:#000;background:#888;margin:0;padding:20px}
  .toolbar{font-family:Arial;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:680px;display:flex;gap:10px}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .card{background:#fff;max-width:680px;margin:0 auto;padding:22px 26px;box-shadow:0 4px 18px rgba(0,0,0,.35)}
  .bc{text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
  .bc .code{font-family:'Courier New',monospace;font-size:10pt;letter-spacing:.1em;margin-top:2px}
  h2{text-align:center;font-size:13pt;margin:6px 0 12px}
  table{border-collapse:collapse;width:100%;font-size:11pt}
  td{border:1px solid #000;padding:5px 8px;vertical-align:top}
  td.l{width:34%;font-weight:bold;background:#f3f3f3}
  .rt{margin-top:10px;font-size:10pt}
  @media print{ body{background:#fff;padding:0} .toolbar{display:none} .card{box-shadow:none;max-width:none} }
</style></head>
<body>
<div class="toolbar">
    <a href="/docs/<?= (int)$doc['id'] ?>">← К документу</a>
    <button class="primary" onclick="window.print()">⬇ Печать РК</button>
    <span style="font-family:Arial;font-size:10pt;align-self:center">Наклеивается на бумажный документ; штрихкод — для поиска/потоковой обработки.</span>
</div>
<div class="card">
    <div class="bc">
        <?= $barcode ?>
        <div class="code">DOC<?= str_pad((string)$doc['id'],6,'0',STR_PAD_LEFT) ?><?= $doc['reg_number'] ? '  ·  рег. № '.e($doc['reg_number']) : '' ?></div>
    </div>
    <h2>Регистрационная карточка документа</h2>
    <table>
        <tr><td class="l">Рег. номер</td><td><strong><?= e($doc['reg_number'] ?: '— (не присвоен)') ?></strong></td></tr>
        <tr><td class="l">Тип</td><td><?= e($doc['type_name']) ?></td></tr>
        <tr><td class="l">Направление</td><td><?= e($dirLabel[$doc['direction']] ?? 'Внутренний') ?><?= $doc['grif']==='ДСП' ? ' · ДСП' : '' ?></td></tr>
        <tr><td class="l">Заголовок</td><td><?= e($doc['title']) ?></td></tr>
        <?php if (!empty($doc['correspondent_name'])): ?><tr><td class="l"><?= $doc['direction']==='incoming'?'От кого':'Кому' ?></td><td><?= e($doc['correspondent_name']) ?></td></tr><?php endif; ?>
        <?php if ($doc['direction']==='incoming' && (!empty($doc['incoming_number']) || !empty($doc['incoming_date']))): ?>
            <tr><td class="l">Вх. (исх. корр.)</td><td><?= e($doc['incoming_number']) ?><?= $doc['incoming_date']?' от '.e($doc['incoming_date']):'' ?></td></tr><?php endif; ?>
        <tr><td class="l">Автор</td><td><?= e($doc['author_name']) ?><?= $doc['dept_name']?' · '.e($doc['dept_name']):'' ?></td></tr>
        <tr><td class="l">Дата</td><td><?= e(substr((string)($doc['sent_at'] ?: $doc['created_at']),0,16)) ?></td></tr>
        <?php if ($doc['case_idx']): ?><tr><td class="l">Дело</td><td><?= e($doc['case_idx'].' «'.$doc['case_title'].'»') ?></td></tr><?php endif; ?>
        <?php if ((int)($doc['on_control']??0)): ?><tr><td class="l">Контроль</td><td>на контроле<?= $doc['control_due']?', срок '.e($doc['control_due']):'' ?></td></tr><?php endif; ?>
    </table>
    <?php if ($stages): ?>
    <div class="rt"><strong>Маршрут:</strong>
        <?php $parts=[]; foreach ($stages as $sn=>$mm){ $names=implode(', ', array_map(fn($x)=>$x['full_name'], $mm)); $parts[]= $sn.') '.(DocumentController::STAGE_LABEL[$mm[0]['stage_type']]??'').' — '.$names; } echo e(implode('; ', $parts)); ?>
    </div>
    <?php endif; ?>
</div>
</body></html>
