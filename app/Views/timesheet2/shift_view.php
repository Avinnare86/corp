<?php
/** Сменный табель 0504421 (колл-центр 2/2): половинка месяца, две строки на сотрудника (коды/часы),
 *  дробный код день/ночь «Я/Н» и часы «6/2». Лист А4 со штампом ЭП — печать → PDF. */
use App\Controllers\TabelController;
$month = substr($t['period'], 0, 7);
$half = (int) substr($t['period'], 8);
$mm = (int) substr($month, 5, 2);
$yy = (int) substr($month, 0, 4);
$monthsRu = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
$d1 = (int) substr($dates[0], 8, 2);
$d2 = (int) substr(end($dates), 8, 2);
$signDate = $t['signed_at'] ? date('d.m.Y', strtotime($t['signed_at'])) : date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Табель 0504421 <?= e($t['period']) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:10.5pt;color:#000;background:#888;margin:0;padding:24px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:1180px;display:flex;gap:10px;align-items:center}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:1180px;margin:0 auto;padding:34px 40px;box-shadow:0 4px 24px rgba(0,0,0,.4)}
  .okud{float:right;border:1px solid #000;font-size:8.5pt}
  .okud td{border:1px solid #000;padding:2px 6px}
  .hd{margin:0 0 2px} .hd .lbl{font-size:9pt}
  h1{text-align:center;font-size:12pt;text-transform:uppercase;margin:14px 0 2px}
  .sub{text-align:center;margin:0 0 14px;font-size:10pt}
  table.tb{border-collapse:collapse;width:100%;font-size:9pt;table-layout:fixed}
  table.tb th,table.tb td{border:1px solid #000;padding:2px 3px;text-align:center;word-wrap:break-word}
  table.tb td.name{text-align:left;font-size:8.5pt}
  table.tb th.dnum{width:24px}
  .code{font-weight:bold}
  .hours{color:#333;font-size:8.5pt}
  .legend{font-size:8.5pt;margin:8px 0 0;color:#333}
  .signs{margin-top:26px;font-size:10pt;width:100%}
  .signs td{padding:10px 8px;vertical-align:bottom}
  .sigline{border-bottom:1px solid #000;min-width:160px;display:inline-block}
  .cap{font-size:8pt;color:#444}
  .stamp{margin-top:18px;border:2px solid #1a56b8;border-radius:10px;padding:12px 16px;max-width:480px;color:#1a56b8;font-family:Arial;font-size:9.5pt;line-height:1.5}
  .stamp b{font-size:10.5pt;letter-spacing:.04em}
  @media print{ body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:8mm} }
</style></head>
<body>
<div class="toolbar">
    <a href="/timesheet2?kind=shift&month=<?= e($month) ?>&half=<?= e((string)$half) ?>">← К табелям</a>
    <button class="primary" onclick="window.print()">⬇ Скачать PDF / Печать</button>
    <a href="/timesheet2/<?= (int)$t['id'] ?>/export">Excel</a>
    <span>Документ подписан — изменения только корректировочным табелем.</span>
</div>

<div class="sheet">
    <table class="okud"><tr><td>Форма по ОКУД</td><td>0504421</td></tr></table>
    <div class="hd"><span class="lbl">Учреждение:</span> <strong><?= e($orgName) ?></strong></div>
    <div class="hd"><span class="lbl">Структурное подразделение:</span> <strong><?= e($t['dept_name'] ?: '—') ?></strong></div>
    <h1>Табель учёта использования рабочего времени</h1>
    <p class="sub">
        № <?= (int)$t['id'] ?> от <?= e($signDate) ?> · за период с <?= $d1 ?> по <?= $d2 ?> <?= e($monthsRu[$mm] ?? '') ?> <?= $yy ?> г.
        · <?= (int)$t['revision'] === 0 ? 'первичный' : 'корректировочный № ' . (int)$t['revision'] ?>
    </p>

    <table class="tb">
        <thead>
        <tr>
            <th style="width:26px">№</th><th class="name" style="width:200px">Ф.И.О., должность</th>
            <th style="width:34px"></th>
            <?php foreach ($dates as $d): ?><th class="dnum"><?= (int)substr($d,8,2) ?></th><?php endforeach; ?>
            <th style="width:54px">Итого</th>
        </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($rows as $r): $n++; $cells = $r['cells_arr'] ?? []; ?>
        <tr>
            <td rowspan="2"><?= $n ?></td>
            <td class="name" rowspan="2"><?= e($r['full_name']) ?><br><i style="font-size:8pt"><?= e($r['position']) ?></i></td>
            <td class="cap">код</td>
            <?php foreach ($dates as $i => $d): ?><td class="code"><?= e($cells[$i]['c'] ?? '') ?></td><?php endforeach; ?>
            <td rowspan="2"><b><?= (int)$r['days'] ?></b> дн.<br><span class="hours"><?= e(TabelController::fmtHours((float)$r['hours'])) ?> ч</span></td>
        </tr>
        <tr>
            <td class="cap">часы</td>
            <?php foreach ($dates as $i => $d): ?><td class="hours"><?= e($cells[$i]['h'] ?? '') ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="legend">Условные обозначения: <strong>Я</strong> — явка днём, <strong>Н</strong> — ночная работа, <strong>Я/Н</strong> — день и ночь (часы «дневные/ночные»), <strong>О</strong> — отпуск. Пусто — выходной по графику.</p>

    <table class="signs">
        <tr>
            <td style="width:50%"><?= e($signers[0] ?? 'Ответственное лицо') ?> <span class="sigline">&nbsp;</span><br><span class="cap">(должность, подпись, расшифровка)</span></td>
            <td><?= e($signers[1] ?? 'Главный бухгалтер') ?> <span class="sigline">&nbsp;</span><br><span class="cap">(должность, подпись, расшифровка)</span></td>
        </tr>
    </table>

    <div class="stamp">
        <b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ</b><br>
        Вид подписи: <?= e(TabelController::SIGN_TYPES[$t['sign_type']] ?? $t['sign_type']) ?><br>
        Сертификат: <?= e($t['cert_serial']) ?><br>
        Владелец: <?= e($t['signer_name']) ?><?= $t['signer_position'] ? ', ' . e($t['signer_position']) : '' ?><br>
        <?php if ($cert): ?>Действителен: с <?= e($cert['issued_at']) ?> по <?= e($cert['valid_to']) ?><br><?php endif; ?>
        Подписано: <?= e(substr((string)$t['signed_at'],0,16)) ?><br>
        Отпечаток: <?= e((string)$t['sign_hash']) ?>
    </div>
</div>
</body></html>
