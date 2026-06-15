<?php
use App\Controllers\DocumentController;
$folders = [
    'inbox'       => ['📥 На согласование мне', $counts['inbox']],
    'drafts'      => ['📝 Черновики и доработка', $counts['drafts']],
    'my'          => ['📄 Мои документы', $counts['my']],
    'participate' => ['🖊 С моим участием', $counts['participate']],
];
$folders['incoming']   = ['📨 Входящие', null];
$folders['outgoing']   = ['📤 Исходящие', null];
$folders['control']    = ['🔍 На контроле', null];
$folders['registered'] = ['📕 Журнал регистрации', null];
if ($isHead || $isPrivileged) { $folders['dept'] = ['🏢 Документы подразделения', null]; }
if ($isPrivileged)            { $folders['all']  = ['🗂 Все документы', null]; }
$statusClass = ['draft'=>'','on_approval'=>'st-wait','revision'=>'st-rev','approved'=>'st-ok'];
?>
<div class="chat-head">
    <h1 style="margin:0">Документы</h1>
    <a class="btn btn-primary" href="/docs/create">+ Новый документ</a>
    <?php if ($isPrivileged): ?><a class="btn" href="/docs/templates">Шаблоны маршрутов</a><?php endif; ?>
    <?php if ($isPrivileged): ?><a class="btn" href="/docs/register?direction=<?= e(in_array($folder,['incoming','outgoing'],true)?$folder:'outgoing') ?>" target="_blank">🖨 Реестр передачи</a><?php endif; ?>
    <form method="get" action="/docs" style="margin-left:auto;display:flex;gap:6px">
        <input type="hidden" name="folder" value="<?= e($folder) ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="поиск: №, заголовок, автор, текст…" style="margin-top:0;width:260px">
        <button class="btn">Найти</button>
    </form>
</div>

<div class="sed">
    <aside class="sed-side">
        <?php foreach ($folders as $key => [$label, $cnt]): ?>
            <a class="sed-folder<?= $folder === $key ? ' active' : '' ?>" href="/docs?folder=<?= $key ?>">
                <?= $label ?><?= $cnt ? ' <span class="badge">' . (int)$cnt . '</span>' : '' ?>
            </a>
        <?php endforeach; ?>
    </aside>

    <section class="panel sed-main">
        <table class="table">
            <thead><tr><th>Рег. №</th><th>Дата</th><th>Тип</th><th>Заголовок</th><th>Автор</th><th>Статус</th><th>У кого</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="doc-row" onclick="location.href='/docs/<?= (int)$r['id'] ?>'">
                    <td class="mono"><?= e($r['reg_number'] ?: '—') ?></td>
                    <td class="muted"><?= e(substr((string)($r['sent_at'] ?: $r['created_at']),0,10)) ?></td>
                    <td><?= e($r['type_name']) ?></td>
                    <td><strong><?= e(mb_strimwidth($r['title'],0,60,'…')) ?></strong>
                        <?php if (!empty($r['correspondent_name'])): ?><br><span class="muted" style="font-size:.76rem"><?= $r['direction']==='incoming'?'от: ':'кому: ' ?><?= e($r['correspondent_name']) ?></span><?php endif; ?></td>
                    <td><?= e($r['author_name']) ?><br><span class="muted" style="font-size:.76rem"><?= e($r['dept_name'] ?? '') ?></span></td>
                    <td><span class="st <?= $statusClass[$r['status']] ?? '' ?>"><?= e(DocumentController::STATUS_LABEL[$r['status']] ?? $r['status']) ?></span></td>
                    <td class="muted"><?= $r['status']==='on_approval' ? e($r['current_name'] ?? '') : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="muted">В этой папке пусто.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
