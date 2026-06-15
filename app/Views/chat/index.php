<h1>Чат</h1>

<div class="cards" style="grid-template-columns:1fr">
<section class="panel">
    <h2>Беседы</h2>
    <?php if (!$convs): ?><p class="muted">Бесед пока нет — начните личный чат ниже.</p><?php endif; ?>
    <div class="chat-list">
        <?php foreach ($convs as $c): ?>
            <a class="chat-row<?= $c['unread'] ? ' unread' : '' ?>" href="/chat/<?= (int) $c['id'] ?>">
                <span class="chat-ava"><?= $c['type'] === 'group' ? '👥' : '👤' ?></span>
                <span class="chat-main">
                    <span class="chat-name"><?= e($c['display']) ?><?= $c['type']==='group' ? ' <span class="tag">группа</span>' : '' ?></span>
                    <span class="muted chat-last"><?= e(mb_strimwidth((string)$c['last_body'], 0, 60, '…')) ?></span>
                </span>
                <?php if ($c['unread']): ?><span class="badge">●</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
</div>

<section class="panel">
    <h2>Новый личный чат</h2>
    <form method="post" action="/chat/direct" class="form-inline">
        <?= csrf_field() ?>
        <label>Собеседник
            <select name="user_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Открыть чат</button>
    </form>
</section>

<?php if ($isAdmin): ?>
<section class="panel">
    <h2>Создать групповой чат</h2>
    <form method="post" action="/chat/group">
        <?= csrf_field() ?>
        <label>Название<input type="text" name="title" required></label>
        <label>Участники</label>
        <div class="members-grid">
            <?php foreach ($users as $u): ?>
                <label class="chk"><input type="checkbox" name="members[]" value="<?= (int) $u['id'] ?>"> <?= e($u['full_name']) ?></label>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" style="margin-top:12px">Создать группу</button>
    </form>
</section>
<?php endif; ?>
