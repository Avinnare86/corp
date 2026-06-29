<?php
/** @var array $picks year camp balance planned csrf */
$stage = $camp['stage'] ?? '';
$canBook = $stage === 'booking';
$left = max(0, (int) $balance - (int) $planned);
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign?year=<?= (int) $year ?>">← к кампании</a> &nbsp;
    Распределите свой отпуск на <?= (int) $year ?> год по частям. Система не даст записать период, нарушающий запретные зоны
    или лимиты одновременного отдыха.</p>

<section class="panel">
    <div style="display:flex;gap:18px;flex-wrap:wrap">
        <div><div class="muted" style="font-size:.8rem">Остаток на год</div><strong style="font-size:1.3rem"><?= (int) $balance ?> дн.</strong></div>
        <div><div class="muted" style="font-size:.8rem">Распределено</div><strong style="font-size:1.3rem"><?= (int) $planned ?> дн.</strong></div>
        <div><div class="muted" style="font-size:.8rem">Осталось распределить</div><strong style="font-size:1.3rem;color:<?= $left ? '#b06a00' : '#2a7' ?>"><?= $left ?> дн.</strong></div>
    </div>
    <p class="muted" style="margin:8px 0 0;font-size:.85rem">Совет: одна из частей отпуска должна быть не короче 14 календарных дней (≈ 10 рабочих).</p>
</section>

<?php if (!$camp): ?>
    <p class="tag warn">Кампания на <?= (int) $year ?> ещё не открыта.</p>
<?php elseif (!$canBook): ?>
    <p class="tag warn">Этап самозаписи сейчас не активен (текущий этап: «<?= e(\App\Services\VacationCampaignService::STAGES[$stage] ?? $stage) ?>»). Записанные периоды показаны ниже.</p>
<?php endif; ?>

<?php if ($canBook): ?>
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
