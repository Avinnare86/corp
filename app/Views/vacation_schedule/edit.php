<?php use App\Services\VacationScheduleService as VS; ?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">
    <span class="tag">черновик</span> Ревизия: <?= (int) $s['revision'] === 0 ? 'основной' : 'корректировочный № ' . (int) $s['revision'] ?>.
    Охват: <strong><?= e($scope) ?></strong>. Год: <strong><?= (int) $s['year'] ?></strong>.
    <a href="/vacation-schedule">← к списку</a>
</p>

<?php if ($check['ok']): ?>
    <div class="panel" style="border-left:4px solid #1a7f37"><strong>✓ Готов к подписи:</strong> у всех сотрудников распределён весь остаток и есть часть ≥ <?= (int) $minWd ?> рабочих дней.</div>
<?php else: ?>
    <div class="panel" style="border-left:4px solid #b00020">
        <strong>Ещё нельзя согласовать/подписать.</strong> Не у всех сотрудников выполнены правила (см. колонку «Состояние»).
    </div>
<?php endif; ?>

<section class="panel">
    <h2 style="margin-top:0">Сотрудники: остаток, план, правила</h2>
    <table class="table tbl-cards">
        <thead><tr>
            <th>Сотрудник</th><th class="num">Остаток, дн.</th><th class="num">Запланировано</th>
            <th class="num">Наиб. часть, раб.дн.</th><th>Состояние</th><th>Согласование</th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $emp):
            $id = (int) $emp['id'];
            $c  = $check['byEmp'][$id] ?? ['planned' => 0, 'balance' => (int) ($balances[$id] ?? VS::DEFAULT_BALANCE), 'longestWd' => 0, 'issues' => []];
            $myRows = $byEmp[$id] ?? [];
            $approvedAll = $myRows && !array_filter($myRows, fn($r) => $r['status'] !== VS::ROW_APPROVED);
        ?>
            <tr>
                <td data-label="Сотрудник"><strong><?= e($emp['full_name']) ?></strong><br><span class="muted" style="font-size:.8rem"><?= e($emp['position'] ?? '') ?></span></td>
                <td data-label="Остаток" class="num">
                    <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/balance" style="display:flex;gap:4px;justify-content:flex-end;align-items:center">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="employee_id" value="<?= $id ?>">
                        <input type="number" name="days" value="<?= (int) $c['balance'] ?>" min="0" max="60" style="width:64px;text-align:right">
                        <button class="btn btn-mini" title="Сохранить остаток">💾</button>
                    </form>
                </td>
                <td data-label="Запланировано" class="num"><?= (int) $c['planned'] ?></td>
                <td data-label="Наиб. часть" class="num"><?= (int) $c['longestWd'] ?></td>
                <td data-label="Состояние">
                    <?php if (!$c['issues']): ?>
                        <span class="tag ok">правила выполнены</span>
                    <?php else: foreach ($c['issues'] as $iss): ?>
                        <div style="color:#b00020;font-size:.82rem">• <?= e($iss) ?></div>
                    <?php endforeach; endif; ?>
                </td>
                <td data-label="Согласование">
                    <?php if ($approvedAll): ?>
                        <span class="tag ok">Согласован</span>
                        <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/row-status" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="employee_id" value="<?= $id ?>"><input type="hidden" name="to" value="proposal">
                            <button class="btn btn-mini">вернуть</button>
                        </form>
                    <?php elseif ($myRows): ?>
                        <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/row-status" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="employee_id" value="<?= $id ?>"><input type="hidden" name="to" value="approved">
                            <button class="btn btn-mini btn-primary" <?= $c['issues'] ? 'disabled title="Сначала выполните правила"' : '' ?>>Согласовать</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">нет периодов</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?><tr><td colspan="6" class="muted">В охвате нет активных сотрудников.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Периоды отпуска</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Сотрудник</th><th>Период</th><th class="num">Календ.</th><th class="num">Раб.</th><th>Статус</th><th></th></tr></thead>
        <tbody>
        <?php $rowsAll = []; foreach ($byEmp as $rs) foreach ($rs as $r) $rowsAll[] = $r;
              usort($rowsAll, fn($a, $b) => [$a['full_name'], $a['start_date']] <=> [$b['full_name'], $b['start_date']]);
        foreach ($rowsAll as $r): ?>
            <tr>
                <td data-label="Сотрудник"><?= e($r['full_name']) ?></td>
                <td data-label="Период"><?= e(date('d.m.Y', strtotime($r['start_date']))) ?> — <?= e(date('d.m.Y', strtotime($r['end_date']))) ?></td>
                <td data-label="Календ." class="num"><?= (int) $r['days'] ?></td>
                <td data-label="Раб." class="num"><?= VS::workingDaysBetween($r['start_date'], $r['end_date']) ?></td>
                <td data-label="Статус"><?= $r['status'] === VS::ROW_APPROVED ? '<span class="tag ok">Согласован</span>' : '<span class="tag">Предложение</span>' ?></td>
                <td><form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/row/<?= (int) $r['id'] ?>/delete" onsubmit="return confirm('Удалить период?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rowsAll): ?><tr><td colspan="6" class="muted">Периоды не добавлены.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3>Добавить период</h3>
    <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/row" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Сотрудник
            <select name="employee_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>С<input type="date" name="start_date" required></label>
        <label>По<input type="date" name="end_date" required></label>
        <label class="grow">Примечание<input type="text" name="note" maxlength="300"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <h2>Подписание графика</h2>
    <?php if (!$check['ok']): ?>
        <p class="muted">Подпись станет доступна после выполнения правил по всем сотрудникам и согласования всех периодов.</p>
    <?php endif; ?>
    <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/sign" class="grid-form"
          onsubmit="return confirm('Подписать график? После подписи изменения вносятся только новой ревизией.')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Вид подписи
            <select name="sign_type">
                <?php foreach ($signTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="grow">Пароль<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" <?= $check['ok'] ? '' : 'disabled' ?>>Подписать</button>
    </form>
    <p class="muted" style="font-size:.85rem">ПЭП/УНЭП — пароль учётной записи. УКЭП — пароль сервиса подписи (если он подключён администратором).</p>

    <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/archive" onsubmit="return confirm('Переместить черновик в архив?')">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn">В архив</button>
        </form>
        <?php if (\App\Core\Auth::effectiveHas('admin')): ?>
        <form method="post" action="/vacation-schedule/<?= (int) $s['id'] ?>/delete" onsubmit="return confirm('Безвозвратно удалить черновик?')">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-danger">Удалить черновик</button>
        </form>
        <?php endif; ?>
    </div>
</section>
