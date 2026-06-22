<?php
/** Табель учёта использования рабочего времени — форма по ОКУД 0504421 (Приказ Минфина № 52н).
 *  Способ заполнения — сплошная регистрация явок/неявок: в каждой ячейке код + часы; два кода в один день —
 *  дробью «Я/Н» и часы «4/8» (Письмо Минфина № 02-06-10/32007). Лист А4 со штампом ЭП — печать → PDF. */
use App\Controllers\TabelController;
$OK = TabelController::OKUD_CODES;
$month = substr($t['period'], 0, 7);
$half = (int) substr($t['period'], 8);
$mm = (int) substr($month, 5, 2);
$yy = (int) substr($month, 0, 4);
$d1 = (int) substr($dates[0], 8, 2);
$d2 = (int) substr(end($dates), 8, 2);
$signDate = $t['signed_at'] ? date('d.m.Y', strtotime($t['signed_at'])) : date('d.m.Y');
$halfLabel = $half === 1 ? 'с 1 по 15' : 'с 16 по ' . $d2;

// «Данные для начисления заработной платы»: свод по кодам (дни/часы). Дробные «Я/Н 4/8» раскладываем
// на составляющие (Я — дневные часы, Н — ночные часы); день засчитывается один раз основному коду.
$pay = [];
$add = function (string $code, float $hours, bool $day) use (&$pay, $OK) {
    if (!isset($pay[$code])) { $pay[$code] = ['days' => 0, 'hours' => 0.0]; }
    if ($day) { $pay[$code]['days']++; }
    $pay[$code]['hours'] += $hours;
};
foreach ($rows as $r) {
    foreach (($r['cells_arr'] ?? []) as $cell) {
        $c = (string) ($cell['c'] ?? ''); $h = (string) ($cell['h'] ?? '');
        if ($c === '') { continue; }
        if (strpos($c, '/') !== false) {
            $codes = explode('/', $c); $hrs = explode('/', $h);
            foreach ($codes as $i => $cc) { $add($cc, (float) ($hrs[$i] ?? 0), $i === 0); }
        } else {
            $add($c, $h !== '' ? (float) $h : 0.0, true);
        }
    }
}
uksort($pay, function ($a, $b) {
    $order = ['Я' => 1, 'Н' => 2, 'РВ' => 3, 'С' => 4];
    return ($order[$a] ?? 99) <=> ($order[$b] ?? 99) ?: strcmp($a, $b);
});
$fmt = fn($v) => TabelController::fmtHours((float) $v);
// в легенде показываем обязательные коды + те, что фактически использованы
$legendCodes = array_values(array_unique(array_merge(['Я', 'Н', 'РВ', 'С', 'К', 'О', 'ОД', 'Б', 'Р', 'ОЖ', 'ДО', 'У', 'Г', 'ПР', 'НН', 'В'], array_keys($pay))));
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Табель 0504421 <?= e($t['period']) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:10pt;color:#000;background:#888;margin:0;padding:22px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:1200px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:1200px;margin:0 auto;padding:30px 34px;box-shadow:0 4px 24px rgba(0,0,0,.4)}
  .codes{float:right;border-collapse:collapse;font-size:8.5pt;margin-left:14px}
  .codes td,.codes th{border:1px solid #000;padding:2px 6px;text-align:center}
  .hd{margin:0 0 3px;font-size:10pt}
  .hd .u{border-bottom:1px solid #000;padding:0 6px}
  .lbl{font-size:8pt;color:#444}
  h1{text-align:center;font-size:12pt;margin:14px 0 2px}
  .docline{display:flex;gap:0;border:1px solid #000;font-size:8.5pt;margin:8px 0 12px;width:max-content}
  .docline div{border-right:1px solid #000;padding:2px 8px;text-align:center}
  .docline div:last-child{border-right:none}
  .docline .v{font-weight:bold;font-size:10pt}
  table.tb{border-collapse:collapse;width:100%;font-size:8.5pt;table-layout:fixed}
  table.tb th,table.tb td{border:1px solid #000;padding:2px 3px;text-align:center;word-wrap:break-word}
  table.tb td.name{text-align:left;font-size:8.5pt}
  table.tb th.dn{width:22px}
  .cap{font-size:7.5pt;color:#444}
  .code{font-weight:bold}
  .hours{color:#333;font-size:8pt}
  .pay{border-collapse:collapse;font-size:9pt;margin-top:14px}
  .pay th,.pay td{border:1px solid #000;padding:3px 8px}
  .pay th{background:#f3f3f3}
  .legend{font-size:8pt;margin:10px 0 0;line-height:1.5;columns:2;column-gap:26px}
  .signs{margin-top:24px;font-size:10pt;width:100%}
  .signs td{padding:10px 8px;vertical-align:bottom}
  .sigline{border-bottom:1px solid #000;min-width:150px;display:inline-block}
  .cp{font-size:8pt;color:#444}
  .stamp{margin-top:16px;border:2px solid #1a56b8;border-radius:10px;padding:12px 16px;max-width:480px;color:#1a56b8;font-family:Arial;font-size:9.5pt;line-height:1.5}
  .stamp b{font-size:10.5pt;letter-spacing:.04em}
  @media print{ @page{size:A4 landscape} body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:7mm} }
</style></head>
<body>
<div class="toolbar">
    <a href="/timesheet2?kind=shift&month=<?= e($month) ?>&half=<?= e((string)$half) ?>">← К табелям</a>
    <button class="primary" onclick="window.print()">⬇ Скачать PDF / Печать</button>
    <a href="/timesheet2/<?= (int)$t['id'] ?>/export">Excel</a>
    <span>Документ подписан — изменения только корректировочным табелем.</span>
</div>

<div class="sheet">
    <table class="codes">
        <tr><td></td><td>КОДЫ</td></tr>
        <tr><td style="text-align:left">Форма по ОКУД</td><td><b>0504421</b></td></tr>
        <tr><td style="text-align:left">Дата</td><td><?= e($signDate) ?></td></tr>
        <tr><td style="text-align:left">по ОКПО</td><td>&nbsp;</td></tr>
    </table>
    <p class="hd">Учреждение <span class="u"><?= e($orgName) ?></span></p>
    <p class="lbl" style="margin:0 0 4px">(наименование)</p>
    <p class="hd">Структурное подразделение <span class="u"><?= e($t['dept_name'] ?: '—') ?></span></p>
    <p class="lbl" style="margin:0 0 2px">(наименование)</p>

    <h1>Табель учёта использования рабочего времени</h1>

    <div class="docline">
        <div><span class="lbl">Номер документа</span><br><span class="v"><?= (int)$t['id'] ?><?= (int)$t['revision'] > 0 ? '/к' . (int)$t['revision'] : '' ?></span></div>
        <div><span class="lbl">Дата составления</span><br><span class="v"><?= e($signDate) ?></span></div>
        <div><span class="lbl">Отчётный период<br>с</span><br><span class="v"><?= sprintf('%02d.%02d.%d', $d1, $mm, $yy) ?></span></div>
        <div><span class="lbl">по</span><br><span class="v"><?= sprintf('%02d.%02d.%d', $d2, $mm, $yy) ?></span></div>
        <div><span class="lbl">Вид табеля</span><br><span class="v"><?= (int)$t['revision'] === 0 ? 'первичный' : 'корректирующий' ?></span></div>
        <div><span class="lbl">Номер<br>корректировки</span><br><span class="v"><?= (int)$t['revision'] ?></span></div>
    </div>

    <table class="tb">
        <thead>
        <tr>
            <th style="width:24px">№</th><th class="name" style="width:200px">Должность, Ф.И.О.</th>
            <th style="width:30px"></th>
            <?php foreach ($dates as $d): ?><th class="dn"><?= (int)substr($d,8,2) ?></th><?php endforeach; ?>
            <th style="width:70px">Отработано <?= e($halfLabel) ?> (дни/часы)</th>
        </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($rows as $r): $n++; $cells = $r['cells_arr'] ?? []; ?>
        <tr>
            <td rowspan="2"><?= $n ?></td>
            <td class="name" rowspan="2"><?= e($r['full_name']) ?><br><i style="font-size:7.5pt"><?= e($r['position']) ?></i></td>
            <td class="cap">код</td>
            <?php foreach ($dates as $i => $d): ?><td class="code"><?= e($cells[$i]['c'] ?? '') ?></td><?php endforeach; ?>
            <td rowspan="2"><b><?= (int)$r['days'] ?></b> / <?= e($fmt($r['hours'])) ?></td>
        </tr>
        <tr>
            <td class="cap">часы</td>
            <?php foreach ($dates as $i => $d): ?><td class="hours"><?= e($cells[$i]['h'] ?? '') ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($dates)+4 ?>" class="name" style="color:#777">Нет сотрудников на графике 2/2.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php if ($pay): ?>
    <p style="margin:14px 0 4px;font-weight:bold">Данные для начисления заработной платы</p>
    <table class="pay">
        <thead><tr><th>Код вида оплаты</th><th>Вид использования рабочего времени</th><th>Дни</th><th>Часы</th></tr></thead>
        <tbody>
        <?php foreach ($pay as $code => $agg): ?>
            <tr>
                <td style="text-align:center;font-weight:bold"><?= e($code) ?></td>
                <td><?= e($OK[$code][0] ?? $code) ?></td>
                <td style="text-align:center"><?= $agg['days'] ? (int)$agg['days'] : '—' ?></td>
                <td style="text-align:center"><?= $agg['hours'] > 0 ? e($fmt($agg['hours'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="legend"><strong>Условные обозначения:</strong><br>
        <?php foreach ($legendCodes as $code): if (!isset($OK[$code])) continue; ?>
            <strong><?= e($code) ?></strong> — <?= e($OK[$code][0]) ?>;<br>
        <?php endforeach; ?>
        <em>Я/Н + «дн/ночь»</em> — рабочий день с дневными и ночными часами (дробью, напр. «4/8»).
    </p>

    <table class="signs">
        <tr>
            <td style="width:50%">Ответственный исполнитель
                <span class="sigline">&nbsp;</span> <?= e($signers[1] ?? '') ?><br>
                <span class="cp">(должность) (подпись) (расшифровка подписи)</span></td>
            <td>Руководитель структурного подразделения
                <span class="sigline">&nbsp;</span><br>
                <span class="cp">(должность) (подпись) (расшифровка подписи)</span></td>
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
