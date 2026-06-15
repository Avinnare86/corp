<?php
/**
 * Роутер для встроенного PHP-сервера (разработка).
 * Запуск:  php -S localhost:8000 -t public router.php
 * Раздаёт статические файлы из public/, остальное направляет в index.php.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // отдать файл как есть (css, js, картинки)
}

require __DIR__ . '/public/index.php';
