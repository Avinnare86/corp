<style>main.container{max-width:none;width:auto;margin:0 18px}</style>
<h1>Строки партии «<?= e($batch['name']) ?>»</h1>

<section class="panel">
    <form method="get" action="/visas/batch/<?= (int)$batch['id'] ?>/rows" class="form-inline" style="align-items:flex-end">
        <label>Статус
            <select name="status">
                <option value="">все</option>
                <option value="unchecked" <?= $status==='unchecked'?'selected':'' ?>>не проверены</option>
                <option value="checked" <?= $status==='checked'?'selected':'' ?>>проверены</option>
                <option value="rework" <?= $status==='rework'?'selected':'' ?>>на доработке</option>
            </select>
        </label>
        <label>Поиск (№ / фамилия / паспорт)<input type="text" name="q" value="<?= e($q) ?>"></label>
        <button class="btn">Показать</button>
        <a class="btn" href="/visas/manage">← К партиям</a>
    </form>

    <div class="form-inline" style="margin:10px 0;align-items:center;flex-wrap:wrap">
        <strong id="selInfo">Выбрано: 0</strong>
        <button type="button" class="btn btn-mini" onclick="pageSel(true)">выбрать страницу</button>
        <button type="button" class="btn btn-mini" onclick="pageSel(false)">снять страницу</button>
        <button type="button" class="btn btn-mini" onclick="clearSel()">очистить выбор</button>
        <span class="muted">выбор сохраняется при переходе по страницам</span>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-mini btn-gold" onclick="exportSel('zip', false)">⬇ DOCX выбранных</button>
        <button type="button" class="btn btn-mini btn-primary" onclick="exportSel('pdf', false)">⬇ PDF выбранных</button>
        <button type="button" class="btn btn-mini btn-gold" onclick="exportSel('zip', true)">⬇ DOCX всех по фильтру (<?= (int)$total ?>)</button>
        <button type="button" class="btn btn-mini btn-primary" onclick="exportSel('pdf', true)">⬇ PDF всех по фильтру</button>
    </div>

    <table class="table">
        <thead><tr>
            <th style="width:30px"><input type="checkbox" onclick="pageSel(this.checked)"></th>
            <th>№</th><th>Фамилия</th><th>Гражданство</th><th>Паспорт</th><th>Специалист</th><th>Статус</th><th>Доработки</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><input type="checkbox" class="rowck" value="<?= (int)$r['id'] ?>"></td>
                <td class="mono"><?= e($r['out_no'] ?: '#'.$r['id']) ?></td>
                <td><?= e($r['surname_lat']) ?> <?= e($r['names_lat']) ?><br><span class="muted" style="font-size:.76rem"><?= e($r['surname_ru']) ?></span></td>
                <td><?= e($r['citizenship']) ?></td>
                <td class="mono"><?= e($r['passport_no']) ?></td>
                <td><?= e($r['spec_name'] ?: '—') ?></td>
                <td><?= $r['checked_at'] ? '<span class="plus">✓ ' . e(substr((string)$r['checked_at'],0,16)) . '</span>' : '<span class="muted">в работе</span>' ?></td>
                <td><?= (int)$r['rework_count'] ? '⚠ ' . (int)$r['rework_count'] : '' ?></td>
                <td><a class="btn btn-mini" href="/visas/row/<?= (int)$r['id'] ?>">✎</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="muted">Ничего не найдено.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="form-inline" style="margin-top:10px">
        <?php $qs = '&status=' . urlencode($status) . '&q=' . urlencode($q);
        for ($p = 1; $p <= $pages; $p++): ?>
            <a class="btn btn-mini <?= $p === $page ? 'btn-primary' : '' ?>" href="/visas/batch/<?= (int)$batch['id'] ?>/rows?page=<?= $p . $qs ?>"><?= $p ?></a>
        <?php endfor; ?>
        <span class="muted">всего строк: <?= (int)$total ?></span>
    </div>
    <?php endif; ?>
</section>

<form id="expForm" method="post" action="/visas/export" target="_blank" style="display:none">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="fmt" id="expFmt" value="zip">
    <input type="hidden" name="all" id="expAll" value="">
    <input type="hidden" name="ids" id="expIds" value="">
</form>

<script>
// выбор строк живёт в sessionStorage — переживает переходы между страницами реестра
var KEY = 'visaSel<?= (int)$batch['id'] ?>';
function getSel(){ try { return new Set(JSON.parse(sessionStorage.getItem(KEY) || '[]')); } catch(e){ return new Set(); } }
function putSel(s){ sessionStorage.setItem(KEY, JSON.stringify([...s])); document.getElementById('selInfo').textContent = 'Выбрано: ' + s.size + ' (на всех страницах)'; }
function syncBoxes(){ var s = getSel(); document.querySelectorAll('.rowck').forEach(function(c){ c.checked = s.has(c.value); }); putSel(s); }
document.querySelectorAll('.rowck').forEach(function(c){
    c.addEventListener('change', function(){ var s = getSel(); c.checked ? s.add(c.value) : s.delete(c.value); putSel(s); });
});
function pageSel(v){ var s = getSel(); document.querySelectorAll('.rowck').forEach(function(c){ c.checked = v; v ? s.add(c.value) : s.delete(c.value); }); putSel(s); }
function clearSel(){ sessionStorage.removeItem(KEY); syncBoxes(); }
function exportSel(fmt, all){
    var s = getSel();
    if (!all && !s.size) { alert('Ничего не выбрано. Отметьте строки галочками (выбор работает на всех страницах) или используйте «все по фильтру».'); return; }
    document.getElementById('expFmt').value = fmt;
    document.getElementById('expAll').value = all ? '1' : '';
    document.getElementById('expIds').value = all ? '' : [...s].join(',');
    document.getElementById('expForm').submit();
}
syncBoxes();
</script>
