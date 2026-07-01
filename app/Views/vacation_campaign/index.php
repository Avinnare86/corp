<?php
/** @var array $stages year|camp|stage|isHr|isHead|myDepts|memos|deptsAgreed|allAgreed|orgSchedule|years|csrf */
use App\Services\VacationCampaignService as VC;
$order = ['balances', 'blackouts', 'booking', 'signing', 'closed'];
$curIdx = $camp ? array_search($stage, $order, true) : -1;
$next = $curIdx !== false && $curIdx >= 0 && $curIdx < count($order) - 1 ? $order[$curIdx + 1] : null;
$memoStatus = [
    'new' => 'не начата', 'draft' => 'черновик/на доработке', 'head_signed' => 'подписана начальником (ожидает зама)',
    'deputy_signed' => 'согласована замом (сдан)', 'rejected' => 'отклонена',
];
$deptsAgreed = $deptsAgreed ?? [];
$orgSchedule = $orgSchedule ?? null;
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0">Годовая кампания по отпускам: кадры открывают её и утверждают остатки, начальники/замы задают
    запретные периоды, сотрудники сами вписывают даты. Начальник блокирует отдел и подписывает график ЭП, зам согласует;
    после согласования всех отделов кадры формируют один сводный график по форме Т-7, который утверждает директор.</p>

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

    <?php if ($isHr): ?>
    <section class="panel">
        <h2>Контроль отделов и сводный график (Т-7)</h2>
        <p class="muted" style="margin-top:0">Все отделы должны согласовать свои графики (начальник → зам). После этого сформируйте
            один сводный график по форме Т-7 — его утверждает директор своей ЭЦП, затем сотрудникам направляются уведомления.</p>
        <table class="table tbl-cards">
            <thead><tr><th>Отдел</th><th>Сотрудников</th><th>Статус</th></tr></thead>
            <tbody>
            <?php foreach ($deptsAgreed as $da): ?>
                <tr>
                    <td data-label="Отдел"><a href="/vacation-campaign/memo/<?= (int) $da['dept'] ?>?year=<?= (int) $year ?>"><?= e($da['name']) ?></a></td>
                    <td data-label="Сотрудников"><?= (int) $da['employees'] ?></td>
                    <td data-label="Статус">
                        <?php if ($da['agreed']): ?><span class="tag ok">✓ согласован</span>
                        <?php else: ?><span class="tag warn"><?= e($memoStatus[$da['status']] ?? $da['status']) ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$deptsAgreed): ?><tr><td colspan="3" class="muted">Нет отделов с активными сотрудниками.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <?php if ($orgSchedule): ?>
                <a class="btn btn-primary" href="/vacation-schedule/<?= (int) $orgSchedule['id'] ?>/t7">
                    Открыть сводный график Т-7 (<?= $orgSchedule['status'] === 'signed' ? 'утверждён директором' : 'черновик, на утверждении' ?>)</a>
            <?php else: ?>
                <form method="post" action="/vacation-schedule/consolidate" onsubmit="return confirm('Сформировать сводный график Т-7 по всем отделам?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="year" value="<?= (int) $year ?>">
                    <button class="btn btn-primary" <?= $allAgreed ? '' : 'disabled' ?>>📋 Сформировать сводный график Т-7</button>
                </form>
                <?php if (!$allAgreed): ?><span class="muted">Кнопка станет доступна, когда все отделы согласуют графики.</span><?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

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
                        <?php if ($st === 'deputy_signed'): ?><span class="tag ok"><?= e($memoStatus[$st]) ?></span>
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
