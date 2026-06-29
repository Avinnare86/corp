<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">График отпусков формируется как документ: по отделу или по организации в целом, основной и
    корректировочные ревизии. После подписания изменения вносятся новой ревизией. Согласовать график можно, только когда у каждого
    сотрудника распределён весь остаток отпуска и есть часть не короче 10 рабочих дней.</p>

<section class="panel">
    <h2 style="margin-top:0">Сформировать график</h2>
    <form method="post" action="/vacation-schedule" class="grid-form" id="vsCreate">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Год
            <select name="year">
                <option value="<?= $nextYear ?>"><?= $nextYear ?></option>
                <option value="<?= $curYear ?>"><?= $curYear ?></option>
            </select>
        </label>
        <label>Охват
            <select name="scope" id="vsScope">
                <option value="org">Организация в целом</option>
                <option value="dept">Отдел</option>
            </select>
        </label>
        <label id="vsDeptWrap" style="display:none">Отдел
            <select name="department_id">
                <option value="">— выберите —</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Создать график</button>
    </form>
    <p class="muted" style="margin:8px 0 0;font-size:.85rem">Новый график предзаполняется утверждёнными заявками на отпуск этого года
        (если они есть) — их можно скорректировать.</p>
</section>

<section class="panel">
    <h2>Актуальные</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Год</th><th>Охват</th><th>Ревизия</th><th>Статус</th><th>Создан</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($actual as $s): ?>
            <tr>
                <td data-label="Год"><?= (int) $s['year'] ?></td>
                <td data-label="Охват"><?= $s['department_id'] === null ? 'Организация в целом' : e($s['dept_name']) ?></td>
                <td data-label="Ревизия"><?= (int) $s['revision'] === 0 ? 'основной' : 'корр. ' . (int) $s['revision'] ?></td>
                <td data-label="Статус"><?= $s['status'] === 'signed' ? '<span class="tag ok">подписан</span>' : '<span class="tag">черновик</span>' ?></td>
                <td data-label="Создан" class="muted"><?= e(substr((string) $s['created_at'], 0, 10)) ?></td>
                <td>
                    <?php if ($s['status'] === 'signed'): ?>
                        <a class="btn btn-mini" href="/vacation-schedule/<?= (int) $s['id'] ?>/view">Открыть</a>
                    <?php else: ?>
                        <a class="btn btn-mini btn-primary" href="/vacation-schedule/<?= (int) $s['id'] ?>/edit">Заполнить</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$actual): ?><tr><td colspan="6" class="muted">Графиков пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($archive): ?>
<section class="panel">
    <h2>Архив</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Год</th><th>Охват</th><th>Ревизия</th><th>Статус</th><th>В архиве с</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($archive as $s): ?>
            <tr>
                <td data-label="Год"><?= (int) $s['year'] ?></td>
                <td data-label="Охват"><?= $s['department_id'] === null ? 'Организация в целом' : e($s['dept_name']) ?></td>
                <td data-label="Ревизия"><?= (int) $s['revision'] === 0 ? 'основной' : 'корр. ' . (int) $s['revision'] ?></td>
                <td data-label="Статус"><?= $s['status'] === 'signed' ? '<span class="tag ok">подписан</span>' : '<span class="tag">черновик</span>' ?></td>
                <td data-label="В архиве с" class="muted"><?= e(substr((string) $s['archived_at'], 0, 10)) ?></td>
                <td><a class="btn btn-mini" href="/vacation-schedule/<?= (int) $s['id'] ?>/view">Открыть</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<script>
(function () {
    var scope = document.getElementById('vsScope'), wrap = document.getElementById('vsDeptWrap');
    if (!scope) return;
    function sync() { wrap.style.display = scope.value === 'dept' ? '' : 'none'; }
    scope.addEventListener('change', sync); sync();
})();
</script>
