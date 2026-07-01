<?php
/** @var array $rows year csrf */
$reqLabels = ['add' => 'добавить период', 'remove' => 'убрать период', 'carry_next_year' => 'перенести на след. год'];
$reqStatus = ['pending' => 'на рассмотрении', 'approved' => 'одобрена', 'rejected' => 'отклонена'];
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign?year=<?= (int) $year ?>">← к кампании</a> &nbsp;
    Заявки сотрудников ваших отделов на изменение графика отпусков после самозаписи. Одобрение сразу применяет правку
    к текущим данным (записи, при наличии сформированного графика — и к его строкам).</p>

<section class="panel">
    <table class="table tbl-cards">
        <thead><tr><th>Сотрудник</th><th>Отдел</th><th>Тип</th><th>Период / дни</th><th>Комментарий</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Сотрудник"><?= e($r['full_name']) ?></td>
                <td data-label="Отдел" class="muted"><?= e((string) ($r['dept_name'] ?? '')) ?></td>
                <td data-label="Тип"><?= e($reqLabels[$r['kind']] ?? $r['kind']) ?></td>
                <td data-label="Период/дни">
                    <?php if ($r['kind'] === 'carry_next_year'): ?><?= (int) $r['days'] ?> дн.
                    <?php else: ?><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?> (<?= (int) $r['days'] ?> дн.)
                    <?php endif; ?>
                </td>
                <td data-label="Комментарий" class="muted"><?= e((string) ($r['note'] ?? '')) ?></td>
                <td data-label="Статус">
                    <?php if ($r['status'] === 'approved'): ?><span class="tag ok"><?= e($reqStatus['approved']) ?></span>
                    <?php elseif ($r['status'] === 'rejected'): ?><span class="tag warn"><?= e($reqStatus['rejected']) ?></span>
                    <?php else: ?><span class="tag"><?= e($reqStatus['pending']) ?></span><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <form method="post" action="/vacation-campaign/change-requests/<?= (int) $r['id'] ?>/decide?year=<?= (int) $year ?>">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="act" value="approve">
                            <button class="btn btn-mini btn-primary">Одобрить</button>
                        </form>
                        <form method="post" action="/vacation-campaign/change-requests/<?= (int) $r['id'] ?>/decide?year=<?= (int) $year ?>" onsubmit="return promptReason(this)">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="act" value="reject">
                            <input type="hidden" name="reason" value="">
                            <button class="btn btn-mini btn-danger">Отклонить</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Заявок нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<script>
function promptReason(form) {
    var r = prompt('Причина отклонения (необязательно):', '');
    if (r === null) return false;
    form.querySelector('input[name="reason"]').value = r;
    return true;
}
</script>
