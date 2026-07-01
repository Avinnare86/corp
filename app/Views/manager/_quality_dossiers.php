<?php /** Partial: проверенные анкеты специалиста (раскрытие строки отчёта о проверке). */ ?>
<?php if (!$rows): ?>
    <div class="muted" style="padding:8px 4px">Нет проверенных анкет за выбранный период.</div>
<?php else: ?>
<table class="table tbl-cards q-card" style="margin:0;background:#fff">
    <thead>
        <tr>
            <th>Рег. номер</th><th>Страна</th><th>Дата проверки</th><th>Вид</th>
            <th>Доработка (при проверке)</th><th>Последующий контроль</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): $isRecheck = (int) $r['is_recheck'] === 1; ?>
            <tr>
                <td><strong><?= e($r['reg_number']) ?></strong></td>
                <td data-label="Страна"><?= e($r['country_code']) ?></td>
                <td data-label="Дата проверки"><?= e($r['checked_day']) ?></td>
                <td data-label="Вид">
                    <?php if ($isRecheck): ?>
                        <span class="tag" style="background:#fff4e5;color:#93590c" title="Эта анкета — переделка брака, найденного у другого специалиста<?= $r['src_name'] ? ' (' . e($r['src_name']) . ($r['src_checked_day'] ? ', ' . e($r['src_checked_day']) : '') . ')' : '' ?>">🔁 повтор<?= $r['src_name'] ? ' после ' . e($r['src_name']) : '' ?></span>
                    <?php else: ?>
                        <span class="tag" style="background:#eef2ff;color:#3730a3">первичная</span>
                        <?php if ((int) $r['spawned_recheck'] === 1): ?>
                            <div class="muted" style="font-size:.72rem;margin-top:2px">брак — ушла на переделку другому специалисту</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td data-label="Доработка">
                    <?php if ((int) $r['has_dorabotka'] === 1): ?>
                        <span class="tag">доработка<?= $r['comment_text'] ? ': ' . e($r['comment_text']) : '' ?></span>
                    <?php else: ?>
                        <span class="muted">без замечаний</span>
                    <?php endif; ?>
                </td>
                <td data-label="Контроль">
                    <?php if ($r['ctrl_correct'] === null): ?>
                        <span class="muted">не контролировалась</span>
                    <?php elseif ((int) $r['ctrl_correct'] === 1): ?>
                        <span class="tag ok">корректно</span>
                    <?php else: ?>
                        <span class="tag" style="background:#fdecec;color:#b42318">ошибка<?= $r['ctrl_error'] ? ': ' . e($r['ctrl_error']) : '' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p class="muted" style="font-size:.8rem;margin:4px 0 0">Всего показано: <?= count($rows) ?><?= count($rows) >= 1000 ? ' (первые 1000)' : '' ?>.</p>
<?php endif; ?>
