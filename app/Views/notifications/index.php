<h1>Уведомления</h1>

<section class="panel">
    <?php if (!$items): ?>
        <p class="muted">Уведомлений пока нет.</p>
    <?php endif; ?>

    <?php foreach ($items as $n): ?>
        <article class="note <?= (int) $n['is_read'] ? 'read' : 'unread' ?>">
            <div class="note-head">
                <strong><?= e($n['title']) ?></strong>
                <span class="muted"><?= e($n['created_at']) ?></span>
            </div>
            <div class="note-body"><?= nl2br(e($n['body'])) ?></div>
            <?php if (!(int) $n['is_read']): ?>
                <form method="post" action="/notifications/<?= (int) $n['id'] ?>/read">
                    <?= csrf_field() ?>
                    <button class="btn btn-mini">Отметить прочитанным</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
