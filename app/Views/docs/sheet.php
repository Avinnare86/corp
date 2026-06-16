<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Лист согласования</title>
<style>
  body{font-family:'Times New Roman',serif;font-size:12pt;color:#000;max-width:800px;margin:24px auto;padding:0 20px;line-height:1.4}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border:1px solid #ccd;border-radius:8px;padding:10px 14px;margin-bottom:24px;display:flex;gap:10px}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  h1{text-align:center;font-size:13pt;text-transform:uppercase}
  table{border-collapse:collapse;width:100%;font-size:10.5pt;margin-top:14px}
  th,td{border:1px solid #000;padding:5px 7px;text-align:left;vertical-align:top}
  th{text-align:center}
  @media print{ .toolbar{display:none} body{margin:0} }
</style>
</head>
<body>
<div class="toolbar">
    <a href="/docs/<?= (int)$doc['id'] ?>">← К документу</a>
    <button class="primary" onclick="window.print()">🖨 Печать</button>
</div>

<h1>Лист согласования</h1>
<p style="text-align:center">
    <?= e($doc['type_name']) ?> «<?= e($doc['title']) ?>»<br>
    <?= $doc['reg_number'] ? 'Рег. № ' . e($doc['reg_number']) : 'проект (без рег. номера)' ?>
    · Автор: <?= e($doc['author_name']) ?><?= $doc['dept_name'] ? ' (' . e($doc['dept_name']) . ')' : '' ?>
</p>

<table>
    <tr><th>Этап</th><th>Участник</th><th>Результат</th><th>Дата</th><th>Виза (комментарий)</th></tr>
    <?php
    $labels = \App\Controllers\DocumentController::STAGE_LABEL;
    $res = ['approved'=>'Согласовано','acked'=>'Ознакомлен','rejected'=>'ОТКЛОНЕНО','pending'=>'—'];
    foreach ($route as $r): ?>
    <tr>
        <td><?= (int)$r['step_no'] ?>. <?= e($labels[$r['stage_type']] ?? '') ?></td>
        <td><?= e($r['full_name']) ?><br><i style="font-size:9.5pt"><?= e($r['position']) ?></i>
            <?= $r['behalf_name'] ? '<br><i style="font-size:9pt">(визировал зам.: ' . e($r['behalf_name']) . ')</i>' : '' ?></td>
        <td><?= e($res[$r['status']] ?? $r['status']) ?><?= $r['stage_type']==='sign' && $r['status']==='approved' ? ' (подписано)' : '' ?></td>
        <td><?= e($r['decided_at'] ? substr((string)$r['decided_at'],0,16) : '') ?></td>
        <td><?= e($r['comment'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php $signed = array_filter($route, fn($r) => ($r['stage_type'] ?? '') === 'sign' && !empty($r['sign_hash'])); ?>
<?php if ($signed): ?>
<div style="margin-top:22px">
    <?php foreach ($signed as $r): ?>
        <?= ep_stamp('Подписант' . ($r['behalf_name'] ? ' (за ' . $r['full_name'] . ')' : ''), $r['behalf_name'] ?: $r['full_name'], $r['decided_at'], $r['sign_type'] ?? 'PEP', $r['sign_hash']) ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p style="margin-top:30px">Лист сформирован: <?= date('d.m.Y H:i') ?></p>
</body>
</html>
