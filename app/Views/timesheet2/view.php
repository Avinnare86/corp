<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Табель <?= e($t['period']) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:11pt;color:#000;background:#888;margin:0;padding:24px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:1050px;display:flex;gap:10px;align-items:center}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:1050px;margin:0 auto;padding:40px 46px;box-shadow:0 4px 24px rgba(0,0,0,.4);min-height:600px}
  h1{text-align:center;font-size:13pt;text-transform:uppercase;margin:0 0 4px}
  .sub{text-align:center;margin:0 0 18px}
  table.tb{border-collapse:collapse;width:100%;font-size:9.5pt}
  table.tb th,table.tb td{border:1px solid #000;padding:3px 4px;text-align:center}
  table.tb td.name{text-align:left}
  .stamp{margin-top:30px;border:2px solid #1a56b8;border-radius:10px;padding:12px 16px;max-width:480px;color:#1a56b8;font-family:Arial;font-size:9.5pt;line-height:1.5}
  .stamp b{font-size:10.5pt;letter-spacing:.04em}
  .meta{margin-top:16px;font-size:10pt}
  @media print{ body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:10mm} }
</style>
</head>
<body>
<div class="toolbar">
    <a href="/timesheet2?month=<?= e(substr($t['period'],0,7)) ?>&half=<?= e(substr($t['period'],8)) ?>">← К табелям</a>
    <button class="primary" onclick="window.print()">⬇ Скачать PDF / Печать</button>
    <a href="/timesheet2/<?= (int)$t['id'] ?>/export">Excel</a>
    <span>Документ подписан — изменения только корректировочным табелем.</span>
</div>

<div class="sheet">
    <h1>Табель учёта рабочего времени</h1>
    <p class="sub">
        за период <?= e($dates[0]) ?> — <?= e(end($dates)) ?>
        · охват: <?= e($t['dept_name'] ?: 'вся организация') ?>
        · <?= (int)$t['revision'] === 0 ? 'первичный' : 'корректировочный № ' . (int)$t['revision'] ?>
    </p>

    <table class="tb">
        <tr>
            <th style="width:30px">№</th><th class="name">ФИО, должность</th>
            <?php foreach ($dates as $d): ?><th><?= (int)substr($d,8,2) ?></th><?php endforeach; ?>
            <th>Дней</th>
        </tr>
        <?php $n=0; foreach ($rows as $r): $n++; $m=str_split((string)$r['day_marks']); ?>
        <tr>
            <td><?= $n ?></td>
            <td class="name"><?= e($r['full_name']) ?><br><i style="font-size:8.5pt"><?= e($r['position']) ?></i></td>
            <?php foreach ($dates as $i => $d): ?><td><?= e($codes[$m[$i] ?? '0'] ?? '') ?></td><?php endforeach; ?>
            <td><b><?= (int)$r['days'] ?></b></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="meta">
        Составил: табель сформирован в системе «Учёт работы специалистов» · ревизия <?= (int)$t['revision'] ?>
    </div>

    <div class="stamp">
        <b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ</b><br>
        Вид подписи: <?= e(\App\Controllers\TabelController::SIGN_TYPES[$t['sign_type']] ?? $t['sign_type']) ?><br>
        Сертификат: <?= e($t['cert_serial']) ?><br>
        Владелец: <?= e($t['signer_name']) ?><?= $t['signer_position'] ? ', ' . e($t['signer_position']) : '' ?><br>
        <?php if ($cert): ?>Действителен: с <?= e($cert['issued_at']) ?> по <?= e($cert['valid_to']) ?><br><?php endif; ?>
        Подписано: <?= e(substr((string)$t['signed_at'],0,16)) ?><br>
        Отпечаток: <?= e(substr((string)$t['sign_hash'],0,32)) ?>…
    </div>
</div>
</body>
</html>
