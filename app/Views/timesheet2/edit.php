<h1>Табель <?= e($t['period']) ?> — <?= e($t['dept_name'] ?: 'вся организация') ?>
    <?= (int)$t['revision'] > 0 ? '<span class="tag">корректировочный №' . (int)$t['revision'] . '</span>' : '' ?></h1>
<p><a href="/timesheet2?month=<?= e(substr($t['period'],0,7)) ?>&half=<?= e(substr($t['period'],8)) ?>">← к списку табелей</a></p>

<section class="panel">
    <p class="muted">Коды: <b>8</b> — явка, <b>В</b> — выходной, <b>ОТ</b> — отпуск, <b>Б</b> — больничный,
        <b>К</b> — командировка, <b>НН</b> — неявка. Снимите галочку, чтобы исключить сотрудника из табеля («на часть сотрудников»).</p>
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/save">
        <?= csrf_field() ?>
        <table class="table" style="min-width:820px">
            <thead><tr><th style="width:26px"></th><th>Сотрудник</th>
                <?php foreach ($dates as $d): ?><th style="text-align:center;padding:6px 2px"><?= (int)substr($d,8,2) ?></th><?php endforeach; ?>
                <th class="num">8-час. дней</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $eId=(int)$r['employee_id']; $marks=str_split((string)$r['day_marks']); ?>
                <tr>
                    <td><input type="checkbox" name="keep[]" value="<?= $eId ?>" checked style="width:16px;height:16px;margin:0"></td>
                    <td><?= e($r['full_name']) ?><br><span class="muted" style="font-size:.72rem"><?= e($r['dept_name'] ?? '') ?></span></td>
                    <?php foreach ($dates as $i => $d): $cur=$marks[$i] ?? '0'; ?>
                        <td style="padding:2px">
                            <select name="mark[<?= $eId ?>][<?= $i ?>]" class="day-sel">
                                <?php foreach ($codes as $cv => $cl): ?>
                                    <option value="<?= $cv ?>" <?= $cur===$cv?'selected':'' ?>><?= $cl ?: '·' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php endforeach; ?>
                    <td class="num"><strong><?= (int)$r['days'] ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" style="margin-top:10px">Сохранить отметки</button>
    </form>
</section>

<section class="panel">
    <h2>Подписать электронной подписью</h2>
    <form method="post" action="/timesheet2/<?= (int)$t['id'] ?>/sign" class="grid-form">
        <?= csrf_field() ?>
        <label>Вид подписи
            <select name="sign_type">
                <?php foreach ($signTypes as $sv => $sl):
                    $hasCert = $sv === 'PEP' || array_filter($certs, fn($c) => $c['sign_type'] === $sv); ?>
                    <option value="<?= $sv ?>" <?= !$hasCert ? 'disabled' : '' ?>><?= e($sl) ?><?= !$hasCert ? ' — нет сертификата' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Пароль учётной записи (подтверждение)
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn btn-gold" onclick="return confirm('Подписать табель? После подписания изменения возможны только корректировочным табелем.')">🖋 Подписать</button>
    </form>
    <p class="muted">ПЭП выпускается системой автоматически при первом подписании. Для УНЭП/УКЭП сертификат
        регистрирует администратор (Оргструктура → Сертификаты ЭП). Подписанный табель попадает в расчёт ЗП
        и отображается листом со штампом подписи.</p>
</section>
