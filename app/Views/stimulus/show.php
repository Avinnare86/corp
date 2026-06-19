<?php use App\Controllers\StimulusController; $st = StimulusController::STATUS;
$isMgmt = ($kind ?? 'staff') === 'mgmt';
$direct = $direct ?? ($memo['direct_tier'] ?? null);
$isCross = $isCross ?? false;
$batchId = $batchId ?? ($memo['batch_id'] ?? null);
$batchCount = $batchCount ?? 0;
$dirStep = ['director', 'Директор', $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? null];
$depStep = ['deputy', 'Зам (прямое назначение)', $memo['deputy_signed_at'] ?? null, $memo['deputy_sign_type'] ?? null];
if ($isMgmt) {
    $steps = [['director', 'Директор (утверждает приказом)', $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? null]];
} elseif ($direct === 'director') {
    $steps = [['director', 'Директор (прямое назначение)', $memo['director_signed_at'] ?? null, $memo['director_sign_type'] ?? null]];
} elseif ($direct === 'deputy') {
    $steps = [$depStep, $dirStep];
} else {
    $steps = [
        ['head', $isCross ? 'Инициатор (вышестоящий)' : 'Начальник отдела', $memo['head_signed_at'] ?? null, $memo['head_sign_type'] ?? null],
        ['deputy', 'Курирующий зам', $memo['deputy_signed_at'] ?? null, $memo['deputy_sign_type'] ?? null],
        $dirStep,
    ];
}
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <?php if ($batchCount > 1): ?><a class="btn btn-mini" href="/memos/batch/<?= (int)$batchId ?>">⊞ Пакет (<?= (int)$batchCount ?>)</a><?php endif; ?>
    <h1 style="margin:0;font-size:1.2rem">Служебка №<?= e($memo['number'] ?: $memo['id']) ?></h1>
    <span class="st <?= ['approved'=>'st-ok','revision'=>'st-rev','rejected'=>'st-rev'][$memo['status']] ?? 'st-wait' ?>"><?= e($st[$memo['status']] ?? $memo['status']) ?></span>
</div>

<div class="doc-grid">
<div>
    <section class="panel">
        <h2>Реквизиты</h2>
        <table class="table">
            <tr><td class="muted" style="width:150px">Отдел</td><td><?= e($memo['dept_name'] ?? '—') ?></td></tr>
            <tr><td class="muted">Автор</td><td><?= e($memo['author_name']) ?></td></tr>
            <tr><td class="muted">Период</td><td><?= e($memo['period']) ?></td></tr>
            <tr><td class="muted">Вид выплаты</td><td><?= $memo['pay_kind']==='onetime' ? 'единовременная (полной суммой)' : 'ежемесячная (пропорц. отработке)' ?></td></tr>
            <?php if ($source): ?><tr><td class="muted">Источник</td><td><?= e($source['name']) ?><?= $source['detail']?' ('.e($source['detail']).')':'' ?></td></tr><?php endif; ?>
            <tr><td class="muted">Сумма по служебке</td><td><strong><?= money($total) ?></strong></td></tr>
        </table>
        <h3 class="sub">Основания (раздел 4) — перечень с нормативным %</h3>
        <?php if (!empty($groundRows)): ?>
            <table class="table">
                <thead><tr><th>Показатель</th><th class="num" style="white-space:nowrap">Норматив</th></tr></thead>
                <tbody>
                <?php foreach ($groundRows as $g): ?>
                    <tr><td><?= e($g['text']) ?> <span class="muted" style="font-size:.78rem"><?= e($g['category']) ?></span></td>
                        <td class="num" style="white-space:nowrap"><?= (float)$g['percent']>0 ? 'до '.e(rtrim(rtrim(number_format((float)$g['percent'],1,'.',''),'0'),'.')).'%' : '—' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="doc-body"><?= nl2br(e(str_replace('; ', "\n", (string)$memo['grounds']))) ?></div>
        <?php endif; ?>

        <?php if ($memo['status']==='revision' && $memo['reject_reason']): ?>
            <div class="flash flash-error" style="margin-top:10px">Возвращено на доработку: <?= e($memo['reject_reason']) ?></div>
        <?php endif; ?>

        <div class="form-inline" style="margin-top:12px">
            <?php if ($canEdit): ?><a class="btn btn-primary" href="/memos/<?= (int)$memo['id'] ?>/edit">✎ Редактировать</a>
                <form method="post" action="/memos/<?= (int)$memo['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить черновик?')"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-danger">Удалить</button></form>
            <?php endif; ?>
        </div>
    </section>

    </section>
