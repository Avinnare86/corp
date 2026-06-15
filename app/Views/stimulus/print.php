<?php
$months = [1=>'январе',2=>'феврале',3=>'марте',4=>'апреле',5=>'мае',6=>'июне',7=>'июле',8=>'августе',9=>'сентябре',10=>'октябре',11=>'ноябре',12=>'декабре'];
$monthsG = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
[$yy,$mm] = array_map('intval', explode('-', $memo['period']));
$lastDay = date('t', mktime(0,0,0,$mm,1,$yy));
$docDate = $memo['head_signed_at'] ? date('d.m.Y', strtotime($memo['head_signed_at'])) : date('d.m.Y', strtotime($memo['created_at']));
$groundsLc = array_map(fn($g)=>mb_strtolower(mb_substr($g,0,1)).mb_substr($g,1), $grounds);
$total = array_sum(array_map(fn($l)=>(float)$l['amount'], $lines));
$rows = $groundRows ?? array_map(fn($t)=>['text'=>$t,'category'=>'','percent'=>0], $grounds);
$pctf = fn($p)=>rtrim(rtrim(number_format((float)$p,1,'.',''),'0'),'.');
$stamp = function($who, $s, $at, $type, $hash) {
    if (!$at) return '';
    return '<div class="stamp"><b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ</b><br>'
        . 'Роль: ' . htmlspecialchars($who, ENT_QUOTES) . '<br>'
        . 'Вид подписи: ' . htmlspecialchars(['PEP'=>'ПЭП (простая ЭП)','UNEP'=>'УНЭП','UKEP'=>'УКЭП'][$type] ?? ($type ?: 'ПЭП'), ENT_QUOTES) . '<br>'
        . 'Владелец: ' . htmlspecialchars(($s['full_name'] ?? ''), ENT_QUOTES) . '<br>'
        . 'Подписано: ' . htmlspecialchars(substr((string)$at,0,16), ENT_QUOTES) . '<br>'
        . 'Отпечаток: ' . htmlspecialchars(substr((string)$hash,0,32), ENT_QUOTES) . '…</div>';
};
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Служебка №<?= e($memo['number'] ?: $memo['id']) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:13pt;color:#000;background:#888;margin:0;padding:24px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:820px;display:flex;gap:10px;align-items:center}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:820px;margin:0 auto;padding:28mm 20mm;box-shadow:0 4px 24px rgba(0,0,0,.4);box-sizing:border-box}
  .addr{margin-left:55%;text-align:left;margin-bottom:24px;line-height:1.4}
  h1{text-align:center;font-size:14pt;margin:8px 0 2px;text-transform:uppercase;letter-spacing:.02em}
  .sub{text-align:center;font-style:italic;margin:0 0 4px}
  .num{text-align:center;margin:0 0 18px}
  .body{text-align:justify;line-height:1.5;text-indent:1.25cm}
  table.t{border-collapse:collapse;width:100%;font-size:11.5pt;margin:14px 0}
  table.t th,table.t td{border:1px solid #000;padding:5px 8px;vertical-align:top}
  table.t th{background:#f3f3f3;text-align:center;font-weight:bold}
  table.t td.r{text-align:right;white-space:nowrap}
  .sign-row{margin-top:28px;display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
  .stamp{border:2px solid #1a56b8;border-radius:10px;padding:10px 14px;color:#1a56b8;font-family:Arial;font-size:9pt;line-height:1.5;margin:14px 0;max-width:430px}
  .stamp b{font-size:10pt;letter-spacing:.03em}
  .status{font-family:Arial;font-size:10pt;color:#666;text-align:center;margin-top:10px}
  @media print{ body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:18mm} }
</style></head>
<body>
<div class="toolbar">
    <a href="/memos/<?= (int)$memo['id'] ?>">← К служебке</a>
    <button class="primary" onclick="window.print()">⬇ Скачать PDF / Печать</button>
    <span>Сформированный документ. ЭП проставляется по мере подписания.</span>
</div>

<?php $isMgmt = ($kind ?? 'staff') === 'mgmt'; ?>
<div class="sheet">
    <?php if ($isMgmt): ?>
    <div class="addr" style="margin-left:auto;width:48%">
        УТВЕРЖДАЮ<br>Генеральный директор<br>ФГБУ «Интеробразование»<br><?= e($directorName) ?>
    </div>
    <h1>Приказ (служебная записка)</h1>
    <p class="sub">Об установлении стимулирующих выплат заместителям директора и главному бухгалтеру</p>
    <p class="num"><?= e($docDate) ?> № <?= e($memo['number'] ?: '________') ?></p>
    <p class="body">
        На основании Положения об оплате и стимулировании труда работников ФГБУ «Центр развития образования
        и международной деятельности («Интеробразование»)» и в соответствии с перечнем показателей (Приложение № 2)
        установить стимулирующие выплаты<?= $source ? ' из средств ' . e($source['name']) . ($source['detail'] ? ' ' . e($source['detail']) : '') : '' ?>
        за <?= e(implode(', ', $groundsLc)) ?> следующим работникам:
    </p>
    <?php else: ?>
    <div class="addr">
        Генеральному директору<br>ФГБУ «Интеробразование»<br><?= e($directorName) ?>
    </div>

    <h1>Служебная записка</h1>
    <p class="sub">Об установлении выплаты стимулирующего характера</p>
    <p class="num"><?= e($docDate) ?> № <?= e($memo['number'] ?: '________') ?></p>

    <p class="body">
        На основании Положения об оплате и стимулировании труда работников федерального государственного
        бюджетного учреждения «Центр развития образования и международной деятельности («Интеробразование»)»
        и в соответствии с перечнем показателей интенсивности, результативности и качества работы для различных
        категорий работников (раздел 4), прошу установить стимулирующие выплаты<?= $source ? ' из средств ' . e($source['name']) . ($source['detail'] ? ' ' . e($source['detail']) : '') : '' ?>
        за <?= e(implode(', ', $groundsLc)) ?><?= $memo['dept_name'] ? ' следующим работникам ' . e(mb_strtolower($memo['dept_name'])) : ' следующим работникам' ?>:
    </p>
    <?php endif; ?>

    <table class="t">
        <tr><th>Ф.И.О. работника (полностью)</th><th>Должность</th><th>Размер выплаты, ₽</th><th>Срок действия выплаты</th></tr>
        <?php foreach ($lines as $l):
            $srok = $l['pay_kind'] === 'onetime'
                ? 'Единовременная в ' . $months[$mm] . ' ' . $yy . ' г.'
                : 'с 01.' . sprintf('%02d', $mm) . '.' . $yy . ' по ' . $lastDay . '.' . sprintf('%02d', $mm) . '.' . $yy . ' пропорционально отработанному времени';
        ?>
        <tr>
            <td><?= e($l['full_name']) ?></td>
            <td><?= e($l['position']) ?></td>
            <td class="r"><?= number_format((float)$l['amount'], 2, ',', ' ') ?></td>
            <td><?= e($srok) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="2" style="text-align:right;font-weight:bold">ИТОГО:</td>
            <td class="r" style="font-weight:bold"><?= number_format((float)$total, 2, ',', ' ') ?></td>
            <td></td>
        </tr>
    </table>

    <?php if ($rows): ?>
    <p class="body" style="text-indent:0;margin-bottom:4px"><b>Основания (раздел 4 Положения):</b></p>
    <table class="t">
        <tr><th style="width:80%">Показатель интенсивности, результативности и качества</th><th>Норматив, %</th></tr>
        <?php foreach ($rows as $g): ?>
        <tr>
            <td><?= e($g['text']) ?><?= !empty($g['category']) ? ' <i>(' . e($g['category']) . ')</i>' : '' ?></td>
            <td class="r"><?= (float)$g['percent'] > 0 ? 'до ' . $pctf($g['percent']) . '%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php
        $signPos = $memo['author_position'] ?: ('Начальник ' . ($memo['dept_name'] ? mb_strtolower($memo['dept_name']) : 'отдела'));
        $signName = $memo['author_name'] ?? '';
        // «И.О. Фамилия» из «Фамилия Имя Отчество»
        $parts = preg_split('/\s+/u', trim((string)$signName));
        $short = $signName;
        if (count($parts) >= 2) { $short = mb_substr($parts[1],0,1) . '.' . (isset($parts[2]) ? mb_substr($parts[2],0,1) . '. ' : ' ') . $parts[0]; }
    ?>
    <table style="width:100%;margin-top:30px;border:none">
        <tr style="border:none">
            <td style="border:none;padding:0;vertical-align:bottom"><?= e($signPos) ?></td>
            <td style="border:none;padding:0;text-align:center;vertical-align:bottom;width:160px">_________________<br><span style="font-size:9pt">(подпись)</span></td>
            <td style="border:none;padding:0;text-align:right;vertical-align:bottom;white-space:nowrap"><?= e($short) ?></td>
        </tr>
    </table>

    <?= $stamp('Начальник отдела (составил)', $signers['head'], $memo['head_signed_at'], $memo['head_sign_type'], $memo['head_sign_hash']) ?>
    <?= $stamp('Курирующий заместитель директора (утвердил)', $signers['deputy'], $memo['deputy_signed_at'], $memo['deputy_sign_type'], $memo['deputy_sign_hash']) ?>
    <?= $stamp('Директор (утвердил)', $signers['director'], $memo['director_signed_at'], $memo['director_sign_type'], $memo['director_sign_hash']) ?>

    <?php if (!$memo['head_signed_at']): ?>
        <div class="status">⚠ Черновик — ещё не подписан. ЭП появится после подписания.</div>
    <?php endif; ?>
</div>
</body></html>
