<h1>Настройки расчёта</h1>

<section class="panel">
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <label>Процент анкет на проверку (за прошлый день), %
            <input type="number" step="0.1" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        </label>
        <label>Целевая дневная норма анкет (ориентир)
            <input type="number" step="1" name="daily_norm" value="<?= e($dailyNorm) ?>">
        </label>
        <label>Шаг эскалации штрафа за повтор
            <input type="number" step="0.1" name="penalty_step" value="<?= e($penaltyStep) ?>">
        </label>
        <label>Потолок множителя штрафа
            <input type="number" step="0.1" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        </label>
        <label>Ночные (колл-центр 2/2), % к часу
            <input type="number" step="1" name="night_pct" value="<?= e($nightPct) ?>">
        </label>
        <label>Множитель праздничных часов (×)
            <input type="number" step="0.1" name="holiday_mult" value="<?= e($holidayMult) ?>">
        </label>
        <label>Множитель сверхурочных часов (×)
            <input type="number" step="0.1" name="overtime_mult" value="<?= e($overtimeMult) ?>">
        </label>
        <label>Начало ночного времени (ТК ст.96)
            <input type="time" name="night_start" value="<?= e($nightStart) ?>">
        </label>
        <label>Конец ночного времени
            <input type="time" name="night_end" value="<?= e($nightEnd) ?>">
        </label>
        <p class="muted" style="flex-basis:100%;margin:0">Ночное окно делит смены 2/2 на дневные/ночные часы автоматически (для дробного кода «Я/Н»). Применяется к сменам, сохранённым после изменения.</p>
        <button class="btn btn-primary">Сохранить</button>
    </form>
</section>

<section class="panel">
    <h2>Производственный календарь РФ</h2>
    <p class="muted" style="margin-top:0">Норма рабочих дней месяца (для оклада и бюджета ФОТ) берётся из официального
        производственного календаря РФ (праздники и переносы), источник — isdayoff.ru. Если год не загружен —
        откат на простой счёт Пн–Пт. Обновляйте, когда правительство публикует переносы на следующий год (обычно осенью).</p>
    <table class="table" style="max-width:520px;margin-bottom:12px">
        <tbody>
            <tr><td>Текущий год (<?= (int) $calendar['curYear'] ?>)</td>
                <td><?= $calendar['curFetched'] ? '<span class="tag ok">загружен</span> ' . e(substr((string) $calendar['curFetched'], 0, 16)) : '<span class="tag off">не загружен (Пн–Пт)</span>' ?></td></tr>
            <tr><td>Следующий год (<?= (int) $calendar['nextYear'] ?>)</td>
                <td><?= $calendar['nextFetched'] ? '<span class="tag ok">загружен</span> ' . e(substr((string) $calendar['nextFetched'], 0, 16)) : '<span class="tag off">не загружен</span>' ?></td></tr>
            <tr><td>Рабочих дней в текущем месяце</td>
                <td><strong><?= $calendar['curMonthWd'] !== null ? (int) $calendar['curMonthWd'] . ' дн.' : '— (по календарю не загружено)' ?></strong></td></tr>
        </tbody>
    </table>
    <form method="post" action="/admin/calendar/refresh" style="margin:0">
        <?= csrf_field() ?>
        <button class="btn">Обновить календарь (<?= (int) $calendar['curYear'] ?> и <?= (int) $calendar['nextYear'] ?>)</button>
    </form>
</section>

<section class="panel">
    <h2>ИИ для виз (OpenRouter)</h2>
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        <input type="hidden" name="daily_norm" value="<?= e($dailyNorm) ?>">
        <input type="hidden" name="penalty_step" value="<?= e($penaltyStep) ?>">
        <input type="hidden" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        <label>API-ключ OpenRouter<input type="password" name="openrouter_key" value="" placeholder="<?= $or['key_set'] ? 'ключ сохранён — введите новый для замены' : 'sk-or-…' ?>"></label>
        <label>Модель (открытое поле)<input type="text" name="openrouter_model" value="<?= e($or['model']) ?>" placeholder="anthropic/claude-sonnet-4.6"></label>
        <label style="grid-column:1/-1">Промпт подстановки адресов
            <textarea name="visa_prompt" rows="8" style="font-size:.8rem"><?= e($or['prompt']) ?></textarea>
        </label>
        <button class="btn btn-primary">Сохранить ИИ-настройки</button>
    </form>
    <p class="muted">Используется для пакетной подстановки адресов в визовых ходатайствах (по 25 строк за запрос,
        формат ответа ROW_X: адрес). Модель указывается идентификатором OpenRouter.</p>
