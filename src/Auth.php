<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = ((int) Config::get('SESSION_LIFETIME_MIN', '120')) * 60;
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => Config::baseUrl() . '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
            session_name('gymstudio_admin_session');
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        self::start();
        $stmt = Database::pdo()->prepare(
            'SELECT id, nome, cognome, email, password_hash, ruolo, attivo
             FROM users WHERE tenant_id = ? AND email = ? LIMIT 1'
        );
        $stmt->execute([Database::tenantId(), $email]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['attivo'] !== 1 || !password_verify($password, $user['password_hash'])) {
            AuditLogger::log('login_fallito', 'users', null, null, ['email' => $email]);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'nome' => $user['nome'],
            'cognome' => $user['cognome'],
            'email' => $user['email'],
            'ruolo' => $user['ruolo'],
        ];

        $upd = Database::pdo()->prepare('UPDATE users SET ultimo_accesso = NOW() WHERE id = ?');
        $upd->execute([$user['id']]);

        AuditLogger::log('login', 'users', (int) $user['id']);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        if (self::user()) {
            AuditLogger::log('logout', 'users', self::user()['id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['ruolo'], $roles, true);
    }

    /** Reindirizza al login se non autenticato. Da chiamare in cima alle pagine admin. */
    public static function requireLogin(string $loginUrl = 'login.php'): void
    {
        if (!self::check()) {
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    public static function requireRole(array $roles, string $forbiddenUrl = 'dashboard.php?errore=permessi'): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            header('Location: ' . $forbiddenUrl);
            exit;
        }
    }
}
