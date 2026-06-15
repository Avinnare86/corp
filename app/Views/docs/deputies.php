<h1>Замещение в СЭД</h1>

<section class="panel">
    <h2 style="margin-top:0">Назначить замещение</h2>
    <p class="muted" style="margin-top:0">На время отсутствия сотрудника документы и задачи СЭД (подпись, согласование, ознакомление)
        в указанный период автоматически направляются замещающему.</p>
    <form method="post" action="/docs/deputies" class="form-inline" style="align-items:flex-end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Отсутствующий сотрудник
            <select name="user_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($users as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Замещающий (исполняет за него)
            <select name="deputy_id" required>
                <option value="">— выберите —</option>
                <?php foreach ($users as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['full_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>с<input type="date" name="deputy_from" required></label>
        <label>по<input type="date" name="deputy_to" required></label>
        <button class="btn btn-primary">Сохранить</button>
    </form>
</section>

<section class="panel">
    <h2>Действующие и назначенные замещения</h2>
    <table class="table">
        <thead><tr><th>Отсутствующий</th><th>Замещающий</th><th>Период</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['full_name']) ?> <span class="muted" style="font-size:.78rem"><?= e($r['position'] ?: '') ?></span></td>
                <td><?= e($r['deputy_name'] ?? '—') ?></td>
                <td class="muted"><?= e($r['deputy_from'] ?: '—') ?> — <?= e($r['deputy_to'] ?: '—') ?></td>
                <td>
                    <form method="post" action="/docs/deputies" onsubmit="return confirm('Снять замещение?')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="deputy_id" value="">
                        <button class="btn btn-mini btn-danger">Снять</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Замещения не назначены.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
