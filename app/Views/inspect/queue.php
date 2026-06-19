<h1>Проверка анкет за <?= e($date) ?></h1>
<p>
    <a href="/inspect">← к списку выборок</a>
    <?php if ($batch['finished_at']): ?>
        <span class="tag ok">проверка завершена <?= e($batch['finished_at']) ?></span>
    <?php endif; ?>
</p>

<section class="panel">
    <table class="table">
        <thead>
        <tr><th>Специалист</th><th>Рег. номер</th><th>План приема</th><th>Вердикт</th><th>Тип ошибки</th><th class="num">Снижение</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
            $reviewed = $it['is_correct'] !== null;
            $isError = $reviewed && (int) $it['is_correct'] === 0;
        ?>
            <tr class="<?= $reviewed ? ($isError ? 'row-error' : 'row-ok') : '' ?>">
                <td><?= e($it['employee_name']) ?></td>
                <td><strong><?= e($it['reg_number']) ?></strong> <span class="muted"><?= e($it['country_code']) ?></span></td>
                <?php $al = arrival_label($it['arrival_code'] ?? null, $it['arrival_detail'] ?? null); ?>
                <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($al) ?>"><?= $al !== '' ? e($al) : '<span class="muted">—</span>' ?></td>
                <td colspan="3">
                    <form method="post" action="/inspect/<?= (int) $it['id'] ?>/review" class="review-form">
                        <?= csrf_field() ?>
                        <label class="radio">
                            <input type="radio" name="verdict" value="correct" <?= $reviewed && !$isError ? 'checked' : '' ?>>
                            Корректно
                        </label>
                        <label class="radio">
                            <input type="radio" name="verdict" value="error" <?= $isError ? 'checked' : '' ?>>
                            Ошибка
                        </label>
                        <select name="error_type_id">
                            <option value="">— тип ошибки —</option>
                            <?php foreach ($errorTypes as $et): ?>
                                <option value="<?= (int) $et['id'] ?>" <?= (int) $it['error_type_id'] === (int) $et['id'] ? 'selected' : '' ?>>
                                    <?= e($et['name']) ?> (−<?= e($et['penalty']) ?> ₽)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="comment" class="comment-input"
                               placeholder="Комментарий к ошибке (необязательно) — придёт сотруднику"
                               value="<?= e($it['controller_comment'] ?? '') ?>">
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                        <?php if ($reviewed): ?>
                            <span class="verdict <?= $isError ? 'bad' : 'good' ?>">
                                <?= $isError ? 'Ошибка: ' . e($it['error_name']) . ' · −' . money($it['penalty_amount']) . ($it['occurrence'] > 1 ? ' (повтор №' . (int) $it['occurrence'] . ')' : '') : 'Корректно' ?>
                            </span>
                            <?php if ($isError && !empty($it['controller_comment'])): ?>
                                <span class="muted comment-saved">💬 <?= e($it['controller_comment']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
            <tr><td colspan="6" class="muted">В выборке нет анкет.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php if (!$batch['finished_at'] && $items): ?>
<form method="post" action="/inspect/finish" onsubmit="return confirm('Завершить проверку и разослать уведомления сотрудникам?')">
    <?= csrf_field() ?>
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <button class="btn btn-primary">Завершить проверку и уведомить сотрудников</button>
</form>
<?php endif; ?>
