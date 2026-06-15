<?php
/**
 * Конфигурация приложения.
 * Для локального запуска используется SQLite (ничего ставить не нужно).
 * Для интернет-хостинга поменяйте 'driver' на 'mysql' и заполните mysql-блок.
 */
return [
    'app_name' => 'Корпоративный портал',

    // 'sqlite' (по умолчанию, для разработки/демо), 'pgsql' (рекомендуется для прод) или 'mysql'
    // Сейчас зафиксировано на SQLite (старая рабочая БД storage/database.sqlite).
    // Для PostgreSQL верните строку: 'driver' => getenv('DB_DRIVER') ?: 'sqlite',  и задайте DB_DRIVER=pgsql.
    'driver' => 'sqlite',

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
