<?php
namespace App\Services;

use App\Core\Database;

/** Подключение пользователей к проектам (квота/визы/кадры/финансы/документы). */
class Projects
{
    private static array $cache = [];

    /** Набор кодов проектов пользователя. Админ подключён ко всем. */
    public static function forUser(int $uid): array
    {
        if (isset(self::$cache[$uid])) { return self::$cache[$uid]; }
        $role = Database::scalar('SELECT role FROM users WHERE id = ?', [$uid]);
        if ($role === 'admin') {
            $codes = array_map(fn($r) => $r['code'], Database::all('SELECT code FROM projects'));
        } else {
            $codes = array_map(fn($r) => $r['project_code'],
                Database::all('SELECT project_code FROM user_projects WHERE user_id = ?', [$uid]));
        }
        return self::$cache[$uid] = $codes;
    }

    public static function has(int $uid, string $code): bool
    {
        return in_array($code, self::forUser($uid), true);
    }

    public static function all(): array
    {
        return Database::all('SELECT * FROM projects ORDER BY rowid');
    }
}
