<?php
/** @var array $dash  из DashboardService::forUser() */
$wd = $dash['workday'];
$cards = $dash['cards'];
$tasks = $dash['tasks'];
$chat = $dash['chat'];
$notifs = $dash['notifs'];
$fio = trim((string) ($user['full_name'] ?? ''));
$firstName = explode(' ', $fio)[1] ?? $fio; // «Фамилия Имя …» → Имя
?>
<div class="dash-hello">
    <h1 style="margin:0">Здравствуйте<?= $firstName ? ', ' . e($firstName) : '' ?>!</h1>
    <?php if ($dash['showPayslip']): ?>
        <a class="dash-payslip" href="/payroll">🧾 Расчётный листок →</a>
    <?php endif; ?>
</div>

<?php if ($dash['tracksAttendance']): ?>
<!-- Крупный запуск рабочего дня -->
<section class="panel attendance workday-hero">
    <div>
        <h2 style="margin:0">Рабочий день</h2>
        <?php if (!$wd || empty($wd['opened_at'])): ?>
            <p class="muted" style="margin:4px 0 0">День ещё не открыт. Нажмите «Приступить к работе» — это засчитает явку в табель и откроет ввод работы.</p>
        <?php elseif (empty($wd['closed_at'])): ?>
            <p class="muted" style="margin:4px 0 0">Вы работаете с <?= e(substr($wd['opened_at'], 11, 5)) ?>. В конце смены нажмите «Завершить работу».</p>
        <?php else: ?>
            <p class="muted" style="margin:4px 0 0">Работа завершена: <?= e(substr($wd['opened_at'],11,5)) ?>–<?= e(substr($wd['closed_at'],11,5)) ?>. Явка засчитана.</p>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!$wd || empty($wd['opened_at'])): ?>
            <form method="post" action="/day/open"><?= csrf_field() ?><button class="btn btn-primary btn-big">▶ Приступить к работе</button></form>
        <?php elseif (empty($wd['closed_at'])): ?>
            <form method="post" action="/day/close" onsubmit="return confirm('Завершить работу? Ввод работы будет закрыт.')">
                <?= csrf_field() ?><button class="btn btn-gold btn-big">⏹ Завершить работу</button></form>
        <?php else: ?>
            <form method="post" action="/day/open"><?= csrf_field() ?><button class="btn btn-mini">↻ Возобновить работу</button></form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Сводка: сообщения и уведомления -->
<div class="dash-comm">
    <section class="panel dash-mini">
        <div class="dash-mini-head">
            <h2>Сообщения <?php if ($chat['unread']): ?><span class="badge"><?= (int)$chat['unread'] ?></span><?php endif; ?></h2>
            <a class="btn btn-mini" href="/chat">Открыть чат</a>
        </div>
        <?php if ($chat['recent']): ?>
            <ul class="dash-list">
                <?php foreach ($chat['recent'] as $c): ?>
                    <li>
                        <a href="/chat/<?= (int)$c['id'] ?>">
                            <span class="dash-list-title<?= $c['unread'] ? ' is-unread' : '' ?>"><?= e($c['title']) ?></span>
                            <?php if ($c['last'] !== ''): ?><span class="dash-list-sub"><?= e($c['last']) ?></span><?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Пока нет переписки.</p>
        <?php endif; ?>
    </section>

    <section class="panel dash-mini">
        <div class="dash-mini-head">
            <h2>Уведомления <?php if ($notifs['unread']): ?><span class="badge"><?= (int)$notifs['unread'] ?></span><?php endif; ?></h2>
            <a class="btn btn-mini" href="/notifications">Все</a>
        </div>
        <?php if ($notifs['recent']): ?>
            <ul class="dash-list">
                <?php foreach ($notifs['recent'] as $n): ?>
                    <li>
                        <span class="dash-list-title<?= (int)$n['is_read'] ? '' : ' is-unread' ?>"><?= e($n['title']) ?></span>
                        <span class="dash-list-sub"><?= e(mb_strimwidth((string)$n['body'], 0, 80, '…')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Уведомлений нет.</p>
        <?php endif; ?>
    </section>
</div>

<!-- Мои проекты: карточки подсистем -->
<?php if ($cards): ?>
<h2 class="dash-section">Мои проекты</h2>
<div class="dash-cards">
    <?php foreach ($cards as $c): ?>
        <a class="dash-card<?= ($c['count'] ?? 0) > 0 ? ' has-work' : '' ?>" href="<?= e($c['url']) ?>">
            <div class="dash-card-ic"><?= $c['icon'] ?></div>
            <div class="dash-card-body">
                <div class="dash-card-title"><?= e($c['title']) ?></div>
                <div class="dash-card-hint"><?= e($c['hint']) ?></div>
            </div>
            <div class="dash-card-count">
                <?= $c['count'] === null ? '<span class="dash-arrow">→</span>' : (int)$c['count'] ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Мои задачи: корзины СЭД/поручений -->
<?php if ($tasks): ?>
<h2 class="dash-section">Мои задачи</h2>
<div class="dash-buckets">
    <?php foreach ($tasks as $b): ?>
        <section class="panel dash-bucket">
            <div class="dash-mini-head">
                <h3 style="margin:0"><?= e($b['label']) ?> <span class="badge"><?= (int)$b['count'] ?></span></h3>
                <a class="btn btn-mini" href="<?= e($b['link']) ?>">Все</a>
            </div>
            <?php if (!empty($b['items'])): ?>
                <ul class="dash-list">
                    <?php foreach ($b['items'] as [$href, $text]): ?>
                        <li><a href="<?= e($href) ?>"><span class="dash-list-title"><?= e($text) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$cards && !$tasks): ?>
<section class="panel"><p class="muted" style="margin:0">Активных задач нет. Хорошего дня!</p></section>
<?php endif; ?>
