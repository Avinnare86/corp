<?php
/** График сменности (план) на месяц — колл-центр 2/2. Коды: Р рабочий, Р/Н рабочий+ночь («дн/ночь»),
 *  О отпуск, пусто — выходной по графику. Воскресенье — рабочий день (выходные скользящие). */
$mm = (int) substr($month, 5, 2);
$yy = (int) substr($month, 0, 4);
$monthsRu = [1=>'январь',2=>'февраль',3=>'март',4=>'апрель',5=>'май',6=>'июнь',7=>'июль',8=>'август',9=>'сентябрь',10=>'октябрь',11=>'ноябрь',12=>'декабрь'];
$monthU = mb_strtoupper($monthsRu[$mm] ?? '');
$wd = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>График сменности <?= e($month) ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:10pt;color:#000;background:#888;margin:0;padding:22px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:1280px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:1280px;margin:0 auto;padding:26px 30px;box-shadow:0 4px 24px rgba(0,0,0,.4)}
  .org{font-size:9.5pt;margin:0 0 2px}
  .sub{font-size:9pt;color:#222;margin:0 0 2px}
  h1{text-align:center;font-size:12pt;margin:12px 0 2px}
  .meta{font-size:9pt;margin:0 0 10px}
  table.g{border-collapse:collapse;width:100%;font-size:8pt;table-layout:fixed}
  table.g th,table.g td{border:1px solid #000;padding:1px 2px;text-align:center;word-wrap:break-word}
  table.g td.name{text-align:left;font-size:8pt;width:150px}
  table.g th.d,table.g td.d{width:20px}
  table.g .sun{color:#444}
  .code{font-weight:bold}
  .hh{font-size:7pt;color:#333}
  .legend{font-size:8.5pt;margin:8px 0 0}
  .foot{font-size:9pt;margin-top:10px;line-height:1.5}
  .signs{margin-top:22px;font-size:10pt;width:100%}
  .signs td{padding:12px 8px;vertical-align:bottom}
  .sig{border-bottom:1px solid #000;min-width:170px;display:inline-block}
  @media print{ @page{size:A4 landscape} body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:6mm} }
</style></head>
<body>
<div class="toolbar">
    <a href="/shifts?month=<?= e($month) ?>">← К графику</a>
    <button class="primary" onclick="window.print()">⬇ Скачать PDF / Печать</button>
    <a href="/shifts/grafik/export?dept=<?= (int)$deptId ?>&month=<?= e($month) ?>">Excel</a>
</div>

<div class="sheet">
    <p class="org"><strong><?= e($orgName) ?></strong></p>
    <p class="sub"><?= e($dept['name'] ?? '—') ?> <span style="color:#777">(наименование структурного подразделения)</span></p>
    <h1>График сменности</h1>
    <p class="meta">Период графика: с 01.<?= sprintf('%02d', $mm) ?>.<?= $yy ?> по <?= $lastDay ?>.<?= sprintf('%02d', $mm) ?>.<?= $yy ?> г. · Дата составления: <?= date('d.m.Y') ?> · Учётный период: 1 (один) год</p>

    <table class="g">
        <thead>
        <tr>
            <th style="width:24px">№</th><th class="name">Должность / Ф.И.О.</th>
            <?php for ($d = 1; $d <= $lastDay; $d++): $dow = (int) date('w', strtotime(sprintf('%s-%02d', $month, $d))); ?>
                <th class="d"><?= $d ?><br><span style="font-weight:400;font-size:7pt"><?= $wd[$dow] ?></span></th>
            <?php endfor; ?>
            <th style="width:60px">Итого дн (ч)</th>
        </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($rows as $r): $n++; ?>
            <tr>
                <td><?= $n ?></td>
                <td class="name"><?= e($r['emp']['full_name']) ?><br><i style="font-size:7.5pt"><?= e($r['emp']['position']) ?></i></td>
                <?php foreach ($r['cells'] as $c): ?>
                    <td class="d"><?php if ($c['c'] !== ''): ?><span class="code"><?= e($c['c']) ?></span><?php if ($c['h'] !== ''): ?><br><span class="hh"><?= e($c['h']) ?></span><?php endif; ?><?php endif; ?></td>
                <?php endforeach; ?>
                <td><strong><?= (int)$r['days'] ?></strong> (<?= e(\App\Controllers\ShiftController::fmtHours((float)$r['hours'])) ?>)</td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $lastDay + 3 ?>" style="text-align:left;color:#777">В отделе нет сотрудников на графике 2/2.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <p class="legend"><strong>Условные обозначения:</strong> <b>Р</b> — рабочий день; <b>Р/Н</b> — рабочий день с ночными часами (часы «дневные/ночные», напр. 4/8); <b>О</b> — отпуск; пусто — выходной по графику.</p>
    <div class="foot">
        Продолжительность ежедневной работы (смены) — 12 часов. Перерыв для отдыха и питания — 1 час.<br>
        Время начала работы: 07 ч 30 мин. Время окончания работы: 20 ч 30 мин. (для ночных смен — по графику).<br>
        <strong>Выходные дни — скользящие по графику сменности (воскресенье — рабочий день).</strong>
    </div>

    <table class="signs">
        <tr>
            <td style="width:50%">СОГЛАСОВАНО:<br><?= e($signApprove) ?> <span class="sig">&nbsp;</span></td>
            <td>График составил:<br>Начальник отдела <span class="sig">&nbsp;</span></td>
        </tr>
    </table>
</div>
</body></html>