</div>

<div>
    <section class="panel">
        <h2>Маршрут подписания</h2>
        <div class="stepper">
            <?php foreach ($steps as [$k,$label,$at,$type]):
                $done = (bool)$at; $cls = $done ? 'ok' : 'wait'; ?>
                <div class="step <?= $cls ?>">
                    <span class="step-ic"><?= $done ? '🖋' : '○' ?></span>
                    <div class="step-body"><strong><?= e($label) ?></strong>
<?php $typeMap=['PEP'=>'ПЭП','UNEP'=>'УНЭП','UKEP'=>'УКЭП']; ?>
                        <?php if ($done): ?><span class="muted" style="font-size:.76rem">ЭП <?= e($typeMap[$type] ?? $type) ?> · <?= e(substr((string)$at,0,16)) ?></span>
                        <?php else: ?><span class="muted" style="font-size:.76rem">ожидает</span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($canHeadSign || $canDeputySign || $canDirectorSign || !empty($canMgmtSign) || !empty($canDirectSign)): ?>
        <h3 class="sub">Подписать (ЭП — подтверждение паролем)</h3>
        <form method="post" action="/memos/<?= (int)$memo['id'] ?>/sign">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Ваш пароль<input type="password" name="password" required></label>
            <div class="form-inline" style="margin-top:8px">
                <button class="btn btn-primary">
                    <?= !empty($canDirectSign) ? e($directLabel)
                        : (!empty($canMgmtSign) ? '🖋 Утвердить приказом директора'
                        : ($canHeadSign ? '🖋 Подписать и направить заму'
                        : ($canDeputySign ? '🖋 Утвердить (заму)' : '🖋 Утвердить (директор)'))) ?>
                </button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($canReject): ?>
        <details style="margin-top:12px">
            <summary class="muted" style="cursor:pointer">Отклонить / вернуть на доработку…</summary>
            <form method="post" action="/memos/<?= (int)$memo['id'] ?>/reject" style="margin-top:8px">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label>Причина<input type="text" name="reason" required></label>
                <button class="btn btn-danger" style="margin-top:6px">Вернуть автору</button>
            </form>
        </details>
        <?php endif; ?>
    </section>
</div>
</div>

<section class="panel">
    <h2>Работники и суммы</h2>
    <table class="table">
        <thead><tr><th style="min-width:220px">Работник</th><th class="num" style="white-space:nowrap">Сумма, ₽</th><th class="num" style="white-space:nowrap">Оклад×ставка</th><th class="num">%</th><th>Вид выплаты</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <tr>
                <td><?= e($l['full_name']) ?> <span class="muted" style="font-size:.78rem"><?= e($l['position']) ?></span></td>
                <td class="num" style="white-space:nowrap"><?= money($l['amount']) ?></td>
                <td class="num muted" style="white-space:nowrap"><?= money($l['oklad_load']) ?></td>
                <td class="num" style="white-space:nowrap"><strong><?= e(rtrim(rtrim(number_format((float)$l['percent'],1,'.',''),'0'),'.')) ?>%</strong></td>
                <td><?= $l['pay_kind']==='onetime' ? 'единовременная' : 'ежемесячная (пропорц. отработке)' ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total"><td>Итого по служебке</td><td class="num" style="white-space:nowrap"><strong><?= money($total) ?></strong></td><td colspan="3"></td></tr>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Сформированный документ
        <a class="btn btn-mini" style="float:right" href="/memos/<?= (int)$memo['id'] ?>/print" target="_blank">⤢ Открыть · печать / PDF</a>
    </h2>
    <iframe class="doc-preview" src="/memos/<?= (int)$memo['id'] ?>/print" title="Служебная записка"></iframe>
</section>
