<?php
$tierName = [1 => 'Простые', 2 => 'Остальные', 3 => 'Сложные'];
$priceByGroup = [];
foreach ($groups as $g) { $priceByGroup[(int)$g['group_no']] = (float)$g['price']; }
$byGroup = [1=>[],2=>[],3=>[]];
foreach ($countries as $c) { $g=(int)$c['group_no']; if(isset($byGroup[$g])) $byGroup[$g][]=$c; }
?>
<h1>Справочник стран</h1>

<section class="panel">
    <h2>Добавить / изменить страну</h2>
    <form method="post" action="/admin/countries" class="grid-form">
        <?= csrf_field() ?>
        <label>Код ISO<input type="text" name="code" placeholder="FRA" style="text-transform:uppercase" required></label>
        <label>Название<input type="text" name="name" placeholder="Франция" required></label>
        <label>Тариф
            <select name="group_no">
                <?php foreach ([1,2,3] as $g): if(!isset($priceByGroup[$g])) continue; ?>
                    <option value="<?= $g ?>"><?= e($tierName[$g]) ?> — <?= money($priceByGroup[$g]) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Сохранить</button>
    </form>
    <div class="form-inline" style="margin-top:10px">
        <label>Поиск<input type="text" id="cSearch" placeholder="код или название…" onkeyup="filterCountries()"></label>
        <span class="muted">Не указанные в справочнике страны тарифицируются как «Остальные» (<?= money($priceByGroup[2] ?? 70) ?>). Отметьте страны галочками и перенесите кнопками внизу столбца.</span>
    </div>
</section>

<div class="board3">
<?php foreach ([1,2,3] as $g): $price=$priceByGroup[$g] ?? 0; ?>
    <section class="panel">
        <div class="emp-head" style="margin-bottom:10px">
            <strong><?= e($tierName[$g]) ?> — <?= money($price) ?></strong>
            <span class="muted"><?= count($byGroup[$g]) ?> стран</span>
        </div>
        <div class="xfer-mini" style="margin-bottom:8px">
            <button type="button" class="btn btn-mini" onclick="allCol(<?= $g ?>,true)">все</button>
            <button type="button" class="btn btn-mini" onclick="allCol(<?= $g ?>,false)">снять</button>
        </div>
        <form method="post" action="/admin/countries/move">
            <?= csrf_field() ?>
            <div class="xfer-list" id="col<?= $g ?>" style="height:320px">
                <?php foreach ($byGroup[$g] as $c): ?>
                    <label class="xfer-item" data-s="<?= e(mb_strtolower($c['code'].' '.$c['name'])) ?>">
                        <input type="checkbox" name="codes[]" value="<?= e($c['code']) ?>">
                        <span class="mono"><?= e($c['code']) ?></span> <?= e($c['name']) ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$byGroup[$g]): ?><div class="muted" style="padding:10px">Пусто.</div><?php endif; ?>
            </div>
            <div class="xfer-mini" style="margin-top:8px">
                <span class="muted">Перенести в:</span>
                <?php foreach ([1,2,3] as $t): if($t===$g) continue; ?>
                    <button type="submit" name="group" value="<?= $t ?>" class="btn btn-mini btn-primary"><?= e($tierName[$t]) ?></button>
                <?php endforeach; ?>
            </div>
        </form>
    </section>
<?php endforeach; ?>
</div>

<script>
function filterCountries(){ var q=document.getElementById('cSearch').value.toLowerCase();
  document.querySelectorAll('.xfer-item').forEach(function(el){ el.style.display=(el.dataset.s||'').indexOf(q)>=0?'':'none'; }); }
function allCol(g,v){ document.querySelectorAll('#col'+g+' input[type=checkbox]').forEach(function(c){ c.checked=v; }); }
</script>
