<?php
$d = fn($s) => date('d.m.Y', strtotime($s));
$orderDate = $t['director_signed_at'] ? $d($t['director_signed_at']) : date('d.m.Y');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Приказ о командировании — <?= e($emp['full_name'] ?? '') ?></title>
<style>
    @page { size: A4; margin: 20mm; }
    body { font-family: 'Times New Roman', serif; font-size: 14pt; line-height: 1.5; color: #000; max-width: 170mm; margin: 0 auto; padding: 16px; }
    h1 { text-align: center; font-size: 16pt; margin: 0 0 4px; }
    .sub { text-align: center; margin: 0 0 20px; }
    .row { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 16px; }
    .muted { color: #444; }
    p { margin: 0 0 10px; text-align: justify; }
    .sign { margin-top: 40px; display: flex; justify-content: space-between; }
    .noprint { margin: 16px 0; }
    @media print { .noprint { display: none; } body { padding: 0; } }
    .ul { display: inline-block; min-width: 120px; border-bottom: 1px solid #000; }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">🖨 Печать / сохранить в PDF</button> <a href="/trips/<?= (int) $t['id'] ?>">← к заявке</a></div>

<h1>ПРИКАЗ</h1>
<div class="row">
    <div>№ <span class="ul">&nbsp;</span></div>
    <div>«<?= substr($orderDate, 0, 2) ?>» <?= substr($orderDate, 3, 2) ?> <?= substr($orderDate, 6, 4) ?> г.</div>
</div>
<p class="sub"><strong>О направлении в служебную командировку</strong></p>

<p>В целях <?= e($t['purpose'] ?: ($t['event'] ?: 'выполнения служебного задания')) ?><?= $t['event'] && $t['purpose'] ? ' (' . e($t['event']) . ')' : '' ?> <strong>ПРИКАЗЫВАЮ:</strong></p>

<p>1. Направить в служебную командировку <strong><?= e($emp['full_name'] ?? '') ?></strong><?= $emp && $emp['position'] ? ', ' . e($emp['position']) : '' ?><?= $deptName ? ' (' . e($deptName) . ')' : '' ?>, в г. <strong><?= e($t['destination']) ?></strong> на срок <strong><?= (int) $days ?></strong> кал. дн. с <?= $d($t['date_from']) ?> по <?= $d($t['date_to']) ?>.</p>

<p>2. Командировочные расходы (суточные, проезд, проживание<?= '' ?>) произвести за счёт средств: <strong><?= e($sourceName ?: '—') ?></strong>.</p>

<p>3. Главному бухгалтеру обеспечить выплату аванса на командировочные расходы согласно утверждённой смете и приём авансового отчёта по возвращении.</p>

<p>Основание: служебная записка<?= $t['number'] ? ' № ' . e($t['number']) : '' ?><?= $t['submitted_at'] ? ' от ' . $d($t['submitted_at']) : '' ?>.</p>

<div class="sign">
    <div><?= e($dirPos ?: 'Генеральный директор') ?></div>
    <div>__________________ <?= e($dirName) ?></div>
</div>

<p class="muted" style="margin-top:30px;font-size:11pt">С приказом ознакомлен: __________________ / <?= e($emp['full_name'] ?? '') ?> / «____» __________ 20__ г.</p>
</body>
</html>
