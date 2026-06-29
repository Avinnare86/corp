<?php
$fmt = fn($c) => number_format((float) $c, 2, ',', ' ');
$dt  = fn($s) => $s ? date('d.m.Y', strtotime($s)) : '';
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Повышающий коэффициент к базовому тарифу проверки анкет: эффективная цена анкеты =
    <strong>базовый тариф страны × коэффициент дня проверки</strong>. Диапазон <?= $fmt($min) ?>–<?= $fmt($max) ?>.
    Коэффициент применяется ко всем суммам сделки/бюджета за соответствующий день. Менеджер задаёт день в день или за
    прошлый рабочий день; администратор — за любой день.</p>

<section class="panel">
    <h2 style="margin-top:0">Быстрая установка</h2>
    <div style="display:flex;gap:24px;flex-wrap:wrap">
        <form method="post" action="/tariff-coeff/save" class="grid-form" style="align-items:flex-end">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="work_date" value="<?= e($today) ?>">
            <label>Сегодня (<?= $dt($today) ?>)
                <input type="number" name="coefficient" step="0.05" min="<?= $fmt($min) ?>" max="<?= $fmt($max) ?>" value="<?= rtrim(rtrim(number_format($todayCoeff, 2, '.', ''), '0'), '.') ?>">
            </label>
            <button class="btn btn-primary">Сохранить</button>
        </form>
        <form method="post" action="/tariff-coeff/save" class="grid-form" style="align-items:flex-end">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="work_date" value="<?= e($prevWd) ?>">
            <label>Прошлый рабочий день (<?= $dt($prevWd) ?>)
                <input type="number" name="coefficient" step="0.05" min="<?= $fmt($min) ?>" max="<?= $fmt($max) ?>" value="<?= rtrim(rtrim(number_format($prevCoeff, 2, '.', ''), '0'), '.') ?>">
            </label>
            <button class="btn btn-primary">Сохранить</button>
        </form>
    </div>
</section>

<?php if (!empty($isAdmin)): ?>
<section class="panel" style="border-left:4px solid #b00020">
    <h2 style="margin-top:0">Администратор: за любой день</h2>
    <form method="post" action="/tariff-coeff/save" class="grid-form" style="align-items:flex-end">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Дата<input type="date" name="work_date" value="<?= e($today) ?>" required></label>
        <label>Коэффициент<input type="number" name="coefficient" step="0.05" min="<?= $fmt($min) ?>" max="<?= $fmt($max) ?>" value="1" required></label>
        <button class="btn btn-danger">Установить за выбранный день</button>
    </form>
    <p class="muted" style="margin:8px 0 0;font-size:.85rem">Изменение коэффициента за прошедший день пересчитает сделку/бюджет за этот день.</p>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Заданные коэффициенты</h2>
    <table class="table tbl-cards">
        <thead><tr><th>Дата</th><th class="num">Коэффициент</th><th>Кто установил</th><th>Когда</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Дата"><?= $dt($r['work_date']) ?></td>
                <td data-label="Коэффициент" class="num"><?= $fmt($r['coefficient']) ?><?= (float) $r['coefficient'] > 1 ? ' <span class="tag">+' . $fmt(((float) $r['coefficient'] - 1) * 100) . '%</span>' : '' ?></td>
                <td data-label="Кто" class="muted"><?= e($r['by_name'] ?? '') ?></td>
                <td data-label="Когда" class="muted"><?= e(substr((string) $r['set_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Коэффициенты не задавались — действует базовый тариф (×1.0).</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
