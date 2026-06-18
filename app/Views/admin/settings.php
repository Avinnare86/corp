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
        <button class="btn btn-primary">Сохранить</button>
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
