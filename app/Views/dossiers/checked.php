<h1>Проверка досье</h1>

<div class="tabs">
    <a class="tab" href="/dossiers">К проверке<?= $pendingTotal ? ' (' . (int)$pendingTotal . ')' : '' ?></a>
    <a class="tab active" href="/dossiers/checked">Проверенные (<?= (int)$checkedTotal ?>)</a>
</div>

<section class="panel">
    <p class="muted" style="margin-bottom:12px">Статус: «Без замечаний» либо список причин-доработок. Можно изменить или вернуть в работу.</p>
    <?php if (!$items): ?><p class="muted">Проверенных досье пока нет.</p><?php endif; ?>
    <table class="table">
        <thead><tr><th>Рег. номер</th><th>Страна</th><th>План приема</th><th>Проверено</th><th>Статус / причины</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $id=(int)$it['id']; $reasons=trim((string)$it['comment_text']); ?>
            <tr data-id="<?= $id ?>" data-reg="<?= e($it['reg_number']) ?>" data-sel="<?= e(implode(',', $selected[$id] ?? [])) ?>">
                <td class="mono"><strong><?= e($it['reg_number']) ?></strong></td>
                <td><?= e($it['country_name'] ?? $it['country_code']) ?></td>
                <?php $al = arrival_label($it['arrival_code'] ?? null, $it['arrival_detail'] ?? null); ?>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($al) ?>"><?= $al !== '' ? e($al) : '<span class="muted">—</span>' ?></td>
                <td class="muted"><?= e(substr((string)$it['checked_at'],0,16)) ?></td>
                <td class="cmt"><?= $reasons!=='' ? e($reasons) : '<span class="tag ok">Без замечаний</span>' ?></td>
                <td>
                    <button class="btn btn-mini btn-primary" onclick="editItem(<?= $id ?>)">✎ Изменить</button>
                    <form method="post" action="/dossiers/<?= $id ?>/uncheck" class="inline" onsubmit="return confirm('Вернуть досье в работу?')">
                        <?= csrf_field() ?><button class="btn btn-mini">↩ В работу</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php include __DIR__ . '/../partials/picker.php'; ?>
<div id="toast" class="toast"></div>

<script>
(function(){
  var CSRF=<?= json_encode(\App\Core\Auth::csrf()) ?>;
  function toast(m){ var t=document.getElementById('toast'); t.textContent=m; t.classList.add('show'); setTimeout(function(){t.classList.remove('show');},2200); }
  window.editItem=function(id){
    var tr=document.querySelector('tr[data-id="'+id+'"]');
    var pre=(tr.dataset.sel||'').split(',').filter(Boolean).map(Number);
    openPicker({type:'edit',id:id}, pre);
  };
  window.applyComments=function(target, ids){
    if(!target || target.type!=='edit') return;
    var b='_csrf='+encodeURIComponent(CSRF)+ids.map(function(i){return '&comment_id[]='+i;}).join('');
    fetch('/dossiers/'+target.id+'/recomment',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body:b})
      .then(function(r){return r.json();}).then(function(d){ if(!d.ok)return;
        var tr=document.querySelector('tr[data-id="'+target.id+'"]');
        tr.dataset.sel=ids.join(',');
        tr.querySelector('.cmt').innerHTML = d.status==='ok' ? '<span class="tag ok">Без замечаний</span>' : d.reasons;
        toast('Обновлено'); });
  };
})();
</script>
