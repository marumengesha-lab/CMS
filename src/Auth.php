<?php
/**
 * Authentication: login, logout and the loaded "current user" (identity +
 * roles + permissions). The user array is the single input for both Rbac
 * (permissions) and Scope (data isolation).
 */
declare(strict_types=1);

final class Auth
{
    private const SESSION_KEY = '_uid';

    private static bool $resolved = false;
    private static ?array $user  = null;

    /** The authenticated user, or null. Cached for the request. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = Session::get(self::SESSION_KEY);
        if (!$id) {
            return self::$user = null;
        }

        $user = self::loadUser((int) $id);
        if (!$user || $user['status'] !== 'active') {
            self::logout();
            return self::$user = null;
        }
        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Drop the cached identity so it is reloaded from the database on the
     * next call. Use after the current user's own roles/permissions change,
     * and by the test suite when switching the acting user.
     */
    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user     = null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    /** Effective data scope of the current user. */
    public static function scope(): Scope
    {
        return Scope::forUser(self::user());
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    // -----------------------------------------------------------------
    // Login / logout
    // -----------------------------------------------------------------

    /**
     * Try to authenticate. Returns ['ok' => bool, 'message' => string].
     * Brute-force attempts are recorded in login_attempts.
     */
    public static function attempt(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'message' => 'Username and password are required.'];
        }

        if (self::isLockedOut($username)) {
            return ['ok' => false, 'message' => 'Too many failed attempts. Please try again in a few minutes.'];
        }

        $row = Database::one(
            'SELECT * FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1',
            [$username, $username]
        );

        // Always run a hash comparison to avoid user-enumeration timing leaks.
        $hash = $row['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinva';

        if (!$row || !password_verify($password, $hash)) {
            self::recordAttempt($username, false);
            return ['ok' => false, 'message' => 'Invalid credentials.'];
        }

        if ($row['status'] !== 'active') {
            self::recordAttempt($username, false);
            return ['ok' => false, 'message' => 'This account is ' . $row['status'] . '.'];
        }

        self::recordAttempt($username, true);
        self::login($row);
        return ['ok' => true, 'message' => 'Welcome back.'];
    }

    public static function login(array $userRow): void
    {
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int) $userRow['id']);

        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => client_ip(),
        ], ['id' => (int) $userRow['id']]);

        Database::run('UPDATE `users` SET `login_count` = `login_count` + 1 WHERE `id` = ?', [(int) $userRow['id']]);

        self::$resolved = false;
        self::$user     = null;

        Audit::log('auth.login', ['entity_type' => 'user', 'entity_id' => (int) $userRow['id']]);
    }

    public static function logout(): void
    {
        $user = self::$user;
        if ($user) {
            Audit::log('auth.logout', ['entity_type' => 'user', 'entity_id' => (int) $user['id']]);
        }
        Session::forget(self::SESSION_KEY);
        self::$resolved = false;
        self::$user     = null;
    }

    // -----------------------------------------------------------------
    // Loading identity + roles + permissions
    // -----------------------------------------------------------------

    private static function loadUser(int $id): ?array
    {
        $user = Database::one('SELECT * FROM `users` WHERE `id` = ?', [$id]);
        if (!$user) {
            return null;
        }
        $user['roles']       = self::rolesFor($id);
        $user['permissions'] = self::permissionsFor($id);
        return $user;
    }

    /**
     * Active, non-expired role assignments.
     * Each row carries its scope, which is what Scope::forUser() consumes.
     */
    public static function rolesFor(int $userId): array
    {
        return Database::all(
            'SELECT ur.`id` AS `assignment_id`, ur.`scope_type`, ur.`scope_id`, ur.`expires_at`,
                    r.`id` AS `role_id`, r.`code` AS `role_code`, r.`name` AS `role_name`,
                    r.`scope_level`, r.`is_system`
               FROM `user_roles` ur
               JOIN `roles` r ON r.`id` = ur.`role_id`
              WHERE ur.`user_id` = ?
                AND r.`status` = \'active\'
                AND (ur.`expires_at` IS NULL OR ur.`expires_at` > NOW())
              ORDER BY FIELD(ur.`scope_type`, \'global\', \'institution\', \'department\'), r.`name`',
            [$userId]
        );
    }

    /** Flat list of permission codes granted by all of the user's roles. */
    public static function permissionsFor(int $userId): array
    {
        $rows = Database::all(
            'SELECT DISTINCT p.`code`
               FROM `user_roles` ur
               JOIN `role_permissions` rp ON rp.`role_id` = ur.`role_id`
               JOIN `permissions` p       ON p.`id` = rp.`permission_id`
              WHERE ur.`user_id` = ?
                AND (ur.`expires_at` IS NULL OR ur.`expires_at` > NOW())',
            [$userId]
        );
        return array_map(static fn ($r) => (string) $r['code'], $rows);
    }

    // -----------------------------------------------------------------
    // Brute force protection
    // -----------------------------------------------------------------

    public static function isLockedOut(string $username): bool
    {
        $max    = (int) config('app.max_login_attempts', 5);
        $window = (int) config('app.lockout_seconds', 300);

        $failures = (int) Database::value(
            'SELECT COUNT(*) FROM `login_attempts`
              WHERE `username` = ? AND `successful` = 0
                AND `created_at` > (NOW() - INTERVAL ? SECOND)',
            [$username, $window],
            0
        );

        return $failures >= $max;
    }

    public static function recordAttempt(string $username, bool $successful): void
    {
        Database::insert('login_attempts', [
            'username'   => substr($username, 0, 60),
            'ip_address' => client_ip(),
            'successful' => $successful ? 1 : 0,
        ]);

        if ($successful) {
            // Clear the failure counter so a legitimate login resets the lock.
            Database::run('DELETE FROM `login_attempts` WHERE `username` = ? AND `successful` = 0', [$username]);
        }
    }
}
