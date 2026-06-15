<div class="chat-head">
    <a class="btn btn-mini" href="/orders?tab=out">← Поручения</a>
    <h1 style="margin:0">Исполнительская дисциплина</h1>
    <a class="btn btn-gold" href="/orders/report?export=1">📊 В Excel</a>
</div>

<section class="panel">
    <table class="table">
        <thead><tr><th>Исполнитель</th><th class="num">Всего</th><th class="num">Исполнено</th><th class="num">В срок</th><th class="num">Просрочено</th><th class="num">В работе</th><th style="width:220px">% в срок</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['name']) ?></td>
                <td class="num"><?= $r['total'] ?></td>
                <td class="num"><?= $r['done'] ?></td>
                <td class="num"><?= $r['done_on_time'] ?></td>
                <td class="num <?= $r['overdue'] ? 'minus' : '' ?>"><?= $r['overdue'] ?></td>
                <td class="num"><?= $r['active'] ?></td>
                <td><div class="bar"><div class="bar-fill" style="width:<?= $r['pct'] ?>%"></div><span class="bar-label"><?= $r['pct'] ?>%</span></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Поручений пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
