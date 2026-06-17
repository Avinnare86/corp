<h1>Регистрация документа</h1>
<p class="muted">Ручная регистрация входящего/исходящего/внутреннего документа без маршрута согласования — присваивается рег.№ и дата регистрации.</p>

<section class="panel">
    <form method="post" action="/docs/register" enctype="multipart/form-data" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Направление
            <select name="direction" id="rfDir" onchange="document.getElementById('rfCorr').style.display=this.value==='internal'?'none':''">
                <option value="incoming">Входящий</option>
                <option value="outgoing">Исходящий</option>
                <option value="internal">Внутренний</option>
            </select>
        </label>
        <label>Журнал регистрации
            <select name="journal_id">
                <option value="">— по типу документа —</option>
                <?php foreach ($journals as $j): ?><option value="<?= (int)$j['id'] ?>"><?= e($j['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Тип документа
            <select name="type_id" required>
                <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Дата регистрации<input type="date" name="reg_date" value="<?= e(date('Y-m-d')) ?>"></label>
        <label style="grid-column:1/-1">Заголовок<input type="text" name="title" required></label>
        <div id="rfCorr" style="grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <label>Корреспондент (от кого / кому)
                <input type="text" name="correspondent_name" list="rfCorrList" placeholder="организация или гражданин">
                <datalist id="rfCorrList"><?php foreach ($correspondents as $co): ?><option value="<?= e($co['name']) ?>"></option><?php endforeach; ?></datalist>
            </label>
            <label>Входящий/исходящий № корреспондента<input type="text" name="incoming_number"></label>
            <label>Дата документа корреспондента<input type="date" name="incoming_date"></label>
            <label>Способ доставки<input type="text" name="delivery" placeholder="почта, эл. почта, нарочно…"></label>
        </div>
        <label style="grid-column:1/-1">Краткое содержание<textarea name="body" rows="3"></textarea></label>
        <?php if (!empty($reservations)): ?>
        <label>Использовать забронированный №
            <select name="reservation_id">
                <option value="">— нет, присвоить следующий —</option>
                <?php foreach ($reservations as $rv): ?><option value="<?= (int)$rv['id'] ?>"><?= e($rv['reg_number']) ?> (<?= e($rv['jname']) ?>)</option><?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Ручной рег.№ (необязательно)<input type="text" name="manual_no" placeholder="оставьте пустым для авто"></label>
        <label class="file-btn" style="align-self:flex-end">📎 Файл документа<input type="file" name="file"></label>
        <div style="grid-column:1/-1">
            <button class="btn btn-primary">Зарегистрировать</button>
            <a class="btn" href="/docs">Отмена</a>
        </div>
    </form>
</section>
