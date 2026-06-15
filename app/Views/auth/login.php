<?php use App\Core\Auth; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;600;700;800&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <h1><?= e($appName) ?></h1>
    <p class="muted">Вход в личный кабинет</p>

    <?php if (!empty($flashMsg)): ?>
        <div class="flash flash-<?= e($flashMsg['type']) ?>"><?= e($flashMsg['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <?= csrf_field() ?>
        <label>Логин
            <input type="text" name="login" autofocus required>
        </label>
        <label>Пароль
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary">Войти</button>
    </form>
</div>
</body>
</html>
