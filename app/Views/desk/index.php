<h1>Рабочий стол</h1>
<p class="muted">Задачи, требующие вашего действия, собраны из всех модулей.</p>

<?php if (!$buckets): ?>
    <section class="panel"><p class="muted">✅ Нет задач, требующих вашего участия. Хорошего дня!</p></section>
<?php else: ?>
<div class="desk-grid">
    <?php foreach ($buckets as $b): ?>
        <section class="panel desk-card">
            <div class="desk-head">
                <a href="<?= e($b['link']) ?>"><strong><?= e($b['label']) ?></strong></a>
                <span class="badge"><?= (int)$b['count'] ?></span>
            </div>
            <?php if ($b['items']): ?>
                <ul class="desk-list">
                    <?php foreach ($b['items'] as [$href, $text]): ?>
                        <li><a href="<?= e($href) ?>"><?= e(mb_strimwidth($text, 0, 64, '…')) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ((int)$b['count'] > count($b['items'])): ?><a class="muted" href="<?= e($b['link']) ?>">…ещё <?= (int)$b['count'] - count($b['items']) ?></a><?php endif; ?>
            <?php else: ?>
                <a class="btn btn-mini" href="<?= e($b['link']) ?>">Открыть</a>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
