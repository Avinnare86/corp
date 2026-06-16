<?php
// Админ: управление данными — удаление/откат любой записи. Под CSRF, действия пишутся в журнал.
$qs = fn(array $extra = []) => http_build_query(array_merge(['entity' => $entity, 'q' => $q], $extra));
?>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <h1 style="margin:0;font-size:1.2rem">Управление данными</h1>
    <span class="muted">удаление и откат статуса любой записи</span>
</div>

<section class="panel">
    <p class="flash flash-error" style="margin-top:0">⚠ Раздел только для администратора. Действия необратимы (кроме отката) и фиксируются в
        <a href="/audit">журнале</a>. Откат разворачивает последствия автоматически: снимаются визовые вычеты, пересчитывается
        табель, очищаются подписи. Удаление сотрудника с историей ЗП/подписей заменяется деактивацией.</p>
    <form method="get" action="/admin/data" class="xfer-controls" style="flex-wrap:wrap;gap:10px;align-items:flex-end">
        <label>Тип данных
            <select name="entity" onchange="this.form.submit()">
                <?php foreach ($entities as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $entity === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Поиск<input type="text" name="q" value="<?= e($q) ?>" placeholder="№, фамилия, название…"></label>
        <button class="btn">Найти</button>
        <?php if ($q !== ''): ?><a class="btn btn-mini" href="/admin/data?<?= e($qs(['q' => ''])) ?>">Сброс</a><?php endif; ?>
    </form>
</section>

<section class="panel">
    <h2 style="margin-top:0"><?= e($entities[$entity]) ?> <span class="muted">(<?= count($rows) ?><?= count($rows) >= 200 ? '+, уточните поиск' : '' ?>)</span></h2>
    <table class="table">
        <thead><tr><th>Запись</th><th>Описание</th><th>Статус</th><th style="width:260px">Действия</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['title']) ?></td>
                <td class="muted"><?= e($r['sub']) ?></td>
                <td><span class="tag off"><?= e($r['status']) ?></span></td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                        <?php if ($r['can_revert']): ?>
                            <form method="post" action="/admin/data/<?= e($entity) ?>/<?= (int)$r['id'] ?>/revert"
                                  onsubmit="return confirm('Откатить на шаг назад?\n<?= e($r['revert_hint']) ?>')" style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="q" value="<?= e($q) ?>">
                                <button class="btn btn-mini" title="<?= e($r['revert_hint']) ?>">↩ Откатить</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($r['can_delete']): ?>
                            <form method="post" action="/admin/data/<?= e($entity) ?>/<?= (int)$r['id'] ?>/delete"
                                  onsubmit="return confirm('УДАЛИТЬ запись безвозвратно?\n<?= e($r['title']) ?>\nЗависимые данные будут сняты/пересчитаны.')" style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="q" value="<?= e($q) ?>">
                                <button class="btn btn-mini" style="color:#c0392b">✕ Удалить</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Записей нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
