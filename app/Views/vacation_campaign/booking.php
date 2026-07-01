<?php
/** @var array $picks year camp stage balance planned carriedOut myRequests csrf */
$canBook = $stage === 'booking';
$total = (int) ($balance['total'] ?? 0);
$carriedIn = (int) ($balance['carried_in'] ?? 0);
$fresh = (int) ($balance['fresh'] ?? 0);
$left = max(0, $total - (int) $planned - (int) $carriedOut);
$reqLabels = ['add' => 'добавить период', 'remove' => 'убрать период', 'carry_next_year' => 'перенести на след. год'];
$reqStatus = ['pending' => 'на рассмотрении', 'approved' => 'одобрена', 'rejected' => 'отклонена'];
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign?year=<?= (int) $year ?>">← к кампании</a> &nbsp;
    Распределите свой отпуск на <?= (int) $year ?> год по частям. Система не даст записать период, нарушающий запретные зоны
    или лимиты одновременного отдыха.</p>

<section class="panel">
    <div style="display:flex;gap:18px;flex-wrap:wrap">
        <div><div class="muted" style="font-size:.8rem">Остаток на год<?= $carriedIn ? " ($fresh + перенесено $carriedIn)" : '' ?></div><strong style="font-size:1.3rem"><?= $total ?> дн.</strong></div>
        <div><div class="muted" style="font-size:.8rem">Распределено</div><strong style="font-size:1.3rem"><?= (int) $planned ?> дн.</strong></div>
        <?php if ($carriedOut): ?>
        <div><div class="muted" style="font-size:.8rem">Перенесено на <?= (int) $year + 1 ?> год</div><strong style="font-size:1.3rem"><?= (int) $carriedOut ?> дн.</strong></div>
        <?php endif; ?>
        <div><div class="muted" style="font-size:.8rem">Не учтено (нужно распределить или перенести)</div><strong style="font-size:1.3rem;color:<?= $left ? '#b06a00' : '#2a7' ?>"><?= $left ?> дн.</strong></div>
    </div>
    <p class="muted" style="margin:8px 0 0;font-size:.85rem">Совет: одна из частей отпуска должна быть не короче 14 календарных дней (≈ 10 рабочих). Все дни года
        должны быть либо распределены по датам, либо явно перенесены на следующий год — «потерянных» дней быть не должно.</p>
</section>

<?php if (!$camp): ?>
    <p class="tag warn">Кампания на <?= (int) $year ?> ещё не открыта — предложения по отпускам вводить нельзя.</p>
<?php elseif ($canBook): ?>
<section class="panel">
    <h2 style="margin-top:0">Добавить часть отпуска</h2>
    <form method="post" action="/vacation-campaign/picks?year=<?= (int) $year ?>" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>С <input type="date" name="start_date" required></label>
        <label>По <input type="date" name="end_date" required></label>
        <label>Комментарий <input type="text" name="note" maxlength="300"></label>
        <button class="btn btn-primary">Записать период</button>
    </form>
</section>
<?php else: ?>
    <p class="tag warn">Этап самозаписи завершён (текущий этап: «<?= e(\App\Services\VacationCampaignService::STAGES[$stage] ?? $stage) ?>»).
        Изменения теперь вносятся заявкой — её рассматривает начальник отдела.</p>

    <section class="panel">
        <h2 style="margin-top:0">Заявка на изменение графика</h2>
        <div class="grid-form" style="grid-template-columns:repeat(3,1fr);gap:16px">
            <form method="post" action="/vacation-campaign/change-requests?year=<?= (int) $year ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="kind" value="add">
                <strong>Добавить период</strong>
                <label>С <input type="date" name="start_date" required></label>
                <label>По <input type="date" name="end_date" required></label>
                <label>Комментарий <input type="text" name="note" maxlength="300"></label>
                <button class="btn btn-mini btn-primary" style="margin-top:6px">Подать заявку</button>
            </form>
            <form method="post" action="/vacation-campaign/change-requests?year=<?= (int) $year ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="kind" value="remove">
                <strong>Убрать период</strong>
                <label>Какой период
                    <select name="pick_id" required>
                        <option value="">— выберите —</option>
                        <?php foreach ($picks as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= e(date('d.m.Y', strtotime($p['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($p['end_date']))) ?> (<?= (int) $p['days'] ?> дн.)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Комментарий <input type="text" name="note" maxlength="300"></label>
                <button class="btn btn-mini btn-primary" style="margin-top:6px" <?= $picks ? '' : 'disabled' ?>>Подать заявку</button>
            </form>
            <form method="post" action="/vacation-campaign/change-requests?year=<?= (int) $year ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="kind" value="carry_next_year">
                <strong>Перенести на <?= (int) $year + 1 ?> год</strong>
                <label>Сколько дней <input type="number" name="days" min="1" max="<?= max(1, $left + (int) $carriedOut) ?>" required></label>
                <label>Комментарий <input type="text" name="note" maxlength="300"></label>
                <button class="btn btn-mini btn-primary" style="margin-top:6px">Подать заявку</button>
            </form>
        </div>
    </section>

    <?php if ($myRequests): ?>
    <section class="panel">
        <h2>Мои заявки на изменение</h2>
        <table class="table tbl-cards">
            <thead><tr><th>Тип</th><th>Период / дни</th><th>Статус</th><th>Комментарий</th></tr></thead>
            <tbody>
            <?php foreach ($myRequests as $r): ?>
                <tr>
                    <td data-label="Тип"><?= e($reqLabels[$r['kind']] ?? $r['kind']) ?></td>
                    <td data-label="Период/дни">
                        <?php if ($r['kind'] === 'carry_next_year'): ?><?= (int) $r['days'] ?> дн.
                        <?php else: ?><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Статус">
                        <?php if ($r['status'] === 'approved'): ?><span class="tag ok"><?= e($reqStatus['approved']) ?></span>
                        <?php elseif ($r['status'] === 'rejected'): ?><span class="tag warn" title="<?= e((string) ($r['reject_reason'] ?? '')) ?>"><?= e($reqStatus['rejected']) ?></span>
                        <?php else: ?><span class="tag"><?= e($reqStatus['pending']) ?></span><?php endif; ?>
                    </td>
                    <td data-label="Комментарий" class="muted"><?= e((string) ($r['note'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
<?php endif; ?>

<section class="panel">
    <h2>Мои периоды</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Период</th><th>Дней</th><th>Комментарий</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($picks as $p): ?>
            <tr>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($p['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($p['end_date']))) ?></td>
                <td data-label="Дней"><?= (int) $p['days'] ?></td>
                <td data-label="Комментарий" class="muted"><?= e((string) ($p['note'] ?? '')) ?></td>
                <td>
                    <?php if ($canBook): ?>
                    <form method="post" action="/vacation-campaign/picks/<?= (int) $p['id'] ?>/delete" onsubmit="return confirm('Удалить период?')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn-mini btn-danger">Удалить</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$picks): ?><tr><td colspan="4" class="muted">Пока ничего не записано.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
