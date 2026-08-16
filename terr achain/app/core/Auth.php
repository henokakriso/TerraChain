<?php
declare(strict_types=1);

final class Auth
{
    public const SESSION_KEY = 'terrachain_user';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = App::config('session');
        session_name((string)$cfg['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool)$cfg['cookie_secure'],
            'httponly' => (bool)$cfg['cookie_httponly'],
            'samesite' => (string)$cfg['cookie_samesite'],
        ]);
        session_start();
    }

    public static function attempt(string $username, string $password, string $ip): array|false
    {
        $db = App::db();

        $user = $db->fetchOne(
            'SELECT * FROM users WHERE username = ? AND is_active = 1',
            [$username]
        );

        if ($user === null) {
            return false;
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            throw new ApiException('Account temporarily locked. Try again later.', 423);
        }

        if (!password_verify($password, $user['password_hash'])) {
            $attempts = (int)$user['failed_login_count'] + 1;
            $max = (int)App::config('security.max_login_attempts', 5);
            $lockedUntil = null;
            if ($attempts >= $max) {
                $lockedUntil = date('Y-m-d H:i:s', time() + ((int)App::config('security.lockout_minutes', 15) * 60));
                $attempts = 0;
            }
            $db->update('users', [
                'failed_login_count' => $attempts,
                'locked_until' => $lockedUntil,
            ], 'id = ?', [(int)$user['id']]);
            AuditService::log((int)$user['id'], 'LOGIN_FAILED', 'user', (string)$user['id'], null, null, $ip, true, 'Failed login attempt');
            throw new ApiException('Invalid username or password.', 401);
        }

        $db->update('users', [
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => App::now(),
        ], 'id = ?', [(int)$user['id']]);

        return $user;
    }

    public static function createSession(int $userId): string
    {
        $db = App::db();
        $token = bin2hex(random_bytes(32));
        $timeout = (int)App::config('session.timeout_minutes', 60);
        $db->insert('sessions', [
            'id' => $token,
            'user_id' => $userId,
            'user_agent' => Request::userAgent(),
            'ip_address' => Request::ip(),
            'expires_at' => date('Y-m-d H:i:s', time() + $timeout * 60),
        ]);
        return $token;
    }

    public static function setSession(string $token, array $user): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'session_token' => $token,
            'user' => $user,
        ];
    }

    public static function user(): ?array
    {
        self::startSession();
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        $data = $_SESSION[self::SESSION_KEY];
        $db = App::db();
        $session = $db->fetchOne(
            'SELECT s.*, u.* FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.revoked = 0 AND s.expires_at > NOW() AND u.is_active = 1',
            [$data['session_token']]
        );
        if ($session === null) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        return $session;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            Response::unauthorized('Login required.');
        }
        return $user;
    }

    public static function logout(): void
    {
        self::startSession();
        if (isset($_SESSION[self::SESSION_KEY]['session_token'])) {
            App::db()->update('sessions', ['revoked' => 1], 'id = ?', [$_SESSION[self::SESSION_KEY]['session_token']]);
        }
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public static function hasPermission(array $user, string $permissionCode): bool
    {
        if (empty($user['role_id'])) {
            return false;
        }
        $count = App::db()->fetchOne(
            'SELECT COUNT(*) AS c FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ? AND p.code = ?',
            [(int)$user['role_id'], $permissionCode]
        );
        return (int)$count['c'] > 0;
    }

    public static function roleName(int $roleId): string
    {
        $role = App::db()->fetchOne('SELECT name FROM roles WHERE id = ?', [$roleId]);
        return $role['name'] ?? 'unknown';
    }

    /**
     * Administrative scope check (section 29):
     * the user's admin unit must be an ancestor of (or equal to) the target unit.
     */
    public static function inScope(?int $userUnitId, ?int $targetUnitId): bool
    {
        if ($userUnitId === null || $targetUnitId === null) {
            return false;
        }
        if ((int)$userUnitId === 1) {
            return true; // federal
        }
        $db = App::db();
        $current = (int)$targetUnitId;
        $seen = [];
        while (true) {
            if ($current === (int)$userUnitId) {
                return true;
            }
            if (isset($seen[$current])) {
                return false;
            }
            $seen[$current] = true;
            $unit = $db->fetchOne('SELECT parent_id FROM admin_units WHERE id = ?', [$current]);
            if ($unit === null || $unit['parent_id'] === null) {
                return false;
            }
            $current = (int)$unit['parent_id'];
        }
    }

    public static function isSystemAdmin(array $user): bool
    {
        return isset($user['role_id']) && self::roleName((int)$user['role_id']) === 'system_admin';
    }
}
