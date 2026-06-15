<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>Реестр передачи</title>
<style>
  body{font-family:'Times New Roman',serif;font-size:11pt;color:#000;background:#888;margin:0;padding:20px}
  .toolbar{font-family:Arial;background:#f0f2f8;border-radius:8px;padding:10px 14px;margin:0 auto 14px;max-width:900px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .toolbar a,.toolbar button,.toolbar input,.toolbar select{font-family:Arial;padding:7px 12px;border-radius:6px;border:1px solid #99a;background:#fff;text-decoration:none;color:#223}
  .toolbar .primary{background:#26368B;color:#fff;border-color:#26368B;cursor:pointer}
  .sheet{background:#fff;max-width:900px;margin:0 auto;padding:26px 30px;box-shadow:0 4px 18px rgba(0,0,0,.35)}
  h2{text-align:center;font-size:13pt;margin:0 0 4px}
  .sub{text-align:center;margin:0 0 16px}
  table{border-collapse:collapse;width:100%;font-size:10.5pt}
  th,td{border:1px solid #000;padding:5px 7px;vertical-align:top}
  th{background:#f3f3f3}
  @media print{ body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none} }
</style></head>
<body>
<div class="toolbar">
    <a href="/docs">← К документам</a>
    <form method="get" action="/docs/register" style="display:flex;gap:8px;align-items:center;margin:0">
        <select name="direction">
            <option value="outgoing" <?= $direction==='outgoing'?'selected':'' ?>>исходящие</option>
            <option value="incoming" <?= $direction==='incoming'?'selected':'' ?>>входящие</option>
            <option value="internal" <?= $direction==='internal'?'selected':'' ?>>внутренние</option>
        </select>
        с <input type="date" name="from" value="<?= e($from) ?>">
        по <input type="date" name="to" value="<?= e($to) ?>">
        <button class="primary">Сформировать</button>
    </form>
    <button class="primary" onclick="window.print()">⬇ Печать</button>
</div>
<div class="sheet">
    <h2>Реестр передачи <?= e($dirLabel) ?> документов</h2>
    <p class="sub">за период <?= e($from) ?> — <?= e($to) ?></p>
    <table>
        <tr><th style="width:36px">№</th><th>Рег. №</th><th>Дата</th><th>Тип / заголовок</th><th><?= $direction==='incoming'?'От кого':'Кому' ?></th><th style="width:130px">Получил (подпись)</th></tr>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td class="mono"><?= e($r['reg_number'] ?: '—') ?></td>
                <td><?= e(substr((string)$r['created_at'],0,10)) ?></td>
                <td><?= e($r['type_name']) ?><br><?= e($r['title']) ?></td>
                <td><?= e($r['correspondent_name'] ?: '') ?></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:#777">Нет документов за период.</td></tr><?php endif; ?>
    </table>
    <p style="margin-top:24px">Передал: ______________________ /________________/ &nbsp;&nbsp;&nbsp; Дата: «____» __________ 20___ г.</p>
</div>
</body></html>
