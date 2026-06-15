<?php
/**
 * Инициализация приложения: автозагрузка, конфиг, БД, сессия.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('Europe/Moscow');

// Простой автозагрузчик для пространства имён App\.
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Core/helpers.php';

$config = require __DIR__ . '/../config/config.php';

\App\Core\Database::init($config);
\App\Core\Auth::start($config['session_name']);

// Общие данные для всех шаблонов.
\App\Core\View::share('appName', $config['app_name']);
\App\Core\View::share('authUser', \App\Core\Auth::user());
\App\Core\View::share('flashMsg', flash());

return $config;
