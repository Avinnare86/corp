<?php
/** Единый блок «Электронный табель»: оба вида (обычный 5/2 и сменный 2/2) — один список и одно
 *  создание с выбором вида. 2/2 формируется из графика, 5/2 — по презумпции явки. */
$kindLabel = fn($k) => ($k ?? 'std') === 'shift' ? 'Сменный 2/2' : 'Обычный 5/2';
$hasShiftDepts = !empty($shiftDepts);
?>
<h1>Электронный табель</h1>

<section class="panel">
    <form method="get" action="/timesheet2" class="form-inline">
        <label>Месяц<input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()"></label>
        <label>Половина
            <select name="half" onchange="this.form.submit()">
                <option value="1" <?= $half===1?'selected':'' ?>>1–15</option>
                <option value="2" <?= $half===2?'selected':'' ?>>16–конец</option>
            </select>
        </label>
        <a class="btn btn-mini" href="/timesheet2/coverage?month=<?= e($month) ?>&half=<?= e((string)$half) ?>">📊 Покрытие</a>
    </form>

    <?php if ($canCreate): ?>
    <form method="post" action="/timesheet2/create" class="form-inline" style="margin-top:12px;align-items:flex-end;flex-wrap:wrap;gap:14px" id="create-form">
        <?= csrf_field() ?>
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <fieldset style="border:1px solid #d4d8e3;border-radius:8px;padding:6px 12px;margin:0">
            <legend class="muted" style="font-size:.78rem;padding:0 4px">Вид табеля</legend>
            <label class="form-inline" style="gap:5px;margin:0"><input type="radio" name="kind" value="std" checked onchange="tabelKind()"> Обычный (5/2)</label>
            <label class="form-inline" style="gap:5px;margin:0"><input type="radio" name="kind" value="shift" onchange="tabelKind()" <?= $hasShiftDepts ? '' : 'disabled' ?>> Сменный (2/2)</label>
        </fieldset>

        <label id="scope-std">Охват
            <select name="department_id" id="sel-std">
                <?php if ($scope['org']): ?><option value="">Вся организация</option><?php endif; ?>
                <?php foreach ($departments as $d): if (!$scope['org'] && !in_array((int)$d['id'], $scope['depts'], true)) continue; ?>
                    <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label id="scope-shift" style="display:none">Отдел (2/2)
            <select name="department_id" id="sel-shift" disabled>
                <option value="">— выберите отдел —</option>
                <?php foreach ($shiftDepts as $d): if (!$scope['org'] && !in_array((int)$d['id'], $scope['depts'], true)) continue; ?>
                    <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button class="btn btn-primary">+ Сформировать табель</button>
        <span class="muted" id="hint-std">5/2 — предзаполнение по презумпции явки (Я/В/О), отметьте отсутствия.</span>
        <span class="muted" id="hint-shift" style="display:none">2/2 — генерируется из сменного графика; день/ночь — автоматически.</span>
    </form>
    <?php if (!$hasShiftDepts): ?><p class="muted" style="margin:6px 0 0">Сменный (2/2): нет отделов с сотрудниками на графике 2/2 — назначьте режим 2/2 в карточке сотрудника.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Табели за период <?= e($period) ?></h2>
    <table class="table">
        <thead><tr><th>Вид</th><th>Охват</th><th>Ревизия</th><th>Статус</th><th>Составил</th><th>Подписал</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($tabels as $tb): $isShift = ($tb['kind'] ?? 'std') === 'shift'; ?>
            <tr>
                <td><span class="tag <?= $isShift ? 'tag-shift' : '' ?>"><?= $kindLabel($tb['kind'] ?? 'std') ?></span></td>
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
                            <input type="hidden" name="kind" value="<?= $isShift ? 'shift' : 'std' ?>">
                            <button class="btn btn-mini" onclick="return confirm('Создать корректировочный табель?')">↻ Корректировочный</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$tabels): ?><tr><td colspan="7" class="muted">Табелей за период нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<style>.tag-shift{background:#2b6cb0;color:#fff}</style>
<script>
function tabelKind() {
  var shift = document.querySelector('#create-form input[name=kind][value=shift]').checked;
  document.getElementById('scope-std').style.display  = shift ? 'none' : '';
  document.getElementById('scope-shift').style.display = shift ? '' : 'none';
  document.getElementById('hint-std').style.display    = shift ? 'none' : '';
  document.getElementById('hint-shift').style.display  = shift ? '' : 'none';
  document.getElementById('sel-std').disabled   = shift;       // отключаем скрытый select, чтобы не слать оба department_id
  document.getElementById('sel-shift').disabled = !shift;
}
</script>
