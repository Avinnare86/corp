<?php
// Справочники линий прибытия: ЛП (сокращение, напр. ПП = План приема) и ДЛП (детализированная линия).
// Управляет менеджер проекта квота. Есть отключение, объединение дублей.
$activeLines = array_values(array_filter($lines, fn($l) => (int)$l['is_active'] === 1));
$activeDetails = array_values(array_filter($details, fn($d) => (int)$d['is_active'] === 1));
?>
<div class="chat-head">
    <a class="btn btn-mini" href="/manager">← Распределение</a>
    <h1 style="margin:0;font-size:1.2rem">Линии прибытия — справочники</h1>
</div>
<p class="muted">Линия прибытия анкеты складывается из <strong>ЛП</strong> (сокращение, напр. <b>ПП</b> — План приема) и
    <strong>ДЛП</strong> (детализированная линия). В меню показывается как «ЛП/ДЛП», напр. <code>ПП/У ШОС (Министерства образования КНР)</code>.
    При загрузке файла «детализированного Плана приема» ЛП проставляется автоматически (ПП), ДЛП — по заголовкам секций.</p>

<div class="doc-grid">
<!-- ===================== ЛП ===================== -->
<div>
<section class="panel">
    <h2>Линия прибытия (ЛП)</h2>
    <form method="post" action="/manager/arrival/line" class="form-inline" style="align-items:flex-end;gap:8px">
        <?= csrf_field() ?>
        <label>Сокращение<input type="text" name="code" placeholder="ПП" style="max-width:90px" required></label>
        <label>Полное название<input type="text" name="name" placeholder="План приема" style="max-width:220px"></label>
        <button class="btn btn-primary">Добавить ЛП</button>
    </form>
    <table class="table" style="margin-top:10px">
        <thead><tr><th>Код</th><th>Название</th><th class="num">Анкет</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $on = (int)$l['is_active'] === 1; ?>
            <tr class="<?= $on ? '' : 'emp-off' ?>">
                <td colspan="4" style="padding:6px 8px">
                    <form method="post" action="/manager/arrival/line" class="form-inline" style="gap:6px;align-items:center;flex-wrap:wrap">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <input type="text" name="code" value="<?= e($l['code']) ?>" style="max-width:90px">
                        <input type="text" name="name" value="<?= e($l['name']) ?>" style="max-width:220px">
                        <label class="chk" style="margin:0"><input type="checkbox" name="is_active" value="1" <?= $on ? 'checked' : '' ?>> активна</label>
                        <span class="muted">исп.: <?= (int)$l['uses'] ?></span>
                        <button class="btn btn-mini">💾</button>
                        <?php if ($on): ?><button class="btn btn-mini btn-danger" formaction="/manager/arrival/line/<?= (int)$l['id'] ?>/delete" onclick="return confirm('Отключить ЛП?')">Отключить</button><?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?><tr><td colspan="4" class="muted">Пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if (count($activeLines) >= 2): ?>
    <details style="margin-top:8px"><summary class="muted" style="cursor:pointer">Объединить дубли ЛП…</summary>
        <form method="post" action="/manager/arrival/line/merge" class="form-inline" style="align-items:flex-end;gap:8px;margin-top:8px"
              onsubmit="return confirm('Перенести все анкеты источника на цель и удалить источник?')">
            <?= csrf_field() ?>
            <label>Источник (удалится)<select name="source_id" required><option value="">—</option>
                <?php foreach ($activeLines as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['code']) ?> (<?= (int)$l['uses'] ?>)</option><?php endforeach; ?></select></label>
            <label>Цель<select name="target_id" required><option value="">—</option>
                <?php foreach ($activeLines as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['code']) ?> (<?= (int)$l['uses'] ?>)</option><?php endforeach; ?></select></label>
            <button class="btn">Объединить</button>
        </form>
    </details>
    <?php endif; ?>
</section>
</div>

<!-- ===================== ДЛП ===================== -->
<div>
<section class="panel">
    <h2>Детализированная линия (ДЛП)</h2>
    <form method="post" action="/manager/arrival/detail" class="form-inline" style="align-items:flex-end;gap:8px">
        <?= csrf_field() ?>
        <label style="flex:1">Текст<input type="text" name="text" placeholder="У ШОС (Министерства образования КНР)" style="width:100%" required></label>
        <button class="btn btn-primary">Добавить ДЛП</button>
    </form>
    <table class="table" style="margin-top:10px">
        <thead><tr><th>Текст</th><th class="num">Анкет</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($details as $d): $on = (int)$d['is_active'] === 1; ?>
            <tr class="<?= $on ? '' : 'emp-off' ?>">
                <td colspan="3" style="padding:6px 8px">
                    <form method="post" action="/manager/arrival/detail" class="form-inline" style="gap:6px;align-items:center;flex-wrap:wrap">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <input type="text" name="text" value="<?= e($d['text']) ?>" style="min-width:280px;flex:1">
                        <label class="chk" style="margin:0"><input type="checkbox" name="is_active" value="1" <?= $on ? 'checked' : '' ?>> активна</label>
                        <span class="muted">исп.: <?= (int)$d['uses'] ?></span>
                        <button class="btn btn-mini">💾</button>
                        <?php if ($on): ?><button class="btn btn-mini btn-danger" formaction="/manager/arrival/detail/<?= (int)$d['id'] ?>/delete" onclick="return confirm('Отключить ДЛП?')">Отключить</button><?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$details): ?><tr><td colspan="3" class="muted">Пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if (count($activeDetails) >= 2): ?>
    <details style="margin-top:8px"><summary class="muted" style="cursor:pointer">Объединить дубли ДЛП…</summary>
        <form method="post" action="/manager/arrival/detail/merge" class="form-inline" style="align-items:flex-end;gap:8px;margin-top:8px"
              onsubmit="return confirm('Перенести все анкеты источника на цель и удалить источник?')">
            <?= csrf_field() ?>
            <label>Источник (удалится)<select name="source_id" required><option value="">—</option>
                <?php foreach ($activeDetails as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e(mb_substr($d['text'],0,40)) ?> (<?= (int)$d['uses'] ?>)</option><?php endforeach; ?></select></label>
            <label>Цель<select name="target_id" required><option value="">—</option>
                <?php foreach ($activeDetails as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e(mb_substr($d['text'],0,40)) ?> (<?= (int)$d['uses'] ?>)</option><?php endforeach; ?></select></label>
            <button class="btn">Объединить</button>
        </form>
    </details>
    <?php endif; ?>
</section>
</div>
</div>