</section>

<section class="panel">
    <h2>Подпись описей и гарантийных писем</h2>
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        <input type="hidden" name="daily_norm" value="<?= e($dailyNorm) ?>">
        <input type="hidden" name="penalty_step" value="<?= e($penaltyStep) ?>">
        <input type="hidden" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        <label>ФИО подписанта (по умолчанию)
            <input type="text" name="visa_signer_name" value="<?= e($visaSignerName) ?>" placeholder="О.Д. Моловцева">
        </label>
        <label>Должность подписанта (по умолчанию)
            <input type="text" name="visa_signer_position" value="<?= e($visaSignerPosition) ?>" placeholder="Директор Департамента международного сотрудничества">
        </label>
        <button class="btn btn-primary">Сохранить подписанта</button>
    </form>
    <p class="muted">Эти значения подставляются по умолчанию при формировании описи (их можно изменить для каждой описи).
        ФИО заменяет «К.О. Тринченко», должность — «Директор Департамента международного сотрудничества» в бланке ГП и в описи с подписью.</p>
</section>

<section class="panel">
    <h2>Директор — подписант служебок о стимуле</h2>
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        <input type="hidden" name="daily_norm" value="<?= e($dailyNorm) ?>">
        <input type="hidden" name="penalty_step" value="<?= e($penaltyStep) ?>">
        <input type="hidden" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        <label>ФИО директора
            <input type="text" name="stimul_director_name" value="<?= e($stimulDirectorName) ?>" placeholder="Д.Н. Семёнов">
        </label>
        <label>Должность
            <input type="text" name="stimul_director_position" value="<?= e($stimulDirectorPosition) ?>" placeholder="Генеральный директор">
        </label>
        <button class="btn btn-primary">Сохранить директора</button>
    </form>
    <p class="muted">Эти ФИО и должность показываются в шапке служебки («Генеральному директору …») и в штампе ЭП,
        когда служебку <strong>от имени директора подписывает администратор</strong>. Дату подписи администратор указывает при подписании.
        Если поле пустое — берётся пользователь с ролью «Директор», иначе «Д.Н. Семёнов».</p>
</section>

