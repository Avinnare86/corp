<h1>Сертификаты ЭП</h1>
<?php include __DIR__ . '/../partials/org_nav.php'; ?>

<section class="panel">
    <h2>Зарегистрировать сертификат (УНЭП/УКЭП)</h2>
    <form method="post" action="/admin/org/cert" class="grid-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label>Сотрудник
            <select name="user_id"><?php foreach ($usersFull as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Вид<select name="sign_type"><option value="UNEP">УНЭП</option><option value="UKEP">УКЭП</option></select></label>
        <label class="grow">Файл сертификата<input type="file" name="cert_file" accept=".cer,.crt,.pem,.der" required></label>
        <button class="btn btn-primary">Загрузить и прочитать</button>
    </form>
    <p class="muted">Серийный номер, владелец и срок действия читаются из самого сертификата (.cer/.crt/.pem).
        ПЭП (простая ЭП) выпускается автоматически при первом подписании — её регистрировать не нужно.</p>
</section>

<section class="panel">
    <h2>Зарегистрированные сертификаты</h2>
    <table class="table">
        <thead><tr><th>Владелец</th><th>Вид</th><th>Серийный №</th><th>Действует</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($certs as $c): ?>
            <tr>
                <td><?= e($c['full_name']) ?></td>
                <td><span class="tag"><?= e($c['sign_type']) ?></span></td>
                <td class="mono"><?= e($c['serial']) ?></td>
                <td class="muted"><?= e($c['issued_at']) ?> — <?= e($c['valid_to']) ?></td>
                <td><form method="post" action="/admin/org/cert/<?= (int)$c['id'] ?>/delete" onsubmit="return confirm('Удалить сертификат?')">
                    <?= csrf_field() ?><button class="btn btn-mini btn-danger">×</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$certs): ?><tr><td colspan="5" class="muted">Сертификатов нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
