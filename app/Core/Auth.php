<?php
namespace App\Core;

class Auth
{
    public static function start(string $sessionName): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($sessionName);
            session_start();
        }
    }

    public static function attempt(string $login, string $password): bool
    {
        $user = Database::one(
            'SELECT * FROM users WHERE login = ? AND is_active = 1',
            [$login]
        );
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['name']    = $user['full_name'];
            // Требование сменить пароль при первом входе (пароль задан админом).
            $_SESSION['must_pw_change'] = (int) ($user['must_change_password'] ?? 0);
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return Database::one('SELECT * FROM users WHERE id = ?', [self::id()]);
    }

    /** @var array<int,array<string,bool>> кэш набора ролей на запрос */
    private static array $roleCache = [];

    /**
     * Эффективный НАБОР ролей пользователя (slug => true). Админу доступны все роли.
     * Для совместимости со старыми проверками сохраняются базовые роли users.role
     * и производная 'manager' (если есть anketa_manager или visa_manager).
     */
    public static function roles(?int $uid = null): array
    {
        $uid = $uid ?? self::id();
        if (!$uid) { return []; }
        if (isset(self::$roleCache[$uid])) { return self::$roleCache[$uid]; }

        $base = (string) Database::scalar('SELECT role FROM users WHERE id = ?', [$uid]);
        $set = [];
        if ($base !== '') { $set[$base] = true; } // 'admin' | 'employee' | legacy 'manager'/'controller'

        if ($base === 'admin') {
            foreach (Database::all('SELECT slug FROM roles') as $r) { $set[$r['slug']] = true; }
        } else {
            foreach (Database::all('SELECT role_slug FROM user_roles WHERE user_id = ?', [$uid]) as $r) {
                $set[$r['role_slug']] = true;
            }
        }
        // производная legacy-роль «менеджер» для старых requireRole('manager', ...)
        if (!empty($set['anketa_manager']) || !empty($set['visa_manager'])) { $set['manager'] = true; }
        return self::$roleCache[$uid] = $set;
    }

    /** Есть ли у текущего пользователя хотя бы одна из перечисленных ролей (slug или базовая). */
    public static function has(string ...$slugs): bool
    {
        $roles = self::roles();
        foreach ($slugs as $s) { if (!empty($roles[$s])) { return true; } }
        return false;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::has(...$roles)) {
            http_response_code(403);
            echo View::render('errors/403', ['title' => 'Доступ запрещён']);
            exit;
        }
    }

    /** CSRF-токен для форм. */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            exit('Сессия истекла, обновите страницу.');
        }
    }
}
