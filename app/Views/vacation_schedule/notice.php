<?php
/** Печатное уведомление об отпуске (извещение по ст. 123 ТК РФ).
 *  Шапка — ТОЛЬКО ФИО получателя (без отдела/должности). Штамп ЭП начальника отдела кадров.
 *  @var array $n hrHead sig orgName signTypes title */
use App\Controllers\VacationScheduleController as VSC;
$signed = $n['status'] !== 'draft' && !empty($n['signed_at']);
$year = (int) $n['year'];
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Уведомление об отпуске — <?= e($n['full_name']) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:14pt;color:#000;max-width:760px;margin:30px auto;padding:0 20px;line-height:1.5}
  .toolbar{font-family:Arial,sans-serif;font-size:11pt;background:#f0f2f8;border:1px solid #ccd;border-radius:8px;padding:12px;margin-bottom:28px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .head{text-align:right;margin-bottom:34px}
  h1{text-align:center;font-size:14pt;text-transform:uppercase;letter-spacing:.06em}
  .sign{display:flex;justify-content:space-between;margin-top:60px}
  .ack{margin-top:50px;border-top:1px solid #000;padding-top:8px;font-size:12pt}
  .stamp{margin-top:28px;border:2px solid #1a56b8;border-radius:10px;padding:12px 16px;max-width:520px;color:#1a56b8;font-family:Arial;font-size:9.5pt;line-height:1.5}
  @media print{ .toolbar{display:none} body{margin:0} }
</style></head><body>

<div class="toolbar">
    <a href="/vacation-schedule/notices?year=<?= $year ?>">← К списку</a>
    <button class="primary" onclick="window.print()">🖨 Печать / PDF</button>
    <span><?= $n['notified_at'] ? 'Направлено сотруднику: ' . e(substr($n['notified_at'], 0, 16)) : ($signed ? 'Подписано, ожидает рассылки' : 'Черновик — не подписано') ?></span>
</div>

<div class="head"><?= e($n['full_name']) ?></div>

<h1>Уведомление о времени начала отпуска</h1>
<p style="text-align:center">№ <?= (int) $n['id'] ?> от <?= $signed ? e(date('d.m.Y', strtotime($n['signed_at']))) : date('d.m.Y') ?></p>

<p style="text-indent:3em;margin-top:30px">
    В соответствии со статьёй 123 Трудового кодекса Российской Федерации уведомляем Вас о том,
    что согласно графику отпусков на <?= $year ?> год Вам предоставляется ежегодный
    оплачиваемый отпуск продолжительностью <b><?= (int) $n['days'] ?></b> календарных дней
    с <b><?= e(date('d.m.Y', strtotime($n['start_date']))) ?></b>
    по <b><?= e(date('d.m.Y', strtotime($n['end_date']))) ?></b>.
</p>

<div class="sign">
    <span>Начальник отдела кадров <?= e($hrHead['full_name'] ?? '____________________') ?></span>
    <span>«____» ____________ <?= $signed ? e(date('Y', strtotime($n['signed_at']))) : date('Y') ?> г.</span>
</div>

<div class="ack">
    С уведомлением ознакомлен(а):<br><br>
    ________________ / <?= e($n['full_name']) ?> /   «____» ____________ <?= date('Y') ?> г.
</div>

<?php if ($signed): ?>
<div class="stamp">
    <b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ (НАЧАЛЬНИК ОТДЕЛА КАДРОВ)</b><br>
    Вид подписи: <?= e(VSC::SIGN_TYPES[$n['sign_type']] ?? (string) $n['sign_type']) ?><br>
    Сертификат: <?= e((string) $n['cert_serial']) ?><br>
    Владелец: <?= e($hrHead['full_name'] ?? '') ?><?= !empty($hrHead['position']) ? ', ' . e($hrHead['position']) : '' ?><br>
    <?php if (!empty($sig['fingerprint'])): ?>Отпечаток: <?= e((string) $sig['fingerprint']) ?><br><?php endif; ?>
    Подписано: <?= e(substr((string) $n['signed_at'], 0, 16)) ?><br>
    Хэш содержимого: <?= e((string) $n['sign_hash']) ?>
    <?php if (!empty($sig['sig_b64'])): ?><br>Прикреплена усиленная подпись (.sig)<?php endif; ?>
</div>
<?php endif; ?>
</body></html>
