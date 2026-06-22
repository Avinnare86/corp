<?php
$months = [1=>'январе',2=>'феврале',3=>'марте',4=>'апреле',5=>'мае',6=>'июне',7=>'июле',8=>'августе',9=>'сентябре',10=>'октябре',11=>'ноябре',12=>'декабре'];
$monthsG = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
$pctf = fn($p)=>rtrim(rtrim(number_format((float)$p,1,'.',''),'0'),'.');
$stamp = function($who, $s, $at, $type, $hash) {
    if (!$at) return '';
    return '<div class="stamp"><b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ</b><br>'
        . 'Роль: ' . htmlspecialchars($who, ENT_QUOTES) . '<br>'
        . 'Вид подписи: ' . htmlspecialchars(['PEP'=>'ПЭП (простая ЭП)','UNEP'=>'УНЭП','UKEP'=>'УКЭП'][$type] ?? ($type ?: 'ПЭП'), ENT_QUOTES) . '<br>'
        . 'Владелец: ' . htmlspecialchars(($s['full_name'] ?? ''), ENT_QUOTES) . '<br>'
        . 'Подписано: ' . htmlspecialchars(substr((string)$at,0,16), ENT_QUOTES) . '<br>'
        . 'Отпечаток: <span class="fp">' . htmlspecialchars((string)$hash, ENT_QUOTES) . '</span></div>';
};
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Служебки — печать пакетом</title>
<style>
  body{font-family:'Times New Roman',serif;font-size:13pt;color:#000;background:#888;margin:0;padding:24px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:820px;display:flex;gap:10px;align-items:center}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:820px;margin:0 auto 24px;padding:28mm 20mm;box-shadow:0 4px 24px rgba(0,0,0,.4);box-sizing:border-box;page-break-after:always}
  .sheet:last-of-type{page-break-after:auto}
  .addr{margin-left:55%;text-align:left;margin-bottom:24px;line-height:1.4}
  h1{text-align:center;font-size:14pt;margin:8px 0 2px;text-transform:uppercase;letter-spacing:.02em}
  .sub{text-align:center;font-style:italic;margin:0 0 4px}
  .num{text-align:center;margin:0 0 18px}
  .body{text-align:justify;line-height:1.5;text-indent:1.25cm}
  table.t{border-collapse:collapse;width:100%;font-size:11.5pt;margin:14px 0}
  table.t th,table.t td{border:1px solid #000;padding:5px 8px;vertical-align:top}
  table.t th{background:#f3f3f3;text-align:center;font-weight:bold}
  table.t td.r{text-align:right;white-space:nowrap}
  .stamp{border:2px solid #1a56b8;border-radius:10px;padding:10px 14px;color:#1a56b8;font-family:Arial;font-size:9pt;line-height:1.5;margin:14px 0;max-width:430px}
  .stamp b{font-size:10pt;letter-spacing:.03em}
  .stamp .fp{word-break:break-all}
  .status{font-family:Arial;font-size:10pt;color:#666;text-align:center;margin-top:10px}
  @media print{ body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:18mm;margin:0} }
</style></head>
<body>
<div class="toolbar">
    <a href="/memos/print-report?period=<?= e($period) ?>">← К отчёту</a>
    <button class="primary" onclick="window.print()">⬇ Скачать все PDF / Печать</button>
    <span>Служебок: <?= count($batch) ?> · период <?= e($period ?: 'все') ?></span>
</div>
<?php if (!$batch): ?>
    <div class="sheet">Служебок за период не найдено.</div>
<?php endif; ?>
<?php foreach ($batch as $b):
    $memo = $b['memo']; $lines = $b['lines']; $kind = $b['kind']; $source = $b['source'];
    $directorName = $b['directorName']; $grounds = $b['grounds']; $groundRows = $b['groundRows']; $signers = $b['signers'];
    $flexStamps = $b['flexStamps'] ?? null;
    include __DIR__ . '/_memo_sheet.php';
endforeach; ?>
</body></html>
