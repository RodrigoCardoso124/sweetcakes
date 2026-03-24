<?php

class Auth
{
    private static ?array $appConfig = null;

    public static function setConfig(array $config): void
    {
        self::$appConfig = $config;
    }

    private static function cfg(string $key, $default = null)
    {
        return self::$appConfig[$key] ?? $default;
    }

    /**
     * HTTPS visto pelo PHP (InfinityFree / proxies muitas vezes não preenchem HTTPS=on).
     */
    private static function requestIsHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }

        return false;
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name = self::cfg('session_name', 'SWEETCAKESSESSID');
        session_name($name);

        $secure = (self::cfg('app_env') === 'production');
        if (PHP_SAPI === 'cli') {
            $secure = false;
        }
        if (self::requestIsHttps()) {
            $secure = true;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!empty($_SERVER['HTTP_X_SESSION_ID']) && is_string($_SERVER['HTTP_X_SESSION_ID'])) {
            $sid = $_SERVER['HTTP_X_SESSION_ID'];
            if (preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sid)) {
                session_id($sid);
            }
        }

        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::startSession();
        return !empty($_SESSION['pessoa_id']) && !empty($_SESSION['utilizador_id']);
    }

    public static function isAdmin(): bool
    {
        self::startSession();
        return !empty($_SESSION['is_admin']) && !empty($_SESSION['funcionario_id']);
    }

    public static function pessoaId(): ?int
    {
        self::startSession();
        return isset($_SESSION['pessoa_id']) ? (int) $_SESSION['pessoa_id'] : null;
    }

    public static function utilizadorId(): ?int
    {
        self::startSession();
        return isset($_SESSION['utilizador_id']) ? (int) $_SESSION['utilizador_id'] : null;
    }

    public static function funcionarioId(): ?int
    {
        self::startSession();
        return isset($_SESSION['funcionario_id']) ? (int) $_SESSION['funcionario_id'] : null;
    }

    public static function loginFromUserRow(
        array $userRow,
        array $pessoaRow,
        ?array $funcionarioRow
    ): void {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['utilizador_id'] = (int) $userRow['utilizador_id'];
        $_SESSION['pessoa_id'] = (int) $pessoaRow['pessoa_id'];
        $_SESSION['is_admin'] = (bool) $funcionarioRow;
        $_SESSION['funcionario_id'] = $funcionarioRow ? (int) $funcionarioRow['funcionario_id'] : null;
    }

    public static function destroySession(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
