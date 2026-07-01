<?php
/** Очередь уведомлений об отпуске (кадры). Начальник отдела кадров подписывает пакет ЭП и рассылает.
 *  @var array $rows year hrHead meIsHrHead draftCount years signTypes csrf title */
$st = [
    'draft' => ['не подписано', 'warn'],
    'signed' => ['подписано (не разослано)', 'warn'],
    'sent' => ['подписано и направлено', 'ok'],
];
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Уведомления об отпуске (ст. 123 ТК РФ) формируются автоматически после утверждения
    сводного графика Т-7 директором. Начальник отдела кадров подписывает весь пакет своей электронной подписью — и только
    после подписи каждое уведомление направляется сотруднику в личный кабинет и на почту.</p>

<form method="get" action="/vacation-schedule/notices" style="margin-bottom:12px">
    <label>Год
        <select name="year" onchange="this.form.submit()">
            <?php foreach ($years as $y): ?>
                <option value="<?= (int) $y ?>" <?= (int) $y === (int) $year ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<section class="panel">
    <h2 style="margin-top:0">Подпись и рассылка</h2>
    <?php if (!$hrHead): ?>
        <p class="tag warn">Не задан начальник отдела кадров. Укажите его в разделе «Админ → Настройки» (параметр «Начальник отдела кадров»).</p>
    <?php else: ?>
        <p class="muted" style="margin-top:0">Подписант: <strong><?= e($hrHead['full_name']) ?></strong><?= $hrHead['position'] ? ', ' . e($hrHead['position']) : '' ?>.
            К подписи: <strong><?= (int) $draftCount ?></strong> уведомлений.</p>
        <?php if ($draftCount > 0 && $meIsHrHead): ?>
            <form method="post" action="/vacation-schedule/notices/sign" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"
                  onsubmit="return confirm('Подписать <?= (int) $draftCount ?> уведомлений своей ЭП и разослать сотрудникам?')">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="year" value="<?= (int) $year ?>">
                <select name="sign_type" required><?php foreach ($signTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                <input type="password" name="password" placeholder="пароль ЭП" required>
                <button class="btn btn-primary">✍ Подписать пакет и разослать (<?= (int) $draftCount ?>)</button>
            </form>
        <?php elseif ($draftCount > 0): ?>
            <p class="tag warn">Подписать уведомления может только начальник отдела кадров (<?= e($hrHead['full_name']) ?>) — войдите под его учётной записью.</p>
        <?php else: ?>
            <p class="tag ok">Все уведомления за <?= (int) $year ?> подписаны и разосланы.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Уведомления за <?= (int) $year ?></h2>
    <table class="table tbl-cards">
        <thead><tr><th>Сотрудник</th><th>Период</th><th>Дней</th><th>Статус</th><th>Направлено</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $s = $st[$r['status']] ?? [$r['status'], '']; ?>
            <tr>
                <td data-label="Сотрудник"><?= e($r['full_name']) ?></td>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?></td>
                <td data-label="Дней" class="c"><?= (int) $r['days'] ?></td>
                <td data-label="Статус"><span class="tag <?= e($s[1]) ?>"><?= e($s[0]) ?></span></td>
                <td data-label="Направлено"><?= $r['notified_at'] ? e(substr($r['notified_at'], 0, 16)) : '—' ?></td>
                <td><a class="btn btn-mini" href="/vacation-schedule/notice/<?= (int) $r['id'] ?>" target="_blank">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Уведомлений за <?= (int) $year ?> нет — они появятся после утверждения графика Т-7 директором.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
