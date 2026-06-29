<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Ваши периоды отпуска по подписанному графику. Если периодов нет — график ещё не подписан
    или ваш отпуск в нём не запланирован; обратитесь к кадрам.</p>

<section class="panel">
    <table class="table tbl-cards">
        <thead><tr><th>Год</th><th>Период</th><th class="num">Дней</th><th>Охват</th><th>Подписан</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Год"><?= (int) $r['year'] ?></td>
                <td data-label="Период"><strong><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?></strong></td>
                <td data-label="Дней" class="num"><?= (int) $r['days'] ?></td>
                <td data-label="Охват" class="muted"><?= $r['dept_name'] ? e($r['dept_name']) : 'Организация в целом' ?></td>
                <td data-label="Подписан" class="muted"><?= e(substr((string) $r['signed_at'], 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Запланированных периодов нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
