<?php $isShift = ($kind ?? 'std') === 'shift'; $kq = $isShift ? '&kind=shift' : ''; ?>
<h1><?= $isShift ? 'Табель 0504421 (сменный 2/2)' : 'Электронный табель' ?></h1>

<div class="form-inline" style="gap:8px;margin-bottom:10px">
    <a class="btn btn-mini <?= $isShift ? '' : 'btn-primary' ?>" href="/timesheet2?month=<?= e($month) ?>&half=<?= e((string)$half) ?>">Стандартный (5/2)</a>
    <a class="btn btn-mini <?= $isShift ? 'btn-primary' : '' ?>" href="/timesheet2?kind=shift&month=<?= e($month) ?>&half=<?= e((string)$half) ?>">Сменный 0504421 (2/2)</a>
</div>

<section class="panel">
    <form method="get" action="/timesheet2" class="form-inline">
        <?php if ($isShift): ?><input type="hidden" name="kind" value="shift"><?php endif; ?>
        <label>Месяц<input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()"></label>
        <label>Половина
            <select name="half" onchange="this.form.submit()">
                <option value="1" <?= $half===1?'selected':'' ?>>1–15</option>
                <option value="2" <?= $half===2?'selected':'' ?>>16–конец</option>
            </select>
        </label>
    </form>

    <?php if ($canCreate): ?>
    <form method="post" action="/timesheet2/create" class="form-inline" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <?php if ($isShift): ?>
            <input type="hidden" name="kind" value="shift">
            <label>Отдел (2/2)
                <select name="department_id" required>
                    <option value="">— выберите отдел —</option>
                    <?php foreach ($shiftDepts as $d): if (!$scope['org'] && !in_array((int)$d['id'], $scope['depts'], true)) continue; ?>
                        <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary">+ Сформировать 0504421 из графика</button>
            <span class="muted">генерируется из сменного графика 2/2; день/ночь — автоматически</span>
        <?php else: ?>
            <label>Охват
                <select name="department_id">
                    <?php if ($scope['org']): ?><option value="">Вся организация</option><?php endif; ?>
                    <?php foreach ($departments as $d): if (!$scope['org'] && !in_array((int)$d['id'], $scope['depts'], true)) continue; ?>
                        <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary">+ Сформировать табель</button>
            <span class="muted">предзаполняется из явки и утверждённых отпусков; состав сотрудников можно сократить при редактировании</span>
        <?php endif; ?>
    </form>
    <?php if ($isShift && !$shiftDepts): ?><p class="muted" style="margin:6px 0 0">Нет отделов с сотрудниками на графике 2/2. Назначьте сотрудникам режим 2/2 в карточке.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Табели за период <?= e($period) ?></h2>
    <table class="table">
        <thead><tr><th>Охват</th><th>Ревизия</th><th>Статус</th><th>Составил</th><th>Подписал</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($tabels as $tb): ?>
            <tr>
                <td><strong><?= e($tb['dept_name'] ?: 'Вся организация') ?></strong></td>
                <td><?= (int)$tb['revision'] === 0 ? 'первичный' : 'корректировочный №' . (int)$tb['revision'] ?></td>
                <td><?= $tb['status']==='signed'
                    ? '<span class="st st-ok">Подписан (' . e($tb['sign_type']) . ')</span><br><span class="muted" style="font-size:.74rem">' . e(substr((string)$tb['signed_at'],0,16)) . '</span>'
                    : '<span class="st st-wait">Черновик</span>' ?></td>
                <td class="muted"><?= e($tb['creator']) ?></td>
                <td class="muted"><?= e($tb['signer'] ?? '—') ?></td>
                <td>
                    <?php if ($tb['status']==='draft'): ?>
                        <a class="btn btn-mini btn-primary" href="/timesheet2/<?= (int)$tb['id'] ?>/edit"><?= $isShift ? 'Предпросмотр / подписать' : 'Редактировать / подписать' ?></a>
                        <form method="post" action="/timesheet2/<?= (int)$tb['id'] ?>/delete" class="inline" onsubmit="return confirm('Удалить черновик?')">
                            <?= csrf_field() ?><button class="btn btn-mini btn-danger">×</button></form>
                    <?php else: ?>
                        <a class="btn btn-mini btn-primary" href="/timesheet2/<?= (int)$tb['id'] ?>/view">📄 PDF-вид</a>
                        <a class="btn btn-mini" href="/timesheet2/<?= (int)$tb['id'] ?>/export">Excel</a>
                        <?php if ($canCreate): ?>
                        <form method="post" action="/timesheet2/create" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="period" value="<?= e($period) ?>">
                            <input type="hidden" name="department_id" value="<?= e($tb['department_id']) ?>">
                            <?php if ($isShift): ?><input type="hidden" name="kind" value="shift"><?php endif; ?>
                            <button class="btn btn-mini" onclick="return confirm('Создать корректировочный табель?')">↻ Корректировочный</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$tabels): ?><tr><td colspan="6" class="muted">Табелей за период нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
