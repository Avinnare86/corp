<?php /** @var array $emps year bal camp csrf */ ?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign?year=<?= (int) $year ?>">← к кампании</a> &nbsp; Остаток отпуска (календарных дней)
    на <?= (int) $year ?> год по каждому сотруднику. Сотрудник сможет распределить только в пределах этого остатка.
    По умолчанию — 28 дней.</p>

<?php if ($camp && $camp['stage'] !== 'balances'): ?>
    <p class="tag warn">Этап сбора остатков уже завершён — правки остатков повлияют только на будущие проверки.</p>
<?php endif; ?>

<form method="post" action="/vacation-campaign/save-balances?year=<?= (int) $year ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <section class="panel">
        <table class="table tbl-cards">
            <thead><tr><th>Сотрудник</th><th>Должность</th><th>Остаток, дн.</th></tr></thead>
            <tbody>
            <?php foreach ($emps as $em): $id = (int) $em['id']; ?>
                <tr>
                    <td data-label="Сотрудник"><?= e($em['full_name']) ?></td>
                    <td data-label="Должность" class="muted"><?= e((string) ($em['position'] ?? '')) ?></td>
                    <td data-label="Остаток"><input type="number" name="days[<?= $id ?>]" min="0" max="120" value="<?= (int) ($bal[$id] ?? 28) ?>" style="width:80px"></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$emps): ?><tr><td colspan="3" class="muted">Нет активных сотрудников.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить остатки</button>
    </section>
</form>
