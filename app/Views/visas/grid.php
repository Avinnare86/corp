<style>/* широкий режим: таблица занимает весь экран, без «окна с ползунком» */
main.container{max-width:none;width:auto;margin:0 18px}</style>
<h1>Проверка виз</h1>

<div class="cards">
    <div class="card"><div class="card-label">Осталось проверить</div><div class="card-value big"><?= (int)$remaining ?></div></div>
    <div class="card"><div class="card-label">Проверено сегодня</div><div class="card-value big"><?= (int)$doneToday ?></div></div>
    <div class="card"><div class="card-label">Журнал</div><div class="card-value"><a href="/visas/done">Отработанное по датам →</a></div></div>
</div>

<?php if (!$rows): ?>
<section class="panel"><p class="muted">Нет назначенных анкет. Ожидайте распределения от менеджера по визам.</p></section>
<?php else: ?>
<section class="panel" style="padding:10px">
    <p class="muted" style="margin:4px 6px 10px">Редактируйте прямо в ячейках (как в Excel). Отметьте галочкой
        «Проверено» те анкеты, что готовы — кнопка <b>«Сохранить и отметить»</b> финализирует только отмеченные
        (они засчитываются в сделку как «Виза — этап 2», повторная проверка после доработки зачёт не удваивает),
        остальные сохранятся как черновик и останутся в очереди. Кнопка <b>«Сохранить черновик»</b> просто
        записывает введённое, ничего не отмечая проверенным.</p>
    <?php foreach ($rows as $r): if (!empty($r['rework_note']) && empty($r['checked_at']) && (int)$r['rework_count'] > 0): ?>
        <div class="flash flash-error" style="margin:0 6px 8px">⚠ Анкета <strong><?= e($r['out_no'] ?: '#'.$r['id']) ?></strong>
            возвращена на доработку: <?= e($r['rework_note']) ?></div>
    <?php endif; endforeach; ?>
    <form method="post" action="/visas/save">
        <?= csrf_field() ?>
        <?php // ширины колонок подобраны так, чтобы все 18 полей умещались на FullHD без прокрутки
        $w = ['out_no'=>76,'out_date'=>68,'surname_ru'=>100,'names_ru'=>100,'surname_lat'=>100,'names_lat'=>100,
              'citizenship'=>90,'residence'=>90,'birth_date'=>68,'birth_place'=>92,'sex'=>40,'passport_no'=>92,
              'issue_date'=>68,'expiry_date'=>68,'work_address'=>170,'visit_places'=>100,'visa_place'=>110,'ai_address'=>180]; ?>
        <div style="overflow-x:auto">
        <table class="vgrid" style="width:100%">
            <thead><tr>
                <th title="отметить все"><input type="checkbox" id="vAll"> ✓</th>
                <th>#</th>
                <?php foreach ($fields as $f => $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                <th>Действие</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $id=(int)$r['id'];
                $isRework = (int)$r['rework_count'] > 0; ?>
                <tr<?= $isRework ? ' class="vrw"' : '' ?>>
                    <td style="text-align:center"><input type="checkbox" class="vdone" name="done[<?= $id ?>]" value="1"></td>
                    <td class="muted" style="padding:4px 6px;white-space:nowrap"><?= $i+1 ?><?= $isRework ? ' <span title="'.e($r['rework_note'] ?? 'доработка').'">⚠</span>' : '' ?>
                        <a href="/visas/row/<?= $id ?>" title="Открыть анкету отдельно" style="text-decoration:none">↗</a></td>
                    <?php foreach ($fields as $f => $label): $expCls = $f==='expiry_date' ? \App\Controllers\VisaController::expiryClass($r['expiry_date'] ?? '') : ''; ?>
                        <td<?= $expCls ? ' class="'.e($expCls).'" title="срок действия паспорта истекает менее чем через 1 год '.($expCls==='exp-bad'?'6':'7').' мес."' : '' ?>><textarea name="row[<?= $id ?>][<?= $f ?>]" rows="1"
                            class="vcell<?= $f==='ai_address' ? ' vai' : '' ?>" style="min-width:<?= (int)($w[$f] ?? 100) ?>px"><?= e($r[$f] ?? '') ?></textarea></td>
                    <?php endforeach; ?>
                    <td><button type="submit" class="btn btn-mini" formaction="/visas/row/<?= $id ?>/passport-rework" formmethod="post"
                        onclick="return confirm('Отправить анкету на доработку — срок действия паспорта? Строка уйдёт в пул доработки.')"
                        title="Доработка: срок действия паспорта">⏳ Паспорт</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="form-inline" style="margin-top:12px;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" name="mode" value="finalize"
                onclick="return document.querySelectorAll('.vdone:checked').length>0 || confirm('Ни одна анкета не отмечена галочкой «Проверено». Сохранить как черновик (без отметки)?')">
                ✓ Сохранить и отметить проверенными</button>
            <button class="btn" name="mode" value="draft">💾 Сохранить черновик</button>
            <span class="muted">в очереди всего: <?= (int)$remaining ?></span>
        </div>
    </form>
</section>
<script>
// автоподстройка высоты ячеек под текст
document.querySelectorAll('.vcell').forEach(function(t){
  function fit(){ t.style.height='auto'; t.style.height=Math.max(28,t.scrollHeight)+'px'; }
  t.addEventListener('input',fit); fit();
});
// «отметить все» — переключает все галочки «Проверено»
var vAll=document.getElementById('vAll');
if(vAll){ vAll.addEventListener('change',function(){ document.querySelectorAll('.vdone').forEach(function(c){ c.checked=vAll.checked; }); }); }
</script>
<?php endif; ?>
