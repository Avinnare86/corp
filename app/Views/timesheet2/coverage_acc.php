<?php
/** Покрытие для бухгалтерии: по отделам — подписанные табели и графики 2/2 значками-документами (PDF-вид).
 *  $byDept: [отдел => ['tabels'=>[...], 'grafiks'=>[...]]]. По аналогии с покрытием в стимуле. */
$qs = '?month=' . rawurlencode($month);
$halfLabel = fn($p) => (int) substr($p, 8) === 1 ? '1–15' : '16–конец';
$tabKind = fn($t) => ($t['kind'] ?? 'std') === 'shift' ? 'Табель 2/2 (0504421)' : 'Табель 5/2';
?>
<style>
.cov-grid{display:flex;flex-wrap:wrap;gap:12px}
.cov-card{display:block;width:236px;border:1px solid #d7dbe8;border-radius:12px;padding:12px 14px;text-decoration:none;color:#223;background:#fff;transition:box-shadow .15s,border-color .15s}
.cov-card:hover{box-shadow:0 4px 16px rgba(38,54,139,.18);border-color:#26368B}
.cov-card .pdf{font-size:1.5rem;line-height:1}
.cov-card .num{font-weight:700;font-size:.98rem;margin:3px 0 1px}
.cov-card .meta{color:#5a6072;font-size:.8rem}
.cov-card.is-graf{border-left:4px solid #2b6cb0}
.cov-card.is-tab{border-left:4px solid #26368B}
.cov-type{display:inline-block;font-size:.7rem;font-weight:700;padding:1px 7px;border-radius:999px;background:#eef1fb;color:#26368B;margin-bottom:4px}
.cov-type.graf{background:#e7f0fb;color:#2b6cb0}
.cov-sign{display:inline-block;margin-top:6px;font-size:.76rem;padding:2px 8px;border-radius:8px;background:#e7f6ec;color:#1e7e34}
</style>

<div class="chat-head" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-mini" href="/timesheet2?month=<?= e($month) ?>">← Электронный табель</a>
    <h1 style="margin:0;font-size:1.2rem">Покрытие (бухгалтерия): табели и графики 2/2</h1>
    <a class="btn btn-primary" href="/timesheet2/coverage-acc/export<?= e($qs) ?>">⬇ Выгрузить в Excel</a>
</div>

<section class="panel">
    <form method="get" action="/timesheet2/coverage-acc" class="form-inline" style="gap:10px;align-items:flex-end">
        <label>Месяц
            <input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()">
        </label>
        <?php if ($periods): ?>
        <label>Быстрый выбор
            <select onchange="if(this.value){location.href='/timesheet2/coverage-acc?month='+this.value}">
                <option value="">— период —</option>
                <?php foreach ($periods as $p): ?><option value="<?= e($p) ?>" <?= $p === $month ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <span class="muted">Подписанные документы учёта рабочего времени по отделам. Каждый — значок: откройте для печати / сохранения в PDF.</span>
    </form>
    <p class="muted" style="margin:10px 0 0">За <?= e($month) ?>: табелей — <strong><?= (int)$nTab ?></strong>, графиков 2/2 — <strong><?= (int)$nGraf ?></strong>.</p>
</section>

<?php if (!$byDept): ?>
<section class="panel"><p class="muted">За период нет подписанных табелей и графиков.</p></section>
<?php endif; ?>

<?php foreach ($byDept as $dept => $g): $tabs = $g['tabels'] ?? []; $grafs = $g['grafiks'] ?? []; ?>
<section class="panel">
    <h2 style="margin-top:0"><?= e($dept) ?>
        <span class="muted" style="font-size:.85rem;font-weight:400">— табелей: <?= count($tabs) ?> · графиков: <?= count($grafs) ?></span>
    </h2>
    <div class="cov-grid">
        <?php foreach ($tabs as $t): ?>
        <a class="cov-card is-tab" href="/timesheet2/<?= (int)$t['id'] ?>/view" target="_blank" title="Открыть табель (печать / PDF)">
            <span class="cov-type">ТАБЕЛЬ</span>
            <div class="pdf">📄</div>
            <div class="num"><?= e($tabKind($t)) ?> · <?= e($halfLabel($t['period'])) ?></div>
            <div class="meta"><?= (int)$t['revision'] === 0 ? 'первичный' : 'корр. №' . (int)$t['revision'] ?> · ЭП: <?= e(\App\Controllers\TabelController::SIGN_TYPES[$t['sign_type']] ?? $t['sign_type']) ?></div>
            <span class="cov-sign">✔ <?= e($t['signer'] ?: '—') ?> · <?= e(substr((string)$t['signed_at'],0,16)) ?></span>
        </a>
        <?php endforeach; ?>
        <?php foreach ($grafs as $gr): ?>
        <a class="cov-card is-graf" href="/shifts/grafik?dept=<?= (int)$gr['department_id'] ?>&month=<?= e($month) ?>" target="_blank" title="Открыть график сменности (печать / PDF)">
            <span class="cov-type graf">ГРАФИК 2/2</span>
            <div class="pdf">🗓</div>
            <div class="num">График сменности</div>
            <div class="meta"><?= (int)$gr['revision'] === 0 ? 'первичный' : 'корр. №' . (int)$gr['revision'] ?> · ЭП: <?= e(\App\Controllers\TabelController::SIGN_TYPES[$gr['sign_type']] ?? $gr['sign_type']) ?></div>
            <span class="cov-sign">✔ <?= e($gr['signer_name'] ?: '—') ?> · <?= e(substr((string)$gr['signed_at'],0,16)) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
