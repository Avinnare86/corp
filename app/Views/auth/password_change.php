<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Смена пароля — <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;600;700;800&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <h1>Смена пароля</h1>
    <?php if (!empty($forced)): ?>
        <p class="muted">Вы вошли с паролем, выданным администратором. Для продолжения работы задайте свой пароль.</p>
    <?php else: ?>
        <p class="muted">Задайте новый пароль для входа в личный кабинет.</p>
    <?php endif; ?>

    <?php if (!empty($flashMsg)): ?>
        <div class="flash flash-<?= e($flashMsg['type']) ?>"><?= e($flashMsg['message']) ?></div>
    <?php endif; ?>

    <div class="flash flash-info" style="text-align:left"><?= e($hint) ?></div>

    <form method="post" action="/password/change">
        <?= csrf_field() ?>
        <label>Новый пароль
            <input type="password" name="password" autocomplete="new-password" autofocus required>
        </label>
        <label>Повторите пароль
            <input type="password" name="password_confirm" autocomplete="new-password" required>
        </label>
        <button type="submit" class="btn btn-primary">Сохранить пароль</button>
    </form>
    <p class="muted" style="margin-top:14px;text-align:center"><a href="/logout">Выйти</a></p>
</div>
</body>
</html>
