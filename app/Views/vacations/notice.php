<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Уведомление об отпуске</title>
<style>
  body{font-family:'Times New Roman',serif;font-size:14pt;color:#000;max-width:760px;margin:30px auto;padding:0 20px;line-height:1.5}
  .toolbar{font-family:Arial,sans-serif;font-size:11pt;background:#f0f2f8;border:1px solid #ccd;border-radius:8px;padding:12px;margin-bottom:28px;display:flex;gap:10px;align-items:center}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .head{text-align:right;margin-bottom:30px}
  h1{text-align:center;font-size:14pt;text-transform:uppercase;letter-spacing:.06em}
  .sign{display:flex;justify-content:space-between;margin-top:60px}
  .ack{margin-top:50px;border-top:1px solid #000;padding-top:8px;font-size:12pt}
  @media print{ .toolbar{display:none} body{margin:0} }
</style>
</head>
<body>
<div class="toolbar">
    <a href="/vacations">← К графику</a>
    <button class="primary" onclick="window.print()">🖨 Печать (бумажное)</button>
    <?php if ($canIssue): ?>
    <form method="post" action="/vacations/<?= (int)$v['id'] ?>/send-notice" style="display:inline">
        <?= csrf_field() ?>
        <button class="primary">📧 Отправить электронно</button>
    </form>
    <?php endif; ?>
    <span><?= $v['notified_at'] ? 'Электронное уведомление направлено ' . e(substr($v['notified_at'],0,16)) : 'Электронно ещё не направлялось' ?></span>
</div>

<div class="head">
    <?= e($v['full_name']) ?><br>
    <?= e($v['position'] ?: '') ?><?= $v['dept_name'] ? '<br>' . e($v['dept_name']) : '' ?>
</div>

<h1>Уведомление о времени начала отпуска</h1>
<p style="text-align:center">№ ____ от <?= date('d.m.Y') ?></p>

<p style="text-indent:3em;margin-top:30px">
    В соответствии со статьёй 123 Трудового кодекса Российской Федерации уведомляем Вас о том,
    что согласно графику отпусков на <?= (int)$v['year'] ?> год Вам предоставляется ежегодный
    оплачиваемый отпуск продолжительностью <b><?= (int)$v['days'] ?></b> календарных дней
    с <b><?= e(date('d.m.Y', strtotime($v['start_date']))) ?></b>
    по <b><?= e(date('d.m.Y', strtotime($v['end_date']))) ?></b>.
</p>

<div class="sign">
    <span>Руководитель ____________________</span>
    <span>«____» ____________ <?= date('Y') ?> г.</span>
</div>

<div class="ack">
    С уведомлением ознакомлен(а):<br><br>
    ________________ / <?= e($v['full_name']) ?> /   «____» ____________ <?= date('Y') ?> г.
</div>
</body>
</html>
