<?php
/**
 * Конфигурация приложения.
 * Для локального запуска используется SQLite (ничего ставить не нужно).
 * Для интернет-хостинга поменяйте 'driver' на 'mysql' и заполните mysql-блок.
 */
return [
    'app_name' => 'Корпоративный портал',
    // Версия портала (показывается в подвале). Бамп вручную при релизах.
    'app_version' => '0.70.X1',

    // 'sqlite' (по умолчанию, для разработки/демо), 'pgsql' (рекомендуется для прод) или 'mysql'.
    // Драйвер берётся из окружения DB_DRIVER (для Docker/прод задайте DB_DRIVER=pgsql);
    // если переменная не задана — остаётся SQLite (локальная разработка, storage/database.sqlite).
    'driver' => getenv('DB_DRIVER') ?: 'sqlite',

    'sqlite' => [
        'path' => __DIR__ . '/../storage/database.sqlite',
    ],

    'pgsql' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: '5432',
        'database' => getenv('DB_NAME') ?: 'uchet',
        'username' => getenv('DB_USER') ?: 'uchet',
        'password' => getenv('DB_PASS') ?: '',
    ],

    'mysql' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: 'uchet',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
    ],

    // Параметры расчёта (можно менять и в админке — настройки в БД имеют приоритет).
    'defaults' => [
        'inspection_percent'  => 8,    // % анкет на проверку за прошлый день
        'penalty_escalation'  => 1.5,  // множитель эскалации повторных ошибок
    ],

    'session_name' => 'UCHET_SID',
];
