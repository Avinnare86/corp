<?php
// Отчёт для бухгалтерии: по отделам — все служебки периода значками PDF (скачано / не скачано).
// $byDept: [отдел => [служебки]]; каждая — id/number/status/pay_kind/total/people/pdf_downloaded_at/pdf_by_name.
$qs = $period !== '' ? '?period=' . rawurlencode($period) : '';
$totMemos = 0; $totDl = 0; $totSum = 0.0;
foreach ($byDept as $list) {
    foreach ($list as $m) { $totMemos++; $totSum += (float) $m['total']; if ($m['pdf_downloaded_at']) { $totDl++; } }
}
$kindLabel = fn($k) => $k === 'onetime' ? 'единовр.' : 'ежемес.';
$stCls = fn($s) => ['approved' => 'st-ok', 'revision' => 'st-rev', 'rejected' => 'st-rev'][$s] ?? 'st-wait';
?>
<style>
.cov-grid{display:flex;flex-wrap:wrap;gap:12px}
.cov-card{display:block;width:230px;border:1px solid #d7dbe8;border-radius:12px;padding:12px 14px;text-decoration:none;color:#223;background:#fff;transition:box-shadow .15s,border-color .15s}
.cov-card:hover{box-shadow:0 4px 16px rgba(38,54,139,.18);border-color:#26368B}
.cov-card .pdf{font-size:1.5rem;line-height:1}
.cov-card .num{font-weight:700;font-size:1rem;margin:2px 0}
.cov-card .sum{font-size:1.05rem;color:#26368B;font-weight:700}
.cov-dl{display:inline-block;margin-top:6px;font-size:.78rem;padding:2px 8px;border-radius:8px}
.cov-dl.yes{background:#e7f6ec;color:#1e7e34}
.cov-dl.no{background:#fdecea;color:#c0392b}
.cov-card.downloaded{border-color:#bfe3cb;background:#f6fbf7}
</style>
<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <h1 style="margin:0;font-size:1.2rem">Покрытие стимулом (бухгалтерия)</h1>
    <a class="btn btn-primary" href="/memos/coverage/export<?= e($qs) ?>">⬇ Выгрузить в Excel</a>
</div>

<section class="panel">
    <form method="get" action="/memos/coverage" class="form-inline" style="gap:10px;align-items:flex-end">
        <label>Месяц
            <select name="period" onchange="this.form.submit()">
                <option value="">— все месяцы —</option>
                <?php foreach ($periods as $p): ?><option value="<?= e($p) ?>" <?= $period === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
        </label>
        <span class="muted">Каждая служебка — значок PDF (откройте для печати/сохранения). «✓ скачано» ставится, когда PDF открыла бухгалтерия.</span>
    </form>
    <?php if ($totMemos): ?>
    <p class="muted" style="margin:10px 0 0">Служебок за период: <strong><?= $totMemos ?></strong> · скачано: <strong><?= $totDl ?></strong> из <?= $totMemos ?> · сумма: <strong><?= money($totSum) ?></strong></p>
    <?php endif; ?>
</section>

<?php if (!$totMemos): ?>
<section class="panel"><p class="muted">За период нет служебок, видимых бухгалтерии (подписанных замом или утверждённых).</p></section>
<?php endif; ?>

<?php foreach ($byDept as $dept => $list):
    $dSum = array_sum(array_map(fn($m) => (float) $m['total'], $list)); ?>
<section class="panel">
    <h2 style="margin-top:0"><?= e($dept) ?>
        <span class="muted" style="font-size:.85rem;font-weight:400">— служебок: <?= count($list) ?> · <?= money($dSum) ?></span>
    </h2>
    <div class="cov-grid">
        <?php foreach ($list as $m): $dl = (bool) $m['pdf_downloaded_at']; ?>
        <a class="cov-card<?= $dl ? ' downloaded' : '' ?>" href="/memos/<?= (int)$m['id'] ?>/print" target="_blank" title="Открыть служебку (печать / PDF)">
            <div class="pdf">📄</div>
            <div class="num">№ <?= e($m['number'] ?: ('#' . $m['id'])) ?></div>
            <div class="sum"><?= money($m['total']) ?></div>
            <div class="muted" style="font-size:.8rem"><?= e($kindLabel($m['pay_kind'])) ?> · работников: <?= (int)$m['people'] ?>
                · <span class="st <?= $stCls($m['status']) ?>" style="font-size:.72rem"><?= e($statusLabels[$m['status']] ?? $m['status']) ?></span></div>
            <?php if ($dl): ?>
                <span class="cov-dl yes">✓ скачано · <?= e(substr((string)$m['pdf_downloaded_at'],0,16)) ?><?= $m['pdf_by_name'] ? ' · ' . e($m['pdf_by_name']) : '' ?></span>
            <?php else: ?>
                <span class="cov-dl no">⬇ не скачано</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
