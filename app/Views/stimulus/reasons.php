<h1>Справочник оснований стимула</h1>
<p class="muted" style="margin-top:0">Свободные основания «за что доплата» — отдельно от формальных оснований Положения.
    Помогают начальнику и бухгалтерии вспомнить назначение каждой стимулирующей выплаты.
    <?php if ($canAll): ?>Вы можете вести основания по любому отделу и общие (для всех).
    <?php else: ?>Вы ведёте основания своего отдела<?= $myDept ? ' — <strong>' . e($myDept['name']) . '</strong>' : '' ?>. Общие основания — только для просмотра.<?php endif; ?>
</p>

<section class="panel">
    <h2>Добавить основание</h2>
    <form method="post" action="/memos/reasons" class="form-inline">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="grow">Текст основания<input type="text" name="text" required placeholder="За наставничество новых сотрудников…"></label>
        <?php if ($canAll): ?>
        <label>Отдел
            <select name="department_id">
                <option value="">— общие (для всех) —</option>
                <?php foreach ($depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <button class="btn btn-primary">Добавить</button>
    </form>
</section>

<section class="panel">
    <h2>Перечень</h2>
    <table class="table">
        <thead><tr><th>Основание</th><th>Отдел</th><th>Активно</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reasons as $r):
            $isGlobal = $r['department_id'] === null;
            $editable = $canAll || (!$isGlobal && (int)$r['department_id'] === (int)$myDeptId);
        ?>
            <tr<?= (int)$r['is_active'] ? '' : ' class="emp-off"' ?>>
                <?php if ($editable): ?>
                <td colspan="4">
                    <form method="post" action="/memos/reasons" class="row-form">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="text" name="text" value="<?= e($r['text']) ?>" style="min-width:340px">
                        <?php if ($canAll): ?>
                        <select name="department_id">
                            <option value="" <?= $isGlobal?'selected':'' ?>>— общие —</option>
                            <?php foreach ($depts as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)($r['department_id']??0)===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="department_id" value="<?= (int)$myDeptId ?>">
                        <span class="muted"><?= e($r['dept_name'] ?? 'свой отдел') ?></span>
                        <?php endif; ?>
                        <label class="chk"><input type="checkbox" name="is_active" value="1" <?= (int)$r['is_active']?'checked':'' ?>> актив.</label>
                        <button class="btn btn-mini btn-primary">Сохранить</button>
                        <?php if ((int)$r['is_active']): ?>
                        <button type="submit" class="btn btn-mini btn-danger" formaction="/memos/reasons/<?= (int)$r['id'] ?>/delete" onclick="return confirm('Отключить основание?')">×</button>
                        <?php endif; ?>
                    </form>
                </td>
                <?php else: ?>
                <td><?= e($r['text']) ?></td>
                <td class="muted"><?= $isGlobal ? 'общие' : e($r['dept_name'] ?? '') ?></td>
                <td><?= (int)$r['is_active'] ? 'да' : 'нет' ?></td>
                <td class="muted">только просмотр</td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$reasons): ?>
            <tr><td colspan="4" class="muted">Оснований пока нет. Добавьте первое выше.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