<section class="panel">
    <h2>Начальник отдела кадров (уведомления об отпуске)</h2>
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        <input type="hidden" name="daily_norm" value="<?= e($dailyNorm) ?>">
        <input type="hidden" name="penalty_step" value="<?= e($penaltyStep) ?>">
        <input type="hidden" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        <label style="grid-column:1/-1">Сотрудник — начальник отдела кадров
            <select name="vacation_hr_head">
                <option value="0">— не задан —</option>
                <?php foreach ($hrCandidates as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $vacationHrHeadId ? 'selected' : '' ?>>
                        <?= e($u['full_name']) ?><?= $u['position'] ? ' — ' . e($u['position']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary">Сохранить начальника отдела кадров</button>
    </form>
    <p class="muted">Указанный сотрудник подписывает своей ЭП пакет уведомлений об отпуске (ст. 123 ТК РФ) после утверждения
        сводного графика Т-7 директором. Только после его подписи уведомления автоматически направляются сотрудникам
        в личный кабинет и на почту. Подписать пакет может лично этот сотрудник в разделе «Уведомления об отпуске».</p>
</section>

<section class="panel">
    <h2>Электронная подпись (сервис УКЭП)</h2>
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        <input type="hidden" name="daily_norm" value="<?= e($dailyNorm) ?>">
        <input type="hidden" name="penalty_step" value="<?= e($penaltyStep) ?>">
        <input type="hidden" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        <label class="grow">URL сервиса подписи (DSS)
            <input type="text" name="sign_dss_url" value="<?= e($dss['url']) ?>" placeholder="https://sc.ined.ru/api">
        </label>
        <label>Префикс ID пользователя в сервисе
            <input type="text" name="sign_dss_user_prefix" value="<?= e($dss['prefix']) ?>" placeholder="uchet-">
        </label>
        <label class="check"><input type="checkbox" name="sign_dss_enabled" value="1" <?= $dss['enabled'] ? 'checked' : '' ?>> Подпись через сервис включена</label>
        <button class="btn btn-primary">Сохранить сервис ЭП</button>
    </form>
    <p class="muted">Централизованный сервис КриптоПро DSS выпускает УКЭП и подписывает документы по паролю пользователя
        (как в контуре грантов). Префикс отделяет учётные записи портала от других систем на том же сервисе
        (например, <code>uchet-15</code>). Требуется сетевой доступ сервера портала к этому адресу. Если выключено — действует
        прежняя подпись (ПЭП паролем / загруженный сертификат УНЭП/УКЭП).</p>
</section>

<section class="panel">
    <h2>Email-уведомления (SMTP)</h2>
    <form method="post" action="/admin/settings" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inspection_percent" value="<?= e($inspectionPercent) ?>">
        <input type="hidden" name="daily_norm" value="<?= e($dailyNorm) ?>">
        <input type="hidden" name="penalty_step" value="<?= e($penaltyStep) ?>">
        <input type="hidden" name="penalty_max_multiplier" value="<?= e($penaltyMaxMultiplier) ?>">
        <label class="chk"><input type="checkbox" name="smtp_enabled" value="1" <?= $smtp['enabled']?'checked':'' ?>> Включить отправку</label>
        <label>SMTP-сервер<input type="text" name="smtp_host" value="<?= e($smtp['host']) ?>" placeholder="smtp.yandex.ru"></label>
        <label>Порт<input type="number" name="smtp_port" value="<?= e($smtp['port']) ?>"></label>
        <label>Шифрование
            <select name="smtp_secure">
                <option value="ssl" <?= $smtp['secure']==='ssl'?'selected':'' ?>>SSL (465)</option>
                <option value="tls" <?= $smtp['secure']==='tls'?'selected':'' ?>>STARTTLS (587)</option>
                <option value="none" <?= $smtp['secure']==='none'?'selected':'' ?>>без шифрования</option>
            </select>
        </label>
        <label>Логин<input type="text" name="smtp_user" value="<?= e($smtp['user']) ?>"></label>
        <label>Пароль<input type="password" name="smtp_pass" placeholder="оставьте пустым, чтобы не менять"></label>
        <label>Отправитель (From)<input type="text" name="smtp_from" value="<?= e($smtp['from']) ?>" placeholder="noreply@org.ru"></label>
        <button class="btn btn-primary">Сохранить SMTP</button>
    </form>
    <p class="muted">Все внутренние уведомления (назначения, согласования, поручения, отпуска…) дублируются на email
        сотрудника, если SMTP включён и адрес заполнен в карточке сотрудника. Ошибки отправки пишутся в журнал и не мешают работе.</p>
</section>

<section class="panel">
    <h2>Как считается зарплата</h2>
    <p class="muted">
        <strong>Модель: гарантированный оклад + сделка.</strong>
        Сдельный заработок (S) считается «с нуля» — сумма стоимости всех проверенных анкет по ценам
        <a href="/admin/pricing">групп сложности</a>. Оклад (из <a href="/admin/positions">должности</a>,
        пропорционально отработанным дням) — гарантирован.
        <br><strong>К выплате = max(оклад, S) − штрафы.</strong>
        Если сделка не дотянула до оклада — платится оклад; если превысила — разница идёт премией сверху.
        <br><br>
        <strong>Выборка.</strong> Контролёр получает указанный % случайных анкет по каждому специалисту за прошлый день (минимум 1).
        <br><br>
        <strong>Эскалация штрафа за повтор.</strong> Множитель = 1 + шаг × (номер повтора − 1), но не выше
        потолка. При шаге 0.5 и потолке 2.0: 1-я ошибка типа = ×1, 2-я = ×1.5, 3-я и далее = ×2 (плато).
        Счётчик повторов сбрасывается каждый месяц (прогрессивная дисциплина с ограничением).
        <br><br>
        <strong>Целевая дневная норма</strong> — ориентир (60 анкет/день ≈ оклад выходит при текущих ценах);
        используется для целеполагания и рейтинга, на формулу выплаты напрямую не влияет.
    </p>
</section>
