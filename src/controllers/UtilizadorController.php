<?php
include_once __DIR__ . '/../models/Utilizador.php';
include_once __DIR__ . '/../models/Pessoa.php';
include_once __DIR__ . '/../models/Funcionario.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/PasswordHelper.php';
require_once __DIR__ . '/../helpers/email_helper.php';

class UtilizadorController
{
    private $db;
    private $utilizador;
    private $pessoa;
    private $funcionario;

    public function __construct($db)
    {
        $this->db = $db;
        $this->utilizador = new Utilizador($db);
        $this->pessoa = new Pessoa($db);
        $this->funcionario = new Funcionario($db);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
            $stmt->bindValue(':column', $column);
            $stmt->execute();
            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("columnExists({$table}.{$column}): " . $e->getMessage());
            return false;
        }
    }

    private function ensureEmailVerificationSchema(): void
    {
        try {
            if (!$this->columnExists('pessoas', 'email_verificado')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN email_verificado TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!$this->columnExists('pessoas', 'email_verificacao_codigo')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN email_verificacao_codigo VARCHAR(8) NULL DEFAULT NULL");
            }
            if (!$this->columnExists('pessoas', 'email_verificacao_data')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN email_verificacao_data DATETIME NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('ensureEmailVerificationSchema: ' . $e->getMessage());
        }
    }

    private function ensurePasswordResetSchema(): void
    {
        try {
            if (!$this->columnExists('pessoas', 'password_reset_codigo')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN password_reset_codigo VARCHAR(64) NULL DEFAULT NULL");
            }
            if (!$this->columnExists('pessoas', 'password_reset_data')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN password_reset_data DATETIME NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('ensurePasswordResetSchema: ' . $e->getMessage());
        }
    }

    private function loadAppConfig(): array
    {
        $file = __DIR__ . '/../config/app_config.php';
        if (!file_exists($file)) {
            return [];
        }
        $cfg = require $file;
        return is_array($cfg) ? $cfg : [];
    }

    private function buildVerificationUrl(string $email, string $code): string
    {
        $appConfig = $this->loadAppConfig();
        $configuredBase = trim((string) ($appConfig['public_api_base_url'] ?? ''));
        if ($configuredBase !== '') {
            $base = rtrim($configuredBase, '/');
            return "{$base}?route=verify_email&email=" . urlencode($email) . '&code=' . urlencode($code);
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
        return "{$scheme}://{$host}{$scriptName}?route=verify_email&email=" . urlencode($email) . '&code=' . urlencode($code);
    }

    private function buildResetPasswordUrl(string $email, string $token): string
    {
        $appConfig = $this->loadAppConfig();
        $configuredBase = trim((string) ($appConfig['public_api_base_url'] ?? ''));
        if ($configuredBase !== '') {
            $base = rtrim($configuredBase, '/');
            return "{$base}?route=reset_password&email=" . urlencode($email) . '&token=' . urlencode($token);
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
        return "{$scheme}://{$host}{$scriptName}?route=reset_password&email=" . urlencode($email) . '&token=' . urlencode($token);
    }

    private function reissueVerificationEmail(array $pessoa): bool
    {
        $pessoaId = (int) ($pessoa['pessoa_id'] ?? 0);
        $email = trim((string) ($pessoa['email'] ?? ''));
        if ($pessoaId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ok = $this->pessoa->setEmailVerificationCode($pessoaId, $verificationCode);
        if (!$ok) {
            return false;
        }

        $verificationUrl = $this->buildVerificationUrl($email, $verificationCode);
        return enviar_email_verificacao(
            $email,
            (string) ($pessoa['nome'] ?? ''),
            $verificationUrl
        );
    }

    private function jsonSessionPayload(array $user, array $pessoa, ?array $func, string $sessionToken): array
    {
        return [
            'session_id' => $sessionToken,
            'utilizador' => [
                'utilizador_id' => $user['utilizador_id'],
                'pessoa_id' => $pessoa['pessoa_id'],
                'nome' => $pessoa['nome'],
                'email' => $pessoa['email'],
                'funcionario' => $func ?: null,
            ],
        ];
    }

    private function emitDebugLog(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        $line = [
            'sessionId' => '6bdd51',
            'runId' => 'pre-fix',
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        @file_put_contents(__DIR__ . '/../../debug-6bdd51.log', json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    }

    public function show($id)
    {
        $this->utilizador->utilizador_id = $id;
        $stmt = $this->utilizador->getById();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            unset($user['password']);
            echo json_encode($user);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Utilizador não encontrado']);
        }
    }

    public function store($data)
    {
        $this->ensureEmailVerificationSchema();
        if (!isset($data['pessoas_pessoa_id'], $data['password'])) {
            http_response_code(400);
            echo json_encode(['message' => 'pessoas_pessoa_id e password são obrigatórios']);

            return;
        }

        $this->pessoa->pessoa_id = $data['pessoas_pessoa_id'];
        $stmt = $this->pessoa->getById();
        $pessoaData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pessoaData) {
            http_response_code(400);
            echo json_encode(['message' => 'Pessoa não encontrada']);

            return;
        }

        $this->utilizador->pessoas_pessoa_id = $data['pessoas_pessoa_id'];
        if ($this->utilizador->existsByPessoaID()) {
            http_response_code(400);
            echo json_encode(['message' => 'Esta pessoa já possui um utilizador']);

            return;
        }

        $this->utilizador->password = PasswordHelper::hash($data['password']);
        $this->utilizador->pessoas_pessoa_id = $data['pessoas_pessoa_id'];

        if ($this->utilizador->create()) {
            $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->pessoa->setEmailVerificationCode((int) $data['pessoas_pessoa_id'], $verificationCode);
            $verificationUrl = $this->buildVerificationUrl((string) ($pessoaData['email'] ?? ''), $verificationCode);
            $emailSent = enviar_email_verificacao(
                (string) ($pessoaData['email'] ?? ''),
                (string) ($pessoaData['nome'] ?? ''),
                $verificationUrl
            );

            echo json_encode([
                'message' => 'Utilizador criado com sucesso',
                'email_verification_required' => true,
                'email_sent' => $emailSent,
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar utilizador']);
        }
    }

    private function verifyAndMaybeRehash(string $plain, string $stored, int $pessoaId): bool
    {
        $ok = PasswordHelper::verify($plain, $stored);
        if ($ok === true) {
            return true;
        }
        if ($ok === 'legacy_rehash') {
            $this->utilizador->updatePasswordByPessoaId($pessoaId, PasswordHelper::hash($plain));

            return true;
        }

        return false;
    }

    private function findMatchingPessoaUser(string $identifier, string $plainPassword): ?array
    {
        $this->pessoa->email = $identifier;
        $stmt = $this->pessoa->getAllByLoginIdentifier();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $pessoa) {
            $this->utilizador->pessoas_pessoa_id = $pessoa['pessoa_id'];
            $uStmt = $this->utilizador->getAllByPessoaId();
            $users = $uStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($users)) {
                continue;
            }
            foreach ($users as $user) {
                if ($this->verifyAndMaybeRehash((string) $plainPassword, (string) $user['password'], (int) $pessoa['pessoa_id'])) {
                    return ['pessoa' => $pessoa, 'user' => $user];
                }
            }
        }

        return null;
    }

    private function getLoginDebugInfo(string $identifier, string $plainPassword): array
    {
        $this->pessoa->email = $identifier;
        $stmt = $this->pessoa->getAllByLoginIdentifier();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [
            'identifier' => $identifier,
            'candidate_pessoas' => count($candidates),
            'with_user' => 0,
            'password_match' => 0,
            'entered_password_len' => strlen($plainPassword),
            'entered_is_ascii_digits' => preg_match('/^[0-9]+$/', $plainPassword) === 1,
            'pessoa_ids' => [],
        ];

        foreach ($candidates as $pessoa) {
            $out['pessoa_ids'][] = (int) $pessoa['pessoa_id'];
            $this->utilizador->pessoas_pessoa_id = $pessoa['pessoa_id'];
            $uStmt = $this->utilizador->getAllByPessoaId();
            $users = $uStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($users)) {
                continue;
            }
            $out['with_user'] += count($users);
            $out['user_password_meta'] = [];
            foreach ($users as $user) {
                $stored = (string) ($user['password'] ?? '');
                $trimmed = trim(trim($stored), "\"'");
                $format = 'unknown';
                if (preg_match('/^\$2[aby]\$/', $trimmed)) {
                    $format = 'bcrypt';
                } elseif (preg_match('/^\$argon2/i', $trimmed)) {
                    $format = 'argon2';
                } elseif (preg_match('/^[a-f0-9]{32}$/i', $trimmed)) {
                    $format = 'md5';
                } elseif (preg_match('/^[a-f0-9]{40}$/i', $trimmed)) {
                    $format = 'sha1';
                } elseif ($trimmed !== '') {
                    $format = 'plaintext_or_other';
                }
                $out['user_password_meta'][] = [
                    'utilizador_id' => isset($user['utilizador_id']) ? (int) $user['utilizador_id'] : null,
                    'stored_len' => strlen($stored),
                    'trimmed_len' => strlen($trimmed),
                    'format' => $format,
                ];
                if ($this->verifyAndMaybeRehash((string) $plainPassword, (string) $user['password'], (int) $pessoa['pessoa_id'])) {
                    $out['password_match']++;
                }
            }
        }

        return $out;
    }

    public function login($data)
    {
        $this->ensureEmailVerificationSchema();
        if (!isset($data['email'], $data['password'])) {
            http_response_code(400);
            echo json_encode(['message' => 'email e password são obrigatórios']);

            return;
        }

        $email = strtolower(trim((string) $data['email']));
        $match = $this->findMatchingPessoaUser($email, (string) $data['password']);
        if (!$match) {
            http_response_code(401);
            echo json_encode(['message' => 'Password incorreta']);

            return;
        }
        $pessoa = $match['pessoa'];
        $user = $match['user'];

        if (isset($pessoa['email_verificado']) && (int) $pessoa['email_verificado'] !== 1) {
            $emailSent = $this->reissueVerificationEmail($pessoa);
            http_response_code(403);
            echo json_encode([
                'message' => 'Precisa de verificar o email antes de iniciar sessão',
                'verification_email_resent' => $emailSent,
            ]);
            return;
        }

        $this->funcionario->pessoas_pessoa_id = $pessoa['pessoa_id'];
        $stmt = $this->funcionario->getByPessoaId();
        $func = $stmt->fetch(PDO::FETCH_ASSOC);

        Auth::loginFromUserRow($user, $pessoa, $func ?: null);
        $sessionToken = Auth::issueSessionToken($user, $pessoa, $func ?: null);

        echo json_encode(array_merge([
            'success' => true,
            'message' => 'Login efetuado com sucesso',
        ], $this->jsonSessionPayload($user, $pessoa, $func ?: null, $sessionToken)));
    }

    public function verifyEmail($data)
    {
        $this->ensureEmailVerificationSchema();
        if (!isset($data['email'], $data['code'])) {
            http_response_code(400);
            echo json_encode(['message' => 'email e code são obrigatórios']);
            return;
        }

        $email = trim((string) $data['email']);
        $code = trim((string) $data['code']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
            http_response_code(400);
            echo json_encode(['message' => 'Dados inválidos']);
            return;
        }

        $ok = $this->pessoa->verifyEmailWithCode($email, $code);
        if (!$ok) {
            http_response_code(400);
            echo json_encode(['message' => 'Código de verificação inválido']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Email verificado com sucesso']);
    }

    public function resendVerificationEmail($data): void
    {
        $this->ensureEmailVerificationSchema();
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['email']) && isset($_POST['email'])) {
            $data['email'] = $_POST['email'];
        }
        if (!isset($data['email'])) {
            http_response_code(400);
            echo json_encode(['message' => 'email é obrigatório']);
            return;
        }

        $email = trim((string) $data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['message' => 'Email inválido']);
            return;
        }

        $this->pessoa->email = $email;
        $stmt = $this->pessoa->getByEmail();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pessoa) {
            echo json_encode([
                'success' => true,
                'message' => 'Se o email existir, enviámos uma nova verificação',
                'email_sent' => true,
            ]);
            return;
        }

        if (isset($pessoa['email_verificado']) && (int) $pessoa['email_verificado'] === 1) {
            echo json_encode([
                'success' => true,
                'message' => 'Este email já está verificado',
                'email_sent' => false,
            ]);
            return;
        }

        $emailSent = $this->reissueVerificationEmail($pessoa);
        if (!$emailSent) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Não foi possível reenviar o email de verificação. Tenta novamente em instantes.',
            ]);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Enviámos uma nova verificação para o teu email',
            'email_sent' => true,
        ]);
    }

    public function resetPassword($data): void
    {
        $this->ensurePasswordResetSchema();
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['email']) && isset($_POST['email'])) {
            $data['email'] = $_POST['email'];
        }
        if (!isset($data['email'])) {
            http_response_code(400);
            echo json_encode(['message' => 'email é obrigatório']);
            return;
        }

        $email = trim((string) $data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['message' => 'Email inválido']);
            return;
        }

        $this->pessoa->email = $email;
        $stmt = $this->pessoa->getByEmail();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pessoa) {
            http_response_code(404);
            echo json_encode(['message' => 'Email não encontrado']);
            return;
        }

        $token = bin2hex(random_bytes(16));
        $upd = $this->db->prepare('UPDATE pessoas SET password_reset_codigo = :c, password_reset_data = NOW() WHERE pessoa_id = :id');
        $upd->bindValue(':c', $token);
        $upd->bindValue(':id', (int) $pessoa['pessoa_id'], PDO::PARAM_INT);
        $upd->execute();

        $resetUrl = $this->buildResetPasswordUrl($email, $token);
        $emailSent = enviar_email_reset_password(
            $email,
            (string) ($pessoa['nome'] ?? ''),
            $resetUrl
        );

        if (!$emailSent) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Não foi possível enviar o email de redefinição. Tenta novamente em instantes.',
            ]);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Se o email existir, enviámos um link para redefinir a password',
            'email_sent' => true,
        ]);
    }

    public function resetPasswordLink(string $email, string $token): void
    {
        $this->ensurePasswordResetSchema();
        $email = trim($email);
        $token = trim($token);
        header('Content-Type: text/html; charset=UTF-8');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Link inválido</h2><p>O link para redefinir password é inválido.</p></div></body></html>";
            return;
        }

        $stmt = $this->db->prepare('SELECT pessoa_id, password_reset_codigo, password_reset_data FROM pessoas WHERE email = :email LIMIT 1');
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);
        $isValid = $pessoa
            && !empty($pessoa['password_reset_codigo'])
            && hash_equals((string) $pessoa['password_reset_codigo'], $token)
            && !empty($pessoa['password_reset_data'])
            && (strtotime((string) $pessoa['password_reset_data']) >= (time() - 3600));

        if (!$isValid) {
            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Link expirado ou inválido</h2><p>Peça um novo reset de password na app.</p></div></body></html>";
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $postedToken = trim((string) ($_POST['token'] ?? $_POST['code'] ?? ''));
            if ($postedToken === '' || !hash_equals($token, $postedToken)) {
                echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Sessão inválida</h2><p>Volte a abrir o link do email.</p></div></body></html>";
                return;
            }
            $newPassword = trim((string) ($_POST['new_password'] ?? ''));
            $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

            if ($newPassword === '' || strlen($newPassword) < 4 || $newPassword !== $confirmPassword) {
                echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Password inválida</h2><p>A password deve ter pelo menos 4 caracteres e os campos devem coincidir.</p></div></body></html>";
                return;
            }

            $hashed = PasswordHelper::hash($newPassword);
            $ok = $this->utilizador->updatePasswordByPessoaId((int) $pessoa['pessoa_id'], $hashed);
            if ($ok) {
                $clr = $this->db->prepare('UPDATE pessoas SET password_reset_codigo = NULL, password_reset_data = NULL WHERE pessoa_id = :id');
                $clr->bindValue(':id', (int) $pessoa['pessoa_id'], PDO::PARAM_INT);
                $clr->execute();
                echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#faf7ff;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#5B25F0;'>Password alterada com sucesso</h2><p>Já pode voltar à app e iniciar sessão com a nova password.</p></div></body></html>";
                return;
            }

            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Erro ao atualizar</h2><p>Tente novamente mais tarde.</p></div></body></html>";
            return;
        }

        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#faf7ff;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#5B25F0;'>Redefinir password</h2><p>Email: {$safeEmail}</p><form method='post'><label>Nova password</label><br><input type='password' name='new_password' minlength='4' required style='width:100%;padding:10px;margin:8px 0 14px;border:1px solid #ddd;border-radius:8px;'><label>Confirmar password</label><br><input type='password' name='confirm_password' minlength='4' required style='width:100%;padding:10px;margin:8px 0 18px;border:1px solid #ddd;border-radius:8px;'><input type='hidden' name='email' value='{$safeEmail}'><input type='hidden' name='token' value='{$safeToken}'><button type='submit' style='background:#5B25F0;color:#fff;border:none;padding:12px 18px;border-radius:8px;font-weight:700;cursor:pointer;'>Guardar nova password</button></form></div></body></html>";
    }

    public function verifyEmailLink($email, $code)
    {
        $this->ensureEmailVerificationSchema();
        $ok = $this->pessoa->verifyEmailWithCode((string) $email, (string) $code);
        header('Content-Type: text/html; charset=UTF-8');
        if ($ok) {
            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#faf7ff;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#5B25F0;'>Email confirmado com sucesso</h2><p>A sua conta já está ativa. Pode voltar à app e iniciar sessão.</p></div></body></html>";
            return;
        }
        echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Não foi possível confirmar</h2><p>O link é inválido ou já expirou.</p></div></body></html>";
    }

    public function adminLogin($data)
    {
        // #region agent log
        $this->emitDebugLog('H3', 'UtilizadorController.php:173', 'Entrada adminLogin', [
            'has_email' => isset($data['email']),
            'has_password' => isset($data['password']),
            'email_identifier' => isset($data['email']) ? strtolower(trim((string) $data['email'])) : null,
        ]);
        // #endregion
        if (!isset($data['email'], $data['password'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'email e password são obrigatórios',
            ]);

            return;
        }

        $email = strtolower(trim((string) $data['email']));
        $match = $this->findMatchingPessoaUser($email, (string) $data['password']);
        if (!$match) {
            // #region agent log
            $dbg = $this->getLoginDebugInfo($email, (string) $data['password']);
            $this->emitDebugLog('H4', 'UtilizadorController.php:186', 'Falha credenciais adminLogin', $dbg);
            // #endregion
            $payload = [
                'success' => false,
                'message' => 'Credenciais inválidas',
            ];
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $payload['debug'] = $this->getLoginDebugInfo($email, (string) $data['password']);
                $payload['debug']['runtime_config'] = [
                    'app_env' => defined('SC_DEBUG_APP_ENV') ? SC_DEBUG_APP_ENV : null,
                    'app_debug' => defined('SC_DEBUG_APP_DEBUG') ? SC_DEBUG_APP_DEBUG : null,
                    'db_host' => defined('SC_DEBUG_DB_HOST') ? SC_DEBUG_DB_HOST : null,
                    'db_name' => defined('SC_DEBUG_DB_NAME') ? SC_DEBUG_DB_NAME : null,
                    'db_user' => defined('SC_DEBUG_DB_USER') ? SC_DEBUG_DB_USER : null,
                    'has_local_app_config' => file_exists(__DIR__ . '/../config/app_config.local.php'),
                    'has_local_db_config' => file_exists(__DIR__ . '/../config/database.local.php'),
                ];
            }
            http_response_code(401);
            echo json_encode($payload);

            return;
        }
        $pessoa = $match['pessoa'];
        $user = $match['user'];

        $this->funcionario->pessoas_pessoa_id = $pessoa['pessoa_id'];
        $stmt = $this->funcionario->getByPessoaId();
        $func = $stmt->fetch(PDO::FETCH_ASSOC);
        // #region agent log
        $this->emitDebugLog('H5', 'UtilizadorController.php:207', 'Match credenciais adminLogin', [
            'pessoa_id' => (int) $pessoa['pessoa_id'],
            'utilizador_id' => (int) $user['utilizador_id'],
            'is_funcionario' => !empty($func),
        ]);
        // #endregion

        if (!$func) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Acesso negado. Apenas funcionários podem aceder ao painel de administração.',
            ]);

            return;
        }

        Auth::loginFromUserRow($user, $pessoa, $func);
        $sessionToken = Auth::issueSessionToken($user, $pessoa, $func);

        echo json_encode(array_merge([
            'success' => true,
            'message' => 'Login efetuado com sucesso',
        ], $this->jsonSessionPayload($user, $pessoa, $func, $sessionToken)));
    }
}
