<?php
// Пакет служебок о стимуле: одна форма → по одной служебке на отдел (общий batch_id).
use App\Controllers\StimulusController; $st = $statusLabels ?? StimulusController::STATUS;
$stClass = ['draft'=>'','head_signed'=>'st-wait','deputy_signed'=>'st-wait','approved'=>'st-ok','rejected'=>'st-rev','revision'=>'st-rev'];
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/memos">← Служебки</a>
    <h1 style="margin:0;font-size:1.2rem">Пакет служебок о стимуле</h1>
    <span class="muted">период <?= e($period) ?> · автор <?= e($authorName) ?></span>
</div>

<section class="panel">
    <p class="muted" style="margin-top:0">Создано из одной формы — по одной служебке на каждый отдел сотрудников.
        Каждая служебка идёт по своему маршруту: <strong>инициатор → курирующий зам отдела → директор</strong>
        (а где автор сам курирует отдел — сразу директору).</p>

    <?php if (!empty($isAuthor) && (int)$draftMine > 0): ?>
    <div class="panel" style="background:#f5f7ff;border-left:4px solid #26368B">
        <h3 class="sub" style="margin-top:0">Подписать пакет как инициатор (ЭП — подтверждение паролем)</h3>
        <p class="muted" style="margin:0 0 8px">Одна подпись применится ко всем черновикам пакета (<?= (int)$draftMine ?>) — каждая служебка уйдёт курирующему заму своего отдела.</p>
        <form method="post" action="/memos/batch/<?= (int)$batchId ?>/sign" class="form-inline" style="align-items:flex-end;gap:10px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Ваш пароль<input type="password" name="password" required></label>
            <button class="btn btn-primary">🖋 Подписать все как инициатор</button>
        </form>
    </div>
    <?php endif; ?>

    <table class="table">
        <thead><tr><th>№</th><th>Отдел</th><th>Курирующий зам</th><th class="num">Чел.</th><th class="num">Сумма</th><th>Маршрут</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($memos as $m): $dt = $m['direct_tier'] ?? null; ?>
            <tr>
                <td class="mono"><?= e($m['number'] ?: '#'.$m['id']) ?></td>
                <td><a href="/memos/<?= (int)$m['id'] ?>"><?= e($m['dept_name'] ?? '—') ?></a></td>
                <td><?= $dt === 'director' ? '<span class="muted">— (утв. директором)</span>' : ($m['curator_name'] ? e($m['curator_name']) : '<span class="minus">не назначен</span>') ?></td>
                <td class="num"><?= (int)$m['people'] ?></td>
                <td class="num" style="white-space:nowrap"><?= money($m['total']) ?></td>
                <td class="muted" style="font-size:.8rem">
                    <?= $dt === 'director' ? 'директор (сразу)' : ($dt === 'deputy' ? 'зам-автор → директор' : 'инициатор → зам отдела → директор') ?>
                </td>
                <td><span class="st <?= $stClass[$m['status']] ?? '' ?>"><?= e($st[$m['status']] ?? $m['status']) ?></span></td>
                <td><a class="btn btn-mini" href="/memos/<?= (int)$m['id'] ?>">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total"><td colspan="4">Итого по пакету</td><td class="num" style="white-space:nowrap"><strong><?= money($total) ?></strong></td><td colspan="3"></td></tr>
        </tbody>
    </table>
</section>
