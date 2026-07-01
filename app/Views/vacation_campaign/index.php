<?php
/** @var array $stages year|camp|stage|isHr|isHead|myDepts|memos|years|csrf */
use App\Services\VacationCampaignService as VC;
$order = ['balances', 'blackouts', 'booking', 'signing', 'closed'];
$curIdx = $camp ? array_search($stage, $order, true) : -1;
$next = $curIdx !== false && $curIdx >= 0 && $curIdx < count($order) - 1 ? $order[$curIdx + 1] : null;
$memoStatus = [
    'new' => 'не начата', 'draft' => 'черновик/на доработке', 'head_signed' => 'подписана начальником',
    'deputy_signed' => 'утверждена замом', 'approved' => 'утверждена директором', 'rejected' => 'отклонена',
];
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Годовая кампания по отпускам: кадры открывают её и утверждают остатки, начальники/замы задают
    запретные периоды, сотрудники сами вписывают даты (с контролем непересечения), затем по отделам подписывается служебка
    (начальник → зам → директор), и кадры формируют итоговый график отпусков.</p>

<form method="get" action="/vacation-campaign" style="margin-bottom:12px">
    <label>Год кампании
        <select name="year" onchange="this.form.submit()">
            <?php foreach ($years as $y): ?>
                <option value="<?= (int) $y ?>" <?= (int) $y === (int) $year ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<?php if (!$camp): ?>
    <section class="panel">
        <h2 style="margin-top:0">Кампания на <?= (int) $year ?> не открыта</h2>
        <?php if ($isHr): ?>
            <form method="post" action="/vacation-campaign/open">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="year" value="<?= (int) $year ?>">
                <button class="btn btn-primary">Открыть кампанию (этап «Сбор остатков»)</button>
            </form>
        <?php else: ?>
            <p class="muted">Ожидайте открытия кампании отделом кадров.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <h2 style="margin-top:0">Этапы кампании</h2>
        <ol class="vc-steps">
            <?php foreach ($order as $i => $code): ?>
                <li class="<?= $i < $curIdx ? 'done' : ($i === $curIdx ? 'active' : '') ?>">
                    <span class="vc-step-n"><?= $i + 1 ?></span><?= e($stages[$code] ?? $code) ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($isHr): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                <?php if ($stage === 'balances'): ?>
                    <form method="post" action="/vacation-campaign/approve-balances">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="year" value="<?= (int) $year ?>">
                        <button class="btn btn-primary">Утвердить остатки → запретные периоды</button>
                    </form>
                <?php elseif ($next): ?>
                    <form method="post" action="/vacation-campaign/advance" onsubmit="return confirm('Перевести кампанию на этап «<?= e($stages[$next] ?? $next) ?>»?')">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="year" value="<?= (int) $year ?>">
                        <input type="hidden" name="to" value="<?= e($next) ?>">
                        <button class="btn btn-primary">Перейти к этапу «<?= e($stages[$next] ?? $next) ?>»</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Разделы</h2>
        <div class="vc-links">
            <?php if ($isHr): ?>
                <a class="btn btn-mini" href="/vacation-campaign/balances?year=<?= (int) $year ?>">Остатки отпусков</a>
                <a class="btn btn-mini" href="/vacation-campaign/rules">Правила непересечения</a>
            <?php endif; ?>
            <?php if ($isHead): ?>
                <a class="btn btn-mini" href="/vacation-campaign/blackouts">Запретные периоды</a>
                <a class="btn btn-mini" href="/vacation-campaign/map?year=<?= (int) $year ?>">Карта отпусков</a>
                <a class="btn btn-mini" href="/vacation-campaign/change-requests?year=<?= (int) $year ?>">Заявки на изменение графика</a>
            <?php endif; ?>
            <a class="btn btn-mini btn-primary" href="/vacation-campaign/booking?year=<?= (int) $year ?>">Моя запись на отпуск</a>
        </div>
    </section>

    <?php if ($isHead): ?>
    <section class="panel">
        <h2>Служебки по отделам</h2>
        <p class="muted" style="margin-top:0">Когда сотрудники отдела распределили отпуск — начальник подписывает служебку,
            затем её утверждают зам и директор. После утверждения кадры формируют график.</p>
        <table class="table tbl-cards">
            <thead><tr><th>Отдел</th><th>Статус служебки</th><th></th></tr></thead>
            <tbody>
            <?php
            $byDept = [];
            foreach ($memos as $m) { $byDept[(int) $m['department_id']] = $m; }
            foreach ($myDepts as $dId):
                $dn = (string) (\App\Core\Database::scalar('SELECT name FROM departments WHERE id=?', [$dId]) ?: ('Отдел #' . $dId));
                $m = $byDept[$dId] ?? null;
                $st = $m['status'] ?? 'new';
            ?>
                <tr>
                    <td data-label="Отдел"><?= e($dn) ?></td>
                    <td data-label="Статус">
                        <?php if ($st === 'approved'): ?><span class="tag ok"><?= e($memoStatus[$st]) ?></span>
                        <?php elseif ($st === 'new'): ?><span class="tag"><?= e($memoStatus[$st]) ?></span>
                        <?php else: ?><span class="tag warn"><?= e($memoStatus[$st] ?? $st) ?></span><?php endif; ?>
                    </td>
                    <td><a class="btn btn-mini" href="/vacation-campaign/memo/<?= (int) $dId ?>?year=<?= (int) $year ?>">Открыть служебку</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$myDepts): ?><tr><td colspan="3" class="muted">Нет отделов в вашей зоне ответственности.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
<?php endif; ?>

<style>
.vc-steps{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:8px}
.vc-steps li{padding:6px 12px;border:1px solid var(--line,#ddd);border-radius:18px;font-size:.9rem;color:#666;display:flex;align-items:center;gap:6px}
.vc-steps li.active{border-color:#2a7;background:#eafaf1;color:#177245;font-weight:600}
.vc-steps li.done{border-color:#bcdfc9;background:#f4fbf6;color:#2a7}
.vc-step-n{display:inline-flex;width:20px;height:20px;border-radius:50%;background:#eee;align-items:center;justify-content:center;font-size:.78rem}
.vc-steps li.active .vc-step-n{background:#2a7;color:#fff}
.vc-steps li.done .vc-step-n{background:#bcdfc9;color:#177245}
.vc-links{display:flex;gap:8px;flex-wrap:wrap}
</style>
