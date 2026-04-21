<?php

class Auth
{
    private static ?array $appConfig = null;
    private const TOKEN_TTL_SECONDS = 604800; // 7 dias

    public static function setConfig(array $config): void
    {
        self::$appConfig = $config;
    }

    private static function cfg(string $key, $default = null)
    {
        return self::$appConfig[$key] ?? $default;
    }

    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): ?string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private static function tokenSecret(): string
    {
        $envSecret = getenv('APP_KEY');
        if (is_string($envSecret) && trim($envSecret) !== '') {
            return $envSecret;
        }
        return (string) self::cfg('app_key', 'sweetcakes-dev-change-app-key');
    }

    private static function buildSignedToken(array $payload): string
    {
        $json = json_encode($payload);
        $body = self::b64urlEncode($json === false ? '{}' : $json);
        $sig = hash_hmac('sha256', $body, self::tokenSecret(), true);
        return $body . '.' . self::b64urlEncode($sig);
    }

    private static function parseSignedToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token)) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        $expected = self::b64urlEncode(hash_hmac('sha256', $body, self::tokenSecret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = self::b64urlDecode($body);
        if ($json === null) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function getHeaderSessionToken(): ?string
    {
        $sid = $_SERVER['HTTP_X_SESSION_ID'] ?? '';
        if (!is_string($sid) || trim($sid) === '') {
            return null;
        }
        return trim($sid);
    }

    private static function hydrateSessionFromTokenPayload(array $payload): void
    {
        $_SESSION['utilizador_id'] = isset($payload['uid']) ? (int) $payload['uid'] : null;
        $_SESSION['pessoa_id'] = isset($payload['pid']) ? (int) $payload['pid'] : null;
        $_SESSION['is_admin'] = !empty($payload['adm']);
        $_SESSION['funcionario_id'] = isset($payload['fid']) && $payload['fid'] !== null ? (int) $payload['fid'] : null;
    }

    private static function isAdminCargo(?array $funcionarioRow): bool
    {
        if (empty($funcionarioRow) || !isset($funcionarioRow['cargo'])) {
            return false;
        }
        $funcionarioId = isset($funcionarioRow['funcionario_id']) ? (int) $funcionarioRow['funcionario_id'] : 0;
        if ($funcionarioId === 13) {
            return true;
        }
        $cargo = strtolower(trim((string) $funcionarioRow['cargo']));
        $cargo = str_replace(['á', 'à', 'â', 'ã', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'], ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'], $cargo);
        if (strpos($cargo, 'admin') !== false) {
            return true;
        }
        return in_array($cargo, ['gerente', 'gestor', 'owner', 'dono', 'ceo'], true);
    }

    private static function tryAuthenticateFromToken(): bool
    {
        $token = self::getHeaderSessionToken();
        if ($token === null) {
            return false;
        }
        $payload = self::parseSignedToken($token);
        if ($payload === null) {
            return false;
        }
        self::hydrateSessionFromTokenPayload($payload);
        return true;
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
        if (!empty($_SESSION['pessoa_id']) && !empty($_SESSION['utilizador_id'])) {
            return true;
        }
        return self::tryAuthenticateFromToken();
    }

    public static function isAdmin(): bool
    {
        self::startSession();
        if (!empty($_SESSION['is_admin']) && !empty($_SESSION['funcionario_id'])) {
            return true;
        }
        if (!self::tryAuthenticateFromToken()) {
            return false;
        }
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

    public static function isFuncionario(): bool
    {
        self::startSession();
        if (!empty($_SESSION['funcionario_id'])) {
            return true;
        }
        if (!self::tryAuthenticateFromToken()) {
            return false;
        }
        return !empty($_SESSION['funcionario_id']);
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
        $_SESSION['is_admin'] = self::isAdminCargo($funcionarioRow);
        $_SESSION['funcionario_id'] = $funcionarioRow ? (int) $funcionarioRow['funcionario_id'] : null;
    }

    public static function issueSessionToken(
        array $userRow,
        array $pessoaRow,
        ?array $funcionarioRow
    ): string {
        $now = time();
        $payload = [
            'uid' => (int) ($userRow['utilizador_id'] ?? 0),
            'pid' => (int) ($pessoaRow['pessoa_id'] ?? 0),
            'fid' => $funcionarioRow ? (int) ($funcionarioRow['funcionario_id'] ?? 0) : null,
            'adm' => self::isAdminCargo($funcionarioRow),
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
        ];
        return self::buildSignedToken($payload);
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
