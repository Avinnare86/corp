<h1>Замещение и И.о./ВРИО</h1>

<section class="panel">
    <p class="muted" style="margin-top:0">Назначение исполняющих обязанности на период. И.о./ВРИО получает <strong>полные права замещаемого</strong>
        и может переключаться между своей работой и работой как И.о. (переключатель в шапке портала справа).
        Назначать может сам замещаемый, его начальник по структуре, любой вышестоящий руководитель и администратор.</p>
    <form method="post" action="/acting/save" class="form-inline" style="align-items:flex-end;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Замещаемый
            <select name="absent_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($absentChoices as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?><?= $u['position']?' — '.e($u['position']):'' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Исполняющий обязанности
            <select name="acting_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($allUsers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?><?= $u['position']?' — '.e($u['position']):'' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Вид
            <select name="kind">
                <option value="io">И.о. (исполняющий обязанности)</option>
                <option value="vrio">ВРИО (временно исполняющий)</option>
            </select>
        </label>
        <label>С<input type="date" name="date_from" required></label>
        <label>По<input type="date" name="date_to" required></label>
        <label style="flex:1;min-width:200px">Основание (необязательно)<input type="text" name="reason" placeholder="напр. отпуск, командировка"></label>
        <button class="btn btn-primary">Назначить</button>
    </form>
</section>

<section class="panel">
    <h2>Действующие назначения</h2>
    <table class="table">
        <thead><tr><th>Замещаемый</th><th>И.о./ВРИО</th><th>Вид</th><th>Период</th><th>Основание</th><th>Кто назначил</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): ?>
            <tr>
                <td><?= e($a['absent_name']) ?><?= $a['absent_pos']?' <span class="muted" style="font-size:.78rem">'.e($a['absent_pos']).'</span>':'' ?></td>
                <td><?= e($a['acting_name']) ?></td>
                <td><?= $a['kind']==='vrio'?'ВРИО':'И.о.' ?></td>
                <td style="white-space:nowrap"><?= e($a['date_from']) ?> — <?= e($a['date_to']) ?></td>
                <td><?= e($a['reason'] ?? '') ?></td>
                <td class="muted"><?= e($a['creator_name'] ?? '') ?></td>
                <td>
                    <form method="post" action="/acting/<?= (int)$a['id'] ?>/cancel" onsubmit="return confirm('Отменить это назначение?')" style="margin:0">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn-mini btn-danger">Отменить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?><tr><td colspan="7" class="muted">Активных назначений нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
