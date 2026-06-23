<?php
/** Архив графиков сменности 2/2: архивные ревизии по доступным отделам. Открыть снимок, вернуть, удалить (админ). */
$signLabel = fn($t) => $signTypes[$t] ?? $t;
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h1 style="margin:0">🗄 Архив графиков 2/2</h1>
    <a class="btn" href="/shifts">← К сменному графику</a>
</div>

<section class="panel">
    <p class="muted">Архивные (перенесённые в архив) подписанные ревизии графиков сменности. «Открыть» — замороженный снимок с ЭП. Удалить безвозвратно может только администратор.</p>
    <table class="table">
        <thead><tr><th>Отдел</th><th>Период</th><th>Ревизия</th><th>Подписал</th><th>Дата подписи</th><th>Архивировал</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $g): ?>
            <tr>
                <td><strong><?= e($g['dept_name'] ?: '—') ?></strong></td>
                <td><?= e($g['period']) ?></td>
                <td><?= (int)$g['revision'] === 0 ? 'первичный' : 'корр. №' . (int)$g['revision'] ?></td>
                <td class="muted"><?= e($g['signer_name'] ?: '—') ?> · ЭП: <?= e($signLabel($g['sign_type'])) ?></td>
                <td class="muted"><?= e(substr((string)$g['signed_at'],0,16)) ?></td>
                <td class="muted"><?= e($g['archiver'] ?? '') ?><br><span style="font-size:.72rem"><?= e(substr((string)$g['archived_at'],0,16)) ?></span></td>
                <td>
                    <a class="btn btn-mini btn-primary" href="/shifts/grafik?gid=<?= (int)$g['id'] ?>" target="_blank">📄 Открыть</a>
                    <form method="post" action="/shifts/grafik/unarchive" class="inline" onsubmit="return confirm('Вернуть график из архива?')">
                        <?= csrf_field() ?><input type="hidden" name="gid" value="<?= (int)$g['id'] ?>"><button class="btn btn-mini">↩ Вернуть</button></form>
                    <?php if ($isAdmin): ?>
                    <form method="post" action="/shifts/grafik/delete" class="inline" onsubmit="return confirm('Удалить ревизию графика БЕЗВОЗВРАТНО? Действие необратимо.')">
                        <?= csrf_field() ?><input type="hidden" name="gid" value="<?= (int)$g['id'] ?>"><button class="btn btn-mini btn-danger">🗑 Удалить</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Архив графиков пуст.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
