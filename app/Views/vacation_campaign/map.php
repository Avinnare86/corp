<?php
/** @var array $emps picks departments year month dept csrf */
$months = [1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',6=>'Июнь',7=>'Июль',8=>'Август',9=>'Сентябрь',10=>'Октябрь',11=>'Ноябрь',12=>'Декабрь'];
$daysIn = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
// множество дней-отпусков по сотруднику в выбранном месяце + счётчик по дню
$offByEmp = []; $countByDay = array_fill(1, $daysIn, 0);
foreach ($emps as $em) {
    $eid = (int) $em['id']; $offByEmp[$eid] = [];
    foreach ($picks[$eid] ?? [] as $p) {
        $a = max(strtotime($p['start_date']), mktime(0, 0, 0, $month, 1, $year));
        $b = min(strtotime($p['end_date']), mktime(0, 0, 0, $month, $daysIn, $year));
        for ($t = $a; $t <= $b; $t += 86400) {
            $d = (int) date('j', $t);
            $offByEmp[$eid][$d] = true; $countByDay[$d]++;
        }
    }
}
?>
<h1><?= e($title) ?></h1>
<p class="muted" style="margin-top:0"><a href="/vacation-campaign?year=<?= (int) $year ?>">← к кампании</a> &nbsp;
    Закрашены дни отпуска; <span style="background:#ffd9d9;padding:0 4px;border-radius:3px">красным</span> — дни, когда в отделе
    одновременно отдыхает более одного сотрудника (возможное наложение).</p>

<form method="get" action="/vacation-campaign/map" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
    <input type="hidden" name="year" value="<?= (int) $year ?>">
    <label>Месяц
        <select name="month" onchange="this.form.submit()">
            <?php foreach ($months as $mi => $mn): ?>
                <option value="<?= $mi ?>" <?= $mi === $month ? 'selected' : '' ?>><?= e($mn) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Отдел
        <select name="dept" onchange="this.form.submit()">
            <option value="0">— все мои отделы —</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $dept ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<section class="panel" style="overflow:auto">
    <table class="vc-map">
        <thead>
            <tr>
                <th class="vc-emp">Сотрудник</th>
                <?php for ($d = 1; $d <= $daysIn; $d++):
                    $wd = (int) date('N', mktime(0, 0, 0, $month, $d, $year)); ?>
                    <th class="<?= $wd >= 6 ? 'vc-we' : '' ?>"><?= $d ?></th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($emps as $em): $eid = (int) $em['id']; ?>
            <tr>
                <td class="vc-emp"><?= e($em['full_name']) ?></td>
                <?php for ($d = 1; $d <= $daysIn; $d++):
                    $off = !empty($offByEmp[$eid][$d]);
                    $clash = $off && $countByDay[$d] > 1;
                    $wd = (int) date('N', mktime(0, 0, 0, $month, $d, $year)); ?>
                    <td class="<?= $clash ? 'vc-clash' : ($off ? 'vc-off' : ($wd >= 6 ? 'vc-we' : '')) ?>"></td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$emps): ?><tr><td class="vc-emp" colspan="<?= $daysIn + 1 ?>">Нет сотрудников для отображения.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($emps): ?>
        <tfoot>
            <tr>
                <td class="vc-emp muted">Одновременно</td>
                <?php for ($d = 1; $d <= $daysIn; $d++): ?>
                    <td class="<?= $countByDay[$d] > 1 ? 'vc-clash' : '' ?>" style="text-align:center;font-size:.7rem"><?= $countByDay[$d] ?: '' ?></td>
                <?php endfor; ?>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</section>

<style>
.vc-map{border-collapse:collapse;font-size:.8rem}
.vc-map th,.vc-map td{border:1px solid #e3e3e3;width:20px;height:22px;text-align:center;padding:0}
.vc-map th{background:#f6f6f6;font-weight:600}
.vc-map .vc-emp{width:220px;min-width:180px;text-align:left;padding:2px 8px;white-space:nowrap;position:sticky;left:0;background:#fff;z-index:1}
.vc-map thead .vc-emp,.vc-map tfoot .vc-emp{background:#f6f6f6}
.vc-off{background:#bfe3c9}
.vc-clash{background:#ffb3b3}
.vc-we{background:#f0f0f5}
</style>
