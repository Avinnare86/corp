<?php
/** Журнал ревизий графиков сменности 2/2: все ревизии (первичная → корректировочные) по доступным отделам,
 *  с пометкой Актуальная / Заменена / Архив и кем перенесено в архив. */
$signLabel = fn($t) => $signTypes[$t] ?? $t;
$latest = $latest ?? [];
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h1 style="margin:0">🗂 Ревизии графиков 2/2</h1>
    <a class="btn" href="/shifts">← К сменному графику</a>
</div>

<section class="panel">
    <p class="muted">Все подписанные ревизии графиков — от первичной до последней корректировочной. <b>Актуальная</b> — действующая (последняя неархивная) ревизия отдела за месяц; <b>Заменена</b> — перекрыта более поздней корректировкой; <b>Архив</b> — перенесена в архив вручную. Безвозвратно удалить может только администратор.</p>
    <table class="table">
        <thead><tr><th>Отдел</th><th>Период</th><th>Ревизия</th><th>Статус</th><th>Подписал</th><th>Дата подписи</th><th>В архив (кем)</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $g):
            $archived = $g['archived_at'] !== null;
            $k = $g['department_id'] . '|' . $g['period'];
            $isActual = !$archived && (int) $g['revision'] === ($latest[$k] ?? -1);
        ?>
            <tr>
                <td><strong><?= e($g['dept_name'] ?: '—') ?></strong></td>
                <td><?= e($g['period']) ?></td>
                <td><?= (int)$g['revision'] === 0 ? 'первичная' : 'корр. №' . (int)$g['revision'] ?></td>
                <td>
                    <?php if ($archived): ?><span class="st st-rev">🗄 Архив</span>
                    <?php elseif ($isActual): ?><span class="st st-ok">✔ Актуальная</span>
                    <?php else: ?><span class="st st-wait">Заменена<?= isset($latest[$k]) ? ' (корр. №' . (int)$latest[$k] . ')' : '' ?></span><?php endif; ?>
                </td>
                <td class="muted"><?= e($g['signer_name'] ?: ($g['signer'] ?? '—')) ?> · <?= e($signLabel($g['sign_type'])) ?></td>
                <td class="muted"><?= e(substr((string)$g['signed_at'],0,16)) ?></td>
                <td class="muted"><?php if ($archived): ?><?= e($g['archiver'] ?? '') ?><br><span style="font-size:.72rem"><?= e(substr((string)$g['archived_at'],0,16)) ?></span><?php else: ?>—<?php endif; ?></td>
                <td>
                    <a class="btn btn-mini btn-primary" href="/shifts/grafik?gid=<?= (int)$g['id'] ?>" target="_blank">📄 Открыть</a>
                    <?php if ($archived): ?>
                        <form method="post" action="/shifts/grafik/unarchive" class="inline" onsubmit="return confirm('Вернуть ревизию из архива?')">
                            <?= csrf_field() ?><input type="hidden" name="gid" value="<?= (int)$g['id'] ?>"><button class="btn btn-mini">↩ Вернуть</button></form>
                        <?php if ($isAdmin): ?>
                        <form method="post" action="/shifts/grafik/delete" class="inline" onsubmit="return confirm('Удалить ревизию графика БЕЗВОЗВРАТНО? Действие необратимо.')">
                            <?= csrf_field() ?><input type="hidden" name="gid" value="<?= (int)$g['id'] ?>"><button class="btn btn-mini btn-danger">🗑 Удалить</button></form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="/shifts/grafik/archive" class="inline" onsubmit="return confirm('Перенести ревизию графика в архив?')">
                            <?= csrf_field() ?><input type="hidden" name="gid" value="<?= (int)$g['id'] ?>"><button class="btn btn-mini">🗄 В архив</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted">Подписанных графиков пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
