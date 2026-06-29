<?php
/** @var array $rows memo signTypes isHr year dept csrf */
$status = $memo['status'] ?? 'new';
$statusLabel = [
    'new' => 'не начата', 'draft' => 'на доработке', 'head_signed' => 'подписана начальником (ожидает зама)',
    'deputy_signed' => 'утверждена замом (ожидает директора)', 'approved' => 'утверждена директором',
][$status] ?? $status;
// полнота: можно ли подписывать (у всех распределён остаток + длинная часть)
$blocked = [];
foreach ($rows as $r) {
    if ((int) $r['balance'] <= 0) { continue; }
    if ((int) $r['planned'] < (int) $r['balance']) { $blocked[] = $r['emp']['full_name'] . ' — ' . (int) $r['planned'] . '/' . (int) $r['balance'] . ' дн.'; }
}
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign?year=<?= (int) $year ?>">← к кампании</a> &nbsp;
    Служебная записка о графике отпусков отдела на <?= (int) $year ?> год. Маршрут: начальник → курирующий зам → директор.
    После утверждения директором кадры формируют итоговый график.</p>

<section class="panel">
    <strong>Статус: </strong>
    <?php if ($status === 'approved'): ?><span class="tag ok"><?= e($statusLabel) ?></span>
    <?php elseif ($status === 'new'): ?><span class="tag"><?= e($statusLabel) ?></span>
    <?php else: ?><span class="tag warn"><?= e($statusLabel) ?></span><?php endif; ?>
    <?php if (!empty($memo['reject_reason']) && $status === 'draft'): ?>
        <div class="muted" style="margin-top:6px">Причина возврата: <?= e($memo['reject_reason']) ?></div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 style="margin-top:0">Предложения сотрудников</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Сотрудник</th><th>Должность</th><th>Остаток</th><th>Распределено</th><th>Периоды</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $full = (int) $r['planned'] >= (int) $r['balance']; ?>
            <tr>
                <td data-label="Сотрудник"><?= e($r['emp']['full_name']) ?></td>
                <td data-label="Должность" class="muted"><?= e((string) ($r['emp']['position'] ?? '')) ?></td>
                <td data-label="Остаток"><?= (int) $r['balance'] ?></td>
                <td data-label="Распределено"><span class="tag <?= $full ? 'ok' : 'warn' ?>"><?= (int) $r['planned'] ?> дн.</span></td>
                <td data-label="Периоды">
                    <?php foreach ($r['picks'] as $p): ?>
                        <span class="tag"><?= e(date('d.m', strtotime($p['start_date']))) ?>–<?= e(date('d.m', strtotime($p['end_date']))) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$r['picks']): ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">В отделе нет активных сотрудников.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php
// показать панель подписи, если есть следующий этап
$canSignStage = in_array($status, ['new', 'draft', 'head_signed', 'deputy_signed'], true);
?>
<?php if ($canSignStage): ?>
    <?php if ($status === 'new' || $status === 'draft'): ?>
        <?php if ($blocked): ?>
            <section class="panel" style="border-left:4px solid #e0a800">
                <strong>Нельзя подписать — отпуск распределён не у всех:</strong>
                <ul style="margin:6px 0 0"><?php foreach ($blocked as $b): ?><li class="muted"><?= e($b) ?></li><?php endforeach; ?></ul>
            </section>
        <?php endif; ?>
    <?php endif; ?>
    <section class="panel">
        <h2 style="margin-top:0">
            <?= $status === 'new' || $status === 'draft' ? 'Подписать служебку (начальник)' : ($status === 'head_signed' ? 'Утвердить (зам)' : 'Утвердить (директор)') ?>
        </h2>
        <form method="post" action="/vacation-campaign/memo/<?= (int) $dept ?>/sign?year=<?= (int) $year ?>" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Вид подписи
                <select name="sign_type" required>
                    <?php foreach ($signTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Пароль <input type="password" name="password" required></label>
            <button class="btn btn-primary" <?= ($status === 'new' || $status === 'draft') && $blocked ? 'disabled' : '' ?>>Подписать</button>
        </form>
    </section>
    <?php if (in_array($status, ['head_signed', 'deputy_signed'], true)): ?>
        <section class="panel">
            <form method="post" action="/vacation-campaign/memo/<?= (int) $dept ?>/reject?year=<?= (int) $year ?>" onsubmit="return confirm('Вернуть служебку начальнику на доработку?')" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label>Причина возврата <input type="text" name="reason" maxlength="500"></label>
                <button class="btn btn-mini btn-danger">Вернуть на доработку</button>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php if ($status === 'approved' && $isHr): ?>
    <section class="panel" style="border-left:4px solid #2a7">
        <h2 style="margin-top:0">Формирование графика</h2>
        <p class="muted" style="margin-top:0">Служебка утверждена директором. Сформируйте документ «График отпусков» отдела
            из распределённых периодов — затем подпишите его в разделе «График отпусков».</p>
        <form method="post" action="/vacation-campaign/memo/<?= (int) $dept ?>/form-schedule?year=<?= (int) $year ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-primary">Сформировать график отпусков отдела</button>
        </form>
    </section>
<?php endif; ?>
