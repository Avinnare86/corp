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

    // ===== Исполняющие обязанности (И.о./ВРИО) — контекст замещения =====

    /** @var int|false|null проверенный контекст «работаю как И.о.» (false = ещё не проверяли) */
    private static $actingChecked = false;

    /** Кого текущий пользователь СЕЙЧАС замещает в активном контексте (И.о.), либо null. Ревалидируется каждый запрос. */
    public static function actingAs(): ?int
    {
        if (self::$actingChecked !== false) { return self::$actingChecked; }
        $target = (int) ($_SESSION['acting_as'] ?? 0);
        $me = (int) (self::id() ?? 0);
        $ok = $target && $me && \App\Services\Acting::activeFor($me, $target) !== null;
        if (!$ok && !empty($_SESSION['acting_as'])) { unset($_SESSION['acting_as']); } // период истёк/отменён
        return self::$actingChecked = ($ok ? $target : null);
    }

    /** Эффективный набор ролей: свои + (если активен контекст И.о.) роли замещаемого. */
    public static function effectiveRoles(): array
    {
        $set = self::roles();
        $act = self::actingAs();
        if ($act) { $set += self::roles($act); }
        return $set;
    }

    /** Есть ли среди ЭФФЕКТИВНЫХ ролей (свои + И.о.) одна из перечисленных. */
    public static function effectiveHas(string ...$slugs): bool
    {
        $roles = self::effectiveRoles();
        foreach ($slugs as $s) { if (!empty($roles[$s])) { return true; } }
        return false;
    }

    /** Действует ли текущий пользователь за $target (сам или активный И.о. этого лица). */
    public static function actsAsUser(int $target): bool
    {
        return (int) (self::id() ?? 0) === $target || self::actingAs() === $target;
    }

    /** Личности текущего пользователя: он сам + (если активен режим И.о.) замещаемый. */
    public static function actorIds(): array
    {
        $me = (int) (self::id() ?? 0);
        $set = $me ? [$me] : [];
        $act = self::actingAs();
        if ($act) { $set[] = $act; }
        return $set;
    }

    // ===== Работа админа «как сотрудник» (impersonation) =====

    /** Админ «входит как» сотрудник: подменяет личность, сохраняя данные для возврата. */
    public static function impersonate(int $targetId): bool
    {
        $u = Database::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$targetId]);
        if (!$u) { return false; }
        $_SESSION['impostor_admin_id']   = (int) self::id();
        $_SESSION['impostor_admin_role'] = self::role();
        $_SESSION['impostor_admin_name'] = $_SESSION['name'] ?? '';
        $_SESSION['impostor_admin_pw']   = (int) ($_SESSION['must_pw_change'] ?? 0);
        unset($_SESSION['acting_as']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $u['id'];
        $_SESSION['role']    = $u['role'];
        $_SESSION['name']    = $u['full_name'];
        $_SESSION['must_pw_change'] = 0;   // не ловим админа на смене пароля сотрудника
        self::$roleCache = []; self::$actingChecked = false;
        return true;
    }

    public static function impostorAdminId(): ?int
    {
        return !empty($_SESSION['impostor_admin_id']) ? (int) $_SESSION['impostor_admin_id'] : null;
    }

    /** Возврат к админу из режима «войти как». */
    public static function stopImpersonating(): bool
    {
        if (empty($_SESSION['impostor_admin_id'])) { return false; }
        $adminId = (int) $_SESSION['impostor_admin_id'];
        session_regenerate_id(true);
        $_SESSION['user_id'] = $adminId;
        $_SESSION['role']    = $_SESSION['impostor_admin_role'] ?? 'admin';
        $_SESSION['name']    = $_SESSION['impostor_admin_name'] ?? '';
        $_SESSION['must_pw_change'] = (int) ($_SESSION['impostor_admin_pw'] ?? 0);
        unset($_SESSION['impostor_admin_id'], $_SESSION['impostor_admin_role'],
              $_SESSION['impostor_admin_name'], $_SESSION['impostor_admin_pw'], $_SESSION['acting_as']);
        self::$roleCache = []; self::$actingChecked = false;
        return true;
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
        // Эффективные роли: свои + (в режиме И.о.) роли замещаемого — чтобы И.о. имел доступ к разделам замещаемого.
        if (!self::effectiveHas(...$roles)) {
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
