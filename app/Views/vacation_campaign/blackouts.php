<?php /** @var array $rows departments csrf; bool $isHr */ ?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign">← к кампании</a> &nbsp; Периоды, в которые отпуск
    запрещён (например, отчётность, пиковая загрузка). На запретные даты сотрудник не сможет записаться. Период без отдела —
    запрет для всей организации.</p>

<section class="panel">
    <h2 style="margin-top:0">Добавить запретный период</h2>
    <form method="post" action="/vacation-campaign/blackouts" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Отдел
            <select name="department_id" <?= empty($isHr) ? 'required' : '' ?>>
                <?php if (!empty($isHr)): ?><option value="">— вся организация —</option><?php endif; ?>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>С <input type="date" name="start_date" required></label>
        <label>По <input type="date" name="end_date" required></label>
        <label>Причина <input type="text" name="reason" maxlength="300" placeholder="напр. квартальный отчёт"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <h2>Действующие запреты</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Период</th><th>Охват</th><th>Причина</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?></td>
                <td data-label="Охват"><?= $r['emp_name'] ? e($r['emp_name']) : ($r['dept_name'] ? e($r['dept_name']) : 'вся организация') ?></td>
                <td data-label="Причина" class="muted"><?= e((string) ($r['reason'] ?? '')) ?></td>
                <td>
                    <form method="post" action="/vacation-campaign/blackouts/<?= (int) $r['id'] ?>/delete" onsubmit="return confirm('Удалить запрет?')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn-mini btn-danger">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Запретных периодов нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
