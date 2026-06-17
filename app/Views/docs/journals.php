<?php $dirL = ['' => '— любое —', 'incoming' => 'входящие', 'outgoing' => 'исходящие', 'internal' => 'внутренние']; ?>
<h1>Журналы регистрации</h1>

<section class="panel">
    <h2 style="margin-top:0">Журналы</h2>
    <table class="table">
        <thead><tr><th>Название</th><th>Направление</th><th>Префикс</th><th>Индекс дела</th><th class="num">Документов</th><th>Активен</th><th>Бронь</th></tr></thead>
        <tbody>
        <?php foreach ($journals as $j): ?>
            <tr>
                <td><?= e($j['name']) ?></td>
                <td class="muted"><?= e($dirL[$j['direction']] ?? $j['direction']) ?></td>
                <td class="mono"><?= e($j['prefix']) ?></td>
                <td class="mono"><?= e($j['index_code']) ?></td>
                <td class="num"><?= (int)$j['docs'] ?></td>
                <td><?= (int)$j['is_active'] ? '✓' : '—' ?></td>
                <td>
                    <form method="post" action="/docs/journals/<?= (int)$j['id'] ?>/reserve" class="inline">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn-mini" title="Забронировать следующий номер">+ бронь</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$journals): ?><tr><td colspan="7" class="muted">Журналов нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2 style="margin-top:0">Добавить журнал</h2>
    <form method="post" action="/docs/journals" class="form-inline" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Название<input type="text" name="name" required></label>
        <label>Направление
            <select name="direction">
                <?php foreach ($dirL as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Префикс<input type="text" name="prefix" style="max-width:90px" placeholder="Вх"></label>
        <label>Индекс дела<input type="text" name="index_code" style="max-width:110px" placeholder="01-15"></label>
        <button class="btn btn-primary">Добавить</button>
    </form>
    <p class="muted" style="margin:6px 0 0">Формат номера: при заданном индексе — <code>индекс/N-ГГГГ</code>, иначе <code>префикс-N/ГГ</code>. Счётчик N — по журналу и году.</p>
</section>

<?php if (!empty($reservations)): ?>
<section class="panel">
    <h2 style="margin-top:0">Забронированные номера (свободные)</h2>
    <table class="table">
        <thead><tr><th>Рег.№</th><th>Журнал</th><th>Кто</th><th>Когда</th><th>Примечание</th></tr></thead>
        <tbody>
        <?php foreach ($reservations as $r): ?>
            <tr><td class="mono"><?= e($r['reg_number']) ?></td><td class="muted"><?= e($r['jname']) ?></td>
                <td><?= e($r['by_name'] ?: '—') ?></td><td class="muted"><?= e(substr((string)$r['reserved_at'],0,16)) ?></td>
                <td class="muted"><?= e($r['note']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted">Свободную бронь можно использовать при ручной регистрации документа.</p>
</section>
<?php endif; ?>
