<?php use App\Controllers\NomenclatureController; $st = NomenclatureController::STATUS; ?>
<div class="chat-head">
    <a class="btn btn-mini" href="/nomenclature">← Номенклатура</a>
    <h1 style="margin:0;font-size:1.2rem">Архив дел</h1>
</div>

<section class="panel">
    <p class="muted">Закрытые и архивные дела. «Хранение истекло» — год возможного уничтожения уже наступил (по сроку хранения дела).</p>
    <table class="table">
        <thead><tr><th>Год</th><th>Индекс</th><th>Заголовок</th><th>Срок хранения</th><th class="num">Док-в</th><th>Статус</th><th>Уничтожение после</th></tr></thead>
        <tbody>
        <?php foreach ($cases as $c): $expired = $c['destroy_after'] && (int)$c['destroy_after'] < $thisYear; ?>
            <tr<?= $expired ? ' style="background:#fff5f5"' : '' ?>>
                <td><?= (int)$c['year'] ?></td>
                <td class="mono"><a href="/nomenclature/<?= (int)$c['id'] ?>"><?= e($c['index_code']) ?></a></td>
                <td><?= e($c['title']) ?></td>
                <td><?= e($c['storage_term']) ?></td>
                <td class="num"><?= (int)$c['docs'] ?></td>
                <td><span class="st st-wait"><?= e($st[$c['status']] ?? $c['status']) ?></span></td>
                <td><?= $c['destroy_after'] ? (int)$c['destroy_after'] . ' г.' . ($expired ? ' <span class="minus">— истёк</span>' : '') : '<span class="muted">постоянно</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$cases): ?><tr><td colspan="7" class="muted">Архив пуст.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
