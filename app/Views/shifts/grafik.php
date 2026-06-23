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
  .gwrap{overflow-x:auto;margin:0 0 2px}
  table.g{border-collapse:collapse;width:100%;min-width:900px;font-size:8pt;table-layout:fixed}
  table.g th,table.g td{border:1px solid #000;padding:1px 2px;text-align:center;overflow:hidden;vertical-align:middle}
  table.g td.d,table.g th.d{word-wrap:break-word}
  table.g td.name,table.g th.name{text-align:left;font-size:8pt;white-space:normal;word-break:normal;overflow-wrap:normal;line-height:1.2}
  table.g .sun{color:#444}
  .code{font-weight:bold}
  .hh{font-size:7pt;color:#333}
  .legend{font-size:8.5pt;margin:8px 0 0}
  .foot{font-size:9pt;margin-top:10px;line-height:1.5}
  .signs{margin-top:22px;font-size:10pt;width:100%}
  .signs td{padding:12px 8px;vertical-align:bottom}
  .sig{border-bottom:1px solid #000;min-width:170px;display:inline-block}
  .signbar{font-family:Arial;font-size:10.5pt;max-width:1280px;margin:0 auto 12px;padding:10px 14px;border-radius:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .signbar.ok{background:#e8f3ec;border:1px solid #b7d9c2;color:#1c5132}
  .signbar.signform{background:#fff7e6;border:1px solid #e6c97a;color:#5a4a1a}
  .signbar select,.signbar input,.signbar button{font-family:Arial;font-size:10.5pt;padding:7px 10px;border-radius:6px;border:1px solid #99a;background:#fff;color:#223}
  .signbar button.primary{background:#26368B;color:#fff;border-color:#26368B;cursor:pointer}
  .signbar .warn{color:#9a3412;font-weight:bold}
  .stamp{margin-top:16px;border:2px solid #1a56b8;border-radius:10px;padding:12px 16px;max-width:520px;color:#1a56b8;font-family:Arial;font-size:9.5pt;line-height:1.5}
  .stamp b{font-size:10.5pt;letter-spacing:.04em}
  @media print{ @page{size:A4 landscape} body{background:#fff;padding:0} .toolbar,.signbar.signform{display:none} .signbar{margin:0 0 6px} .sheet{box-shadow:none;max-width:none;padding:6mm} .gwrap{overflow:visible} table.g{min-width:0;font-size:6.5pt} table.g th.d,table.g td.d{padding:0} table.g th .wd,table.g th span{font-size:6pt} }
</style></head>
<body>
<div class="toolbar">
    <?php if (!empty($gidView)): ?>
        <a href="/shifts/grafik/archive">← В архив графиков</a>
    <?php else: ?>
        <a href="/shifts?month=<?= e($month) ?>&dept=<?= (int)$deptId ?>">← К графику</a>
    <?php endif; ?>
    <button class="primary" onclick="window.print()">⬇ Скачать PDF / Печать</button>
    <a href="/shifts/grafik/export?dept=<?= (int)$deptId ?>&month=<?= e($month) ?>">Excel</a>
    <?php if (!empty($canArchive) && empty($signed['archived_at'])): ?>
    <form method="post" action="/shifts/grafik/archive" style="display:inline;margin:0">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Auth::csrf()) ?>"><input type="hidden" name="gid" value="<?= (int)$signed['id'] ?>">
        <button onclick="return confirm('Перенести подписанный график в архив?')">🗄 В архив</button>
    </form>
    <?php endif; ?>
    <?php if (!empty($isArchivedRev)): ?>
    <form method="post" action="/shifts/grafik/unarchive" style="display:inline;margin:0">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Auth::csrf()) ?>"><input type="hidden" name="gid" value="<?= (int)$signed['id'] ?>">
        <button>↩ Вернуть из архива</button>
    </form>
    <?php if (!empty($isAdmin)): ?>
    <form method="post" action="/shifts/grafik/delete" style="display:inline;margin:0">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Auth::csrf()) ?>"><input type="hidden" name="gid" value="<?= (int)$signed['id'] ?>">
        <button onclick="return confirm('Удалить ревизию графика БЕЗВОЗВРАТНО? Действие необратимо.')" style="color:#b00;border-color:#e0a0a0">🗑 Удалить</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!empty($isArchivedRev)): ?>
<div class="signbar" style="background:#eef0f4;border:1px solid #c8cdd8;color:#444">🗄 Архивная ревизия графика (только просмотр).</div>
<?php endif; ?>

<?php if (!empty($signed)): ?>
<div class="signbar ok">
    ✔ График подписан ЭП: <strong><?= e($signed['signer_name']) ?></strong>
    · <?= e(substr((string)$signed['signed_at'],0,16)) ?>
    · <?= e(\App\Controllers\TabelController::SIGN_TYPES[$signed['sign_type']] ?? $signed['sign_type']) ?><?= (int)$signed['revision']>0 ? ' · корректировка №'.(int)$signed['revision'] : '' ?>
    <?php if (!empty($stale)): ?><span class="warn">⚠ план изменён после подписи — требуется переподписать (корректировка)</span><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($canSign) && (empty($signed) || !empty($stale))): ?>
<form class="signbar signform" method="post" action="/shifts/grafik/sign">
    <input type="hidden" name="_csrf" value="<?= e(\App\Core\Auth::csrf()) ?>">
    <input type="hidden" name="month" value="<?= e($month) ?>">
    <input type="hidden" name="dept" value="<?= (int)$deptId ?>">
    <span><?= empty($signed) ? '🖋 Подписать график сменности ЭП:' : '🖋 Переподписать (корректировка):' ?></span>
    <select name="sign_type">
        <?php foreach (($signTypes ?? []) as $sv => $sl): $hasCert = $sv === 'PEP' || array_filter($certs ?? [], fn($c) => $c['sign_type'] === $sv); ?>
            <option value="<?= $sv ?>" <?= !$hasCert ? 'disabled' : '' ?>><?= e($sl) ?><?= !$hasCert ? ' — нет сертификата' : '' ?></option>
        <?php endforeach; ?>
    </select>
    <input type="password" name="password" placeholder="Пароль учётной записи" required autocomplete="current-password">
    <button class="primary" onclick="return confirm('Подписать график сменности электронной подписью?')">Подписать</button>
</form>
<?php endif; ?>

<div class="sheet">
    <p class="org"><strong><?= e($orgName) ?></strong></p>
    <p class="sub"><?= e($dept['name'] ?? '—') ?> <span style="color:#777">(наименование структурного подразделения)</span></p>
    <h1>График сменности</h1>
    <p class="meta">Период графика: с 01.<?= sprintf('%02d', $mm) ?>.<?= $yy ?> по <?= $lastDay ?>.<?= sprintf('%02d', $mm) ?>.<?= $yy ?> г. · Дата составления: <?= !empty($signed) ? e(date('d.m.Y', strtotime((string)$signed['signed_at']))) : date('d.m.Y') ?> · Учётный период: 1 (один) год</p>

    <div class="gwrap">
    <table class="g">
        <colgroup>
            <col style="width:26px">
            <col style="width:160px">
            <?php for ($d = 1; $d <= $lastDay; $d++): ?><col style="width:22px"><?php endfor; ?>
            <col style="width:58px">
        </colgroup>
        <thead>
        <tr>
            <th>№</th><th class="name">Должность / Ф.И.О.</th>
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
    </div>

    <p class="legend"><strong>Условные обозначения:</strong> <b>Р</b> — рабочий день (число — часы смены); <b>Р/Н</b> — рабочий день с ночными часами (часы «дневные/ночные», напр. 4/8); <b>О</b> — отпуск; пусто — выходной по графику. В каждой рабочей ячейке указано количество часов.</p>

    <table class="signs">
        <tr>
            <td style="width:50%">СОГЛАСОВАНО:<br><?= e($signApprove) ?> <span class="sig">&nbsp;</span></td>
            <td>График составил:<br>Начальник отдела
                <?php if (!empty($signed)): ?><strong><?= e($signed['signer_name']) ?></strong> <span style="color:#777;font-size:8.5pt">(подписано ЭП)</span><?php else: ?><span class="sig">&nbsp;</span><?php endif; ?></td>
        </tr>
    </table>

    <?php if (!empty($signed)): ?>
    <div class="stamp">
        <b>ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ</b><br>
        Вид подписи: <?= e(\App\Controllers\TabelController::SIGN_TYPES[$signed['sign_type']] ?? $signed['sign_type']) ?><br>
        Сертификат: <?= e($signed['cert_serial']) ?><br>
        Владелец: <?= e($signed['signer_name']) ?><?= $signed['signer_position'] ? ', ' . e($signed['signer_position']) : '' ?><br>
        <?php if (!empty($cert)): ?>Действителен: с <?= e($cert['issued_at']) ?> по <?= e($cert['valid_to']) ?><br><?php endif; ?>
        Подписано: <?= e(substr((string)$signed['signed_at'],0,16)) ?><?= (int)$signed['revision']>0 ? ' · корректировка №'.(int)$signed['revision'] : '' ?><br>
        Отпечаток: <?= e((string)$signed['sign_hash']) ?>
    </div>
    <?php endif; ?>
</div>
</body></html>
