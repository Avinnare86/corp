<h1>Моя электронная подпись</h1>

<section class="panel">
    <h2 style="margin-top:0">Простая ЭП (ПЭП)</h2>
    <p class="muted" style="margin:0">ПЭП — это подтверждение действий вашим паролем при подписании документов и служебок.
        Она <strong>активна автоматически</strong> и выпускается системой при первом подписании — отдельный сертификат загружать не нужно.</p>
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
        <thead><tr><th>Вид</th><th>Серийный №</th><th>Действует</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($certs as $c): ?>
            <tr>
                <td><span class="tag"><?= e($c['sign_type']) ?></span></td>
                <td class="mono"><?= e($c['serial']) ?></td>
                <td class="muted"><?= e($c['issued_at']) ?> — <?= e($c['valid_to']) ?></td>
                <td><form method="post" action="/certs/<?= (int)$c['id'] ?>/delete" onsubmit="return confirm('Удалить сертификат?')">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$certs): ?><tr><td colspan="4" class="muted">Загруженных сертификатов нет. ПЭП активна по умолчанию.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
