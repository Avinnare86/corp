<?php
/** Унифицированная форма Т-7 «График отпусков» (Пост. Госкомстата РФ от 05.01.2004 № 1).
 *  А4-лист со штампом ЭП директора (гриф «УТВЕРЖДАЮ»). Печать → PDF.
 *  @var array $s rows deptNames orgName hrHead sig canSignAsDirector signTypes csrf */
use App\Services\VacationScheduleService as VS;
use App\Controllers\VacationScheduleController as VSC;
$signed = $s['status'] === VS::ST_SIGNED;
$year = (int) $s['year'];
$rev = (int) $s['revision'];
$docNo = ($rev === 0 ? '1' : ('1/изм.' . $rev));
$signDate = $signed && $s['signed_at'] ? date('d.m.Y', strtotime($s['signed_at'])) : '';
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>График отпусков Т-7 на <?= $year ?></title>
<style>
  body{font-family:'Times New Roman',serif;font-size:10pt;color:#000;background:#888;margin:0;padding:22px}
  .toolbar{font-family:Arial;font-size:11pt;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 16px;max-width:1180px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .toolbar a,.toolbar button{font-family:Arial;padding:8px 14px;border-radius:6px;border:1px solid #99a;background:#fff;cursor:pointer;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B}
  .sheet{background:#fff;max-width:1180px;margin:0 auto;padding:28px 32px;box-shadow:0 4px 24px rgba(0,0,0,.4)}
  .approve{float:right;width:300px;text-align:left;font-size:10pt;line-height:1.5;margin:0 0 10px 20px}
  .approve .u{border-bottom:1px solid #000;display:inline-block;min-width:150px}
  .org{font-size:11pt}
  .union{clear:both;font-size:9pt;color:#333;margin:6px 0 0}
  h1.t7{text-align:center;font-size:13pt;letter-spacing:.04em;margin:18px 0 2px}
  .docmeta{text-align:center;font-size:9.5pt;margin:0 0 12px}
  table.t7{border-collapse:collapse;width:100%;font-size:8.6pt}
  table.t7 th,table.t7 td{border:1px solid #000;padding:3px 4px;vertical-align:middle}
  table.t7 th{text-align:center;font-weight:bold;font-size:8pt}
  table.t7 td.c{text-align:center}
  .colnum td{text-align:center;font-size:7.5pt;color:#555;padding:1px 4px}
  .signs{margin-top:22px;font-size:10pt;width:100%}
  .signs td{padding:12px 8px;vertical-align:bottom}
  .sigline{border-bottom:1px solid #000;min-width:150px;display:inline-block}
  .cp{font-size:8pt;color:#444}
  .stamp{margin-top:16px;border:2px solid #1a56b8;border-radius:10px;padding:12px 16px;max-width:500px;color:#1a56b8;font-family:Arial;font-size:9.5pt;line-height:1.5}
  @media print{ .toolbar{display:none} body{background:#fff;padding:0} .sheet{box-shadow:none;max-width:none;padding:8mm} }
</style></head><body>

<div class="toolbar">
    <a href="/vacation-schedule">← к списку</a>
    <button class="primary" onclick="window.print()">🖨 Печать / PDF</button>
    <?php if (!$signed): ?><a href="/vacation-schedule/<?= (int) $s['id'] ?>/edit">✎ Редактировать</a><?php endif; ?>
    <?php if (!$signed && $canSignAsDirector): ?>
        <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/sign" style="display:flex;gap:8px;align-items:center;margin:0">
            <?= csrf_field() ?>
            <select name="sign_type" required><?php foreach ($signTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
            <input type="password" name="password" placeholder="пароль ЭП" required>
            <button class="primary">Утвердить ЭЦП (директор)</button>
        </form>
    <?php elseif (!$signed): ?>
        <span style="color:#a40000">Не подписан — утверждает директор своей ЭЦП.</span>
    <?php endif; ?>
</div>

<div class="sheet">
    <div class="approve">
        УТВЕРЖДАЮ<br>
        Руководитель <?= e($orgName) ?><br>
        <span class="u"><?= $signed ? e($s['signer_name']) : '&nbsp;' ?></span> / <span class="u">&nbsp;</span><br>
        <span class="cp">(подпись)&nbsp;&nbsp;&nbsp;(расшифровка подписи)</span><br>
        «<?= $signed && $signDate ? e(substr($signDate, 0, 2)) : '__' ?>» <?= $signed && $signDate ? '' : '____________' ?> <?= $year ?> г.
    </div>
    <div class="org"><strong><?= e($orgName) ?></strong></div>
    <p class="union">Мнение выборного профсоюзного органа <span class="u" style="display:inline-block;min-width:200px;border-bottom:1px solid #000">&nbsp;</span> учтено (протокол № ___ от ___)</p>

    <h1 class="t7">ГРАФИК ОТПУСКОВ<?= $rev > 0 ? ' (изменение № ' . $rev . ')' : '' ?></h1>
    <p class="docmeta">Номер документа: <?= e($docNo) ?> &nbsp;·&nbsp; Дата составления: <?= $signed && $signDate ? e($signDate) : date('d.m.Y') ?> &nbsp;·&nbsp; На <strong><?= $year ?></strong> год</p>

    <table class="t7">
        <thead>
            <tr>
                <th rowspan="2" style="width:26px">№</th>
                <th rowspan="2">Структурное подразделение</th>
                <th rowspan="2">Должность (специальность, профессия) по штатному расписанию</th>
                <th rowspan="2">Фамилия, имя, отчество</th>
                <th rowspan="2">Табельный номер</th>
                <th colspan="3">ОТПУСК</th>
                <th colspan="2">Перенесение отпуска</th>
                <th rowspan="2">Примечание</th>
            </tr>
            <tr>
                <th>количество календарных дней</th>
                <th>дата запланированная</th>
                <th>дата фактическая</th>
                <th>основание (документ)</th>
                <th>дата предполагаемого отпуска</th>
            </tr>
            <tr class="colnum">
                <td>1</td><td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td><td>8</td><td>9</td><td>10</td>
            </tr>
        </thead>
        <tbody>
        <?php $i = 0; foreach ($rows as $r): $i++; $dep = $deptNames[(int) ($r['department_id'] ?? 0)] ?? '—'; ?>
            <tr>
                <td class="c"><?= $i ?></td>
                <td><?= e($dep) ?></td>
                <td><?= e((string) ($r['position'] ?? '')) ?></td>
                <td><?= e($r['full_name']) ?></td>
                <td class="c"><?= e(VS::tabNumber((int) $r['employee_id'])) ?></td>
                <td class="c"><?= (int) $r['days'] ?></td>
                <td class="c"><?= e(date('d.m.Y', strtotime($r['start_date']))) ?></td>
                <td class="c"></td>
                <td></td>
                <td class="c"></td>
                <td><?= e((string) ($r['note'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="c">Периоды отпуска не заданы.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <table class="signs">
        <tr>
            <td style="width:50%">Руководитель кадровой службы
                <span class="sigline">&nbsp;</span> <?= e($hrHead['full_name'] ?? '') ?><br>
                <span class="cp">(должность<?= !empty($hrHead['position']) ? ': ' . e($hrHead['position']) : '' ?>) &nbsp; (подпись) &nbsp; (расшифровка подписи)</span></td>
            <td>&nbsp;</td>
        </tr>
    </table>

    <?php if ($signed): ?>
    <div class="stamp">
        <b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ (УТВЕРЖДЁН ДИРЕКТОРОМ)</b><br>
        Вид подписи: <?= e(VSC::SIGN_TYPES[$s['sign_type']] ?? $s['sign_type']) ?><br>
        Сертификат: <?= e((string) $s['cert_serial']) ?><br>
        Владелец: <?= e((string) $s['signer_name']) ?><?= $s['signer_position'] ? ', ' . e((string) $s['signer_position']) : '' ?><br>
        <?php if (!empty($sig['fingerprint'])): ?>Отпечаток: <?= e((string) $sig['fingerprint']) ?><br><?php endif; ?>
        Подписано: <?= e(substr((string) $s['signed_at'], 0, 16)) ?><br>
        Хэш содержимого: <?= e((string) $s['sign_hash']) ?>
        <?php if (!empty($sig['sig_b64'])): ?><br>Прикреплена усиленная подпись (.sig)<?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body></html>
