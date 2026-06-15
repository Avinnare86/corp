<div class="chat-head" style="display:flex;gap:10px;align-items:center">
    <h1 style="margin:0;font-size:1.2rem">Описи и визовые указания</h1>
    <a class="btn btn-mini btn-primary" href="/visas/opis">+ Сформировать опись</a>
    <a class="btn btn-mini" href="/visas/rework">МИД: на доработке →</a>
</div>

<section class="panel">
    <?php if (!$list): ?>
        <p class="muted" style="margin:0">Описи ещё не формировались. <a href="/visas/opis">Сформировать первую →</a></p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>№</th><th>Страна</th><th class="num">Человек</th><th>Подписант</th><th>Статус</th><th>Визовое указание</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $o): ?>
            <tr>
                <td><?= (int)$o['id'] ?></td>
                <td><?= e($o['country']) ?></td>
                <td class="num"><strong><?= (int)$o['people'] ?></strong></td>
                <td class="muted"><?= e($o['signer_name']) ?></td>
                <td>
                    <?php if ($o['status'] === 'instructed'): ?>
                        <span class="tag" style="background:#27ae60;color:#fff">указание получено</span>
                    <?php else: ?>
                        <span class="tag off">сформирована</span>
                    <?php endif; ?>
                </td>
                <td><?php if ($o['instruction_no'] !== '' || $o['instruction_date'] !== ''): ?>
                        № <?= e($o['instruction_no']) ?> от <?= e($o['instruction_date']) ?>
                    <?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><a class="btn btn-mini" href="/visas/opis/<?= (int)$o['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
