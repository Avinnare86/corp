<?php
/**
 * Единая точка входа. Веб-сервер должен указывать корнем папку public/.
 * Локальный запуск:  php -S localhost:8000 -t public router.php
 *
 * Структура проекта:
 *   app/Core        — ядро (роутер, БД, аутентификация, шаблоны)
 *   app/Controllers — обработчики запросов
 *   app/Services    — бизнес-логика (расчёт ЗП, выборка, парсинг списков, журнал…)
 *   app/Views       — шаблоны (partials/ — переиспользуемые блоки)
 *   app/routes.php  — таблица маршрутов
 *   database/       — миграция схемы и начальные данные
 *   storage/        — БД SQLite и загруженные файлы (вне public)
 */
require __DIR__ . '/../app/bootstrap.php';

$router = new \App\Core\Router();
require __DIR__ . '/../app/routes.php';

// Автолог всех POST-действий (мутаций) залогиненных пользователей. /login логируется отдельно.
$reqPath = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
if ($reqPath === '') { $reqPath = '/'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reqPath !== '/login' && \App\Core\Auth::check()) {
    \App\Services\Audit::logRequest($reqPath);
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
