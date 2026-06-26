<?php /** Partial: проверенные анкеты специалиста (раскрытие строки отчёта о проверке). */ ?>
<?php if (!$rows): ?>
    <div class="muted" style="padding:8px 4px">Нет проверенных анкет за выбранный период.</div>
<?php else: ?>
<table class="table" style="margin:0;background:#fff">
    <thead>
        <tr>
            <th>Рег. номер</th><th>Страна</th><th>Дата проверки</th>
            <th>Доработка (при проверке)</th><th>Последующий контроль</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['reg_number']) ?></strong></td>
                <td><?= e($r['country_code']) ?></td>
                <td><?= e($r['checked_day']) ?></td>
                <td>
                    <?php if ((int) $r['has_dorabotka'] === 1): ?>
                        <span class="tag">доработка<?= $r['comment_text'] ? ': ' . e($r['comment_text']) : '' ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
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
