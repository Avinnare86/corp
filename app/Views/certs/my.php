<h1>Моя электронная подпись</h1>

<section class="panel">
    <h2 style="margin-top:0">Простая ЭП (ПЭП)</h2>
    <p class="muted" style="margin:0">ПЭП — это подтверждение действий вашим паролем при подписании документов и служебок.
        Она <strong>активна автоматически</strong> и выпускается системой при первом подписании — отдельный сертификат загружать не нужно.</p>
</section>

<section class="panel">
    <h2 style="margin-top:0">Выпустить УКЭП через сервис</h2>
    <?php if (!empty($dss_enabled)): ?>
        <p class="muted" style="margin:0 0 10px">Квалифицированная электронная подпись (УКЭП) выпускается централизованным
            сервисом по вашему паролю. Этим же паролем вы будете подписывать документы. Закрытый ключ хранится в защищённом
            сервисе — файл сертификата загружать не нужно.</p>
        <form method="post" action="/certs/issue" class="grid-form"
              onsubmit="return confirm('Выпустить (перевыпустить) УКЭП? Прежний сертификат, выпущенный сервисом, будет заменён.')">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="grow">Пароль подписи (мин. 6 символов)
                <input type="password" name="password" minlength="6" autocomplete="new-password" required>
            </label>
            <button class="btn btn-primary">Выпустить УКЭП</button>
        </form>
        <p class="muted" style="margin:10px 0 0;font-size:.85rem">Пароль не сохраняется в системе — он передаётся сервису подписи
            при выпуске и при каждом подписании. Запомните его.</p>
    <?php else: ?>
        <p class="muted" style="margin:0">Сервис электронной подписи не настроен. Обратитесь к администратору — после настройки
            здесь появится кнопка выпуска УКЭП. До этого можно загрузить сертификат файлом (ниже).</p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Загрузить сертификат УНЭП / УКЭП</h2>
    <form method="post" action="/certs" class="grid-form" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Вид подписи
            <select name="sign_type">
                <option value="UNEP">УНЭП (усиленная неквалифицированная)</option>
                <option value="UKEP">УКЭП (усиленная квалифицированная)</option>
            </select>
        </label>
        <label class="grow">Файл сертификата (открытый ключ)<input type="file" name="cert_file" accept=".cer,.crt,.pem,.der" required></label>
        <button class="btn btn-primary">Загрузить и прочитать</button>
    </form>
    <p class="muted">Прикрепите файл сертификата (.cer / .crt / .pem), выданный удостоверяющим центром — серийный номер,
        владелец и срок действия будут прочитаны из него автоматически. Закрытый ключ остаётся у вас (в токене/КриптоПро),
        система хранит только реквизиты.</p>
</section>

<section class="panel">
    <h2>Мои сертификаты</h2>
    <table class="table">
        <thead><tr><th>Вид</th><th>Источник</th><th>Серийный №</th><th>Отпечаток</th><th>Действует</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($certs as $c): ?>
            <tr>
                <td data-label="Вид"><span class="tag"><?= e($c['sign_type']) ?></span></td>
                <td data-label="Источник"><?= ($c['source'] ?? 'manual') === 'dss' ? '<span class="tag ok">сервис</span>' : '<span class="muted">файл</span>' ?></td>
                <td class="mono" data-label="Серийный №"><?= e($c['serial']) ?></td>
                <td class="mono muted" data-label="Отпечаток" style="font-size:.8rem"><?= e($c['fingerprint'] ?? '') ?: '—' ?></td>
                <td class="muted" data-label="Действует"><?= e($c['issued_at']) ?> — <?= e($c['valid_to']) ?></td>
                <td><form method="post" action="/certs/<?= (int)$c['id'] ?>/delete" onsubmit="return confirm('Удалить сертификат?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$certs): ?><tr><td colspan="6" class="muted">Загруженных сертификатов нет. ПЭП активна по умолчанию.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
