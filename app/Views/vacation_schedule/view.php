<?php use App\Services\VacationScheduleService as VS;
use App\Controllers\VacationScheduleController;
$signed = $s['status'] === 'signed';
?>
<h1 style="margin-bottom:2px">График отпусков на <?= (int) $s['year'] ?> год</h1>
<p class="muted" style="margin-top:0">
    Охват: <strong><?= e($scope) ?></strong>.
    Ревизия: <?= (int) $s['revision'] === 0 ? 'основной' : 'корректировочный № ' . (int) $s['revision'] ?>.
    <?= $signed ? '<span class="tag ok">подписан</span>' : '<span class="tag">черновик</span>' ?>
    <a href="/vacation-schedule">← к списку</a>
</p>

<section class="panel">
    <table class="table tbl-cards">
        <thead><tr><th>№</th><th>Сотрудник</th><th>Должность</th><th>Период отпуска</th><th class="num">Дней</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($rows as $r): $i++; ?>
            <tr>
                <td data-label="№"><?= $i ?></td>
                <td data-label="Сотрудник"><?= e($r['full_name']) ?></td>
                <td data-label="Должность" class="muted"><?= e($r['position'] ?? '') ?></td>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?>
                    <?php if ($r['status'] !== VS::ROW_APPROVED): ?><span class="tag">не согласован</span><?php endif; ?></td>
                <td data-label="Дней" class="num"><?= (int) $r['days'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Периоды не заданы.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($signed): ?>
<section class="panel" style="max-width:520px">
    <h2 style="margin-top:0">Электронная подпись</h2>
    <p style="margin:0;line-height:1.7">
        Подписал: <strong><?= e($s['signer_name'] ?: '—') ?></strong><br>
        <?php if ($s['signer_position']): ?>Должность: <?= e($s['signer_position']) ?><br><?php endif; ?>
        Вид подписи: <?= e(VacationScheduleController::SIGN_TYPES[$s['sign_type']] ?? $s['sign_type']) ?><br>
        Сертификат: <span class="mono"><?= e($s['cert_serial']) ?></span><br>
        <?php if (!empty($sig['fingerprint'])): ?>Отпечаток сертификата: <span class="mono" style="font-size:.8rem"><?= e($sig['fingerprint']) ?></span><br><?php endif; ?>
        Подписано: <?= e(substr((string) $s['signed_at'], 0, 16)) ?><br>
        Хэш содержимого: <span class="mono" style="font-size:.8rem"><?= e((string) $s['sign_hash']) ?></span>
        <?php if (!empty($sig['sig_b64'])): ?><br><span class="tag ok">прикреплена усиленная подпись (.sig)</span><?php endif; ?>
    </p>
</section>
<?php endif; ?>

<?php if ($signed && $s['archived_at'] === null): ?>
<form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/archive" onsubmit="return confirm('Переместить в архив?')" style="margin-top:8px">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn">В архив</button>
</form>
<?php endif; ?>
