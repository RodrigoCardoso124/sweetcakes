<?php
include_once __DIR__ . "/../models/Utilizador.php";
include_once __DIR__ . "/../models/Pessoa.php";
include_once __DIR__ . "/../models/Funcionario.php";
include_once __DIR__ . "/../helpers/email_helper.php";

class UtilizadorController {
    private $db;
    private $utilizador;
    private $pessoa;
    private $funcionario;

    public function __construct($db) {
        $this->db = $db;
        $this->utilizador = new Utilizador($db);
        $this->pessoa = new Pessoa($db);
        $this->funcionario = new Funcionario($db);
    }

    private function columnExists(string $table, string $column): bool {
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

    private function ensureEmailVerificationSchema() {
        try {
            if (!$this->columnExists('pessoas', 'email_verificado')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN email_verificado TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!$this->columnExists('pessoas', 'email_verificacao_codigo')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN email_verificacao_codigo VARCHAR(128) NULL DEFAULT NULL");
            }
            if (!$this->columnExists('pessoas', 'email_verificacao_data')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN email_verificacao_data DATETIME NULL DEFAULT NULL");
            }
            // Token de link (64 hex); alarga se a BD ainda tiver VARCHAR(8) antigo.
            if ($this->columnExists('pessoas', 'email_verificacao_codigo')) {
                $this->db->exec("ALTER TABLE pessoas MODIFY COLUMN email_verificacao_codigo VARCHAR(128) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log("ensureEmailVerificationSchema: " . $e->getMessage());
        }
    }

    private function ensurePasswordResetSchema() {
        try {
            if (!$this->columnExists('pessoas', 'password_reset_codigo')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN password_reset_codigo VARCHAR(64) NULL DEFAULT NULL");
            }
            if (!$this->columnExists('pessoas', 'password_reset_data')) {
                $this->db->exec("ALTER TABLE pessoas ADD COLUMN password_reset_data DATETIME NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log("ensurePasswordResetSchema: " . $e->getMessage());
        }
    }

    private function loadAppConfig(): array {
        $file = __DIR__ . '/../config/app_config.php';
        if (!file_exists($file)) return [];
        $cfg = require $file;
        return is_array($cfg) ? $cfg : [];
    }

    /** Link mágico: token opaco no parâmetro `token` (sem código visível no email). */
    private function buildVerificationUrl($email, $token) {
        $appConfig = $this->loadAppConfig();
        $configuredBase = trim((string)($appConfig['public_api_base_url'] ?? ''));

        if ($configuredBase !== '') {
            $base = rtrim($configuredBase, '/');
            return "{$base}?route=verify_email&email=" . urlencode($email) . "&token=" . urlencode($token);
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        return "{$scheme}://{$host}{$scriptName}?route=verify_email&email=" . urlencode($email) . "&token=" . urlencode($token);
    }

    private function buildResetPasswordUrl($email, $token) {
        $appConfig = $this->loadAppConfig();
        $configuredBase = trim((string)($appConfig['public_api_base_url'] ?? ''));

        if ($configuredBase !== '') {
            $base = rtrim($configuredBase, '/');
            return "{$base}?route=reset_password&email=" . urlencode($email) . "&token=" . urlencode($token);
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        return "{$scheme}://{$host}{$scriptName}?route=reset_password&email=" . urlencode($email) . "&token=" . urlencode($token);
    }

    /** Gera e grava api_token (requer coluna api_token na tabela utilizadores). */
    private function issueApiTokenForPessoa($pessoaId) {
        try {
            $token = bin2hex(random_bytes(32));
            $this->utilizador->setApiTokenForPessoa($pessoaId, $token);
            return $token;
        } catch (Throwable $e) {
            error_log("issueApiToken: " . $e->getMessage());
            return null;
        }
    }

    private function hashPassword(string $plainPassword): string {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    /**
     * Valida password com suporte a migração transparente de contas antigas.
     * - Novo formato: hash (password_verify)
     * - Legado: texto simples (com upgrade automático para hash no login)
     */
    private function verifyPasswordAndUpgradeIfNeeded(array $user, string $plainPassword, int $pessoaId): bool {
        $storedPassword = (string)($user['password'] ?? '');

        if ($storedPassword === '') {
            return false;
        }

        $isHash = password_get_info($storedPassword)['algo'] !== null;
        if ($isHash) {
            $ok = password_verify($plainPassword, $storedPassword);
            if ($ok && password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
                $this->utilizador->updatePasswordByPessoaId($pessoaId, $this->hashPassword($plainPassword));
            }
            return $ok;
        }

        // Compatibilidade com registos antigos em texto simples.
        if (hash_equals($storedPassword, $plainPassword)) {
            $this->utilizador->updatePasswordByPessoaId($pessoaId, $this->hashPassword($plainPassword));
            return true;
        }

        return false;
    }

    // ------------------------
    public function show($id) {
        $this->utilizador->utilizador_id = $id;
        $stmt = $this->utilizador->getById();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            echo json_encode($user);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Utilizador não encontrado"]);
        }
    }

    // ------------------------
    public function store($data) {
        $this->ensureEmailVerificationSchema();
        if (!isset($data['pessoas_pessoa_id'], $data['password'])) {
            http_response_code(400);
            echo json_encode(["message" => "pessoas_pessoa_id e password são obrigatórios"]);
            return;
        }

        // verificar pessoa
        $this->pessoa->pessoa_id = $data['pessoas_pessoa_id'];
        $stmt = $this->pessoa->getById();
        $pessoaData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pessoaData) {
            http_response_code(400);
            echo json_encode(["message" => "Pessoa não encontrada"]);
            return;
        }

        // verificar se já existe utilizador para esta pessoa
        $this->utilizador->pessoas_pessoa_id = $data['pessoas_pessoa_id'];
        if ($this->utilizador->existsByPessoaID()) {
            http_response_code(400);
            echo json_encode(["message" => "Esta pessoa já possui um utilizador"]);
            return;
        }

        // Guardar sempre em hash.
        $this->utilizador->password = $this->hashPassword((string)$data['password']);
        $this->utilizador->pessoas_pessoa_id = $data['pessoas_pessoa_id'];

        if ($this->utilizador->create()) {
            $verificationToken = bin2hex(random_bytes(32));
            $this->pessoa->setEmailVerificationCode((int)$data['pessoas_pessoa_id'], $verificationToken);
            $verificationUrl = $this->buildVerificationUrl($pessoaData['email'] ?? '', $verificationToken);
            $emailSent = enviar_email_verificacao(
                $pessoaData['email'] ?? '',
                $pessoaData['nome'] ?? '',
                $verificationUrl
            );
            echo json_encode([
                "message" => "Utilizador criado com sucesso",
                "email_verification_required" => true,
                "email_sent" => $emailSent
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar utilizador"]);
        }
    }

    // ------------------------
    public function login($data) {
        $this->ensureEmailVerificationSchema();
        if (!isset($data['email'], $data['password'])) {
            http_response_code(400);
            echo json_encode(["message" => "email e password são obrigatórios"]);
            return;
        }

        // 1 — Buscar pessoa pelo email
        $this->pessoa->email = $data['email'];
        $stmt = $this->pessoa->getByEmail();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pessoa) {
            http_response_code(400);
            echo json_encode(["message" => "Email não encontrado"]);
            return;
        }

        // 2 — Buscar utilizador desta pessoa
        $this->utilizador->pessoas_pessoa_id = $pessoa['pessoa_id'];
        $stmt = $this->utilizador->getByPessoaId();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(400);
            echo json_encode(["message" => "Esta pessoa não tem conta"]);
            return;
        }

        if (isset($pessoa['email_verificado']) && (int)$pessoa['email_verificado'] !== 1) {
            http_response_code(403);
            echo json_encode([
                "message" => "Precisa de verificar o email antes de iniciar sessão"
            ]);
            return;
        }

        // 3 — Validar hash (com migração automática de contas legadas).
        if (!$this->verifyPasswordAndUpgradeIfNeeded($user, (string)$data['password'], (int)$pessoa['pessoa_id'])) {
            http_response_code(401);
            echo json_encode(["message" => "Password incorreta"]);
            return;
        }

        // 4 — Buscar funcionário se existir
        $this->funcionario->pessoas_pessoa_id = $pessoa['pessoa_id'];
        $stmt = $this->funcionario->getByPessoaId();
        $func = $stmt->fetch(PDO::FETCH_ASSOC);

        $apiToken = $this->issueApiTokenForPessoa((int) $pessoa['pessoa_id']);

        echo json_encode([
            "success" => true,
            "message" => "Login efetuado com sucesso",
            "api_token" => $apiToken,
            "utilizador" => [
                "utilizador_id" => $user['utilizador_id'],
                "pessoa_id" => $pessoa['pessoa_id'],
                "nome"       => $pessoa['nome'],
                "email"      => $pessoa['email'],
                "funcionario"=> $func ? $func : null
            ]
        ]);
    }

    // ------------------------
    // Admin Login - Only for funcionarios
    // ------------------------
    public function adminLogin($data) {
        // Debug: Log received data (remove in production)
        // error_log("AdminLogin called with data: " . print_r($data, true));
        
        if (!isset($data['email'], $data['password'])) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "email e password são obrigatórios"
            ]);
            return;
        }

        // 1 — Buscar pessoa pelo email
        $this->pessoa->email = $data['email'];
        $stmt = $this->pessoa->getByEmail();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pessoa) {
            http_response_code(401);
            echo json_encode([
                "success" => false,
                "message" => "Credenciais inválidas"
            ]);
            return;
        }

        // 2 — Buscar utilizador desta pessoa
        $this->utilizador->pessoas_pessoa_id = $pessoa['pessoa_id'];
        $stmt = $this->utilizador->getByPessoaId();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            echo json_encode([
                "success" => false,
                "message" => "Credenciais inválidas"
            ]);
            return;
        }

        // 3 — Validar hash (com migração automática de contas legadas).
        if (!$this->verifyPasswordAndUpgradeIfNeeded($user, (string)$data['password'], (int)$pessoa['pessoa_id'])) {
            http_response_code(401);
            echo json_encode([
                "success" => false,
                "message" => "Credenciais inválidas"
            ]);
            return;
        }

        // 4 — VERIFICAR SE É FUNCIONÁRIO (OBRIGATÓRIO PARA ADMIN)
        $this->funcionario->pessoas_pessoa_id = $pessoa['pessoa_id'];
        $stmt = $this->funcionario->getByPessoaId();
        $func = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$func) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "message" => "Acesso negado. Apenas funcionários podem aceder ao painel de administração."
            ]);
            return;
        }

        // 5 — Login bem-sucedido (é funcionário)
        $apiToken = $this->issueApiTokenForPessoa((int) $pessoa['pessoa_id']);

        echo json_encode([
            "success" => true,
            "message" => "Login efetuado com sucesso",
            "api_token" => $apiToken,
            "utilizador" => [
                "utilizador_id" => $user['utilizador_id'],
                "pessoa_id" => $pessoa['pessoa_id'],
                "nome"       => $pessoa['nome'],
                "email"      => $pessoa['email'],
                "funcionario" => $func
            ]
        ]);
    }

    // ------------------------
    // Reset Password (cliente app)
    // ------------------------
    public function resetPassword($data) {
        $this->ensurePasswordResetSchema();
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['email']) && isset($_POST['email'])) {
            $data['email'] = $_POST['email'];
        }
        if (!isset($data['email'])) {
            http_response_code(400);
            echo json_encode(["message" => "email é obrigatório"]);
            return;
        }

        $email = trim((string) $data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["message" => "Email inválido"]);
            return;
        }

        $this->pessoa->email = $email;
        $stmt = $this->pessoa->getByEmail();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pessoa) {
            http_response_code(404);
            echo json_encode(["message" => "Email não encontrado"]);
            return;
        }

        $code = bin2hex(random_bytes(16));
        $upd = $this->db->prepare("UPDATE pessoas SET password_reset_codigo = :c, password_reset_data = NOW() WHERE pessoa_id = :id");
        $upd->bindValue(":c", $code);
        $upd->bindValue(":id", (int)$pessoa['pessoa_id'], PDO::PARAM_INT);
        $upd->execute();

        $resetUrl = $this->buildResetPasswordUrl($email, $code);
        $emailSent = enviar_email_reset_password(
            $email,
            $pessoa['nome'] ?? '',
            $resetUrl
        );

        if (!$emailSent) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Não foi possível enviar o email de redefinição. Tenta novamente em instantes."
            ]);
            return;
        }

        echo json_encode([
            "success" => true,
            "message" => "Se o email existir, enviámos um link para redefinir a password",
            "email_sent" => true
        ]);
    }

    public function resetPasswordLink($email, $code) {
        $this->ensurePasswordResetSchema();
        $email = trim((string)$email);
        $code = trim((string)$code);
        header("Content-Type: text/html; charset=UTF-8");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Link inválido</h2><p>O link para redefinir password é inválido.</p></div></body></html>";
            return;
        }

        $stmt = $this->db->prepare("SELECT pessoa_id, password_reset_codigo, password_reset_data FROM pessoas WHERE email = :email LIMIT 1");
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        $pessoa = $stmt->fetch(PDO::FETCH_ASSOC);
        $isValid = $pessoa &&
            !empty($pessoa['password_reset_codigo']) &&
            hash_equals((string)$pessoa['password_reset_codigo'], $code) &&
            !empty($pessoa['password_reset_data']) &&
            (strtotime((string)$pessoa['password_reset_data']) >= (time() - 3600));

        if (!$isValid) {
            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Link expirado ou inválido</h2><p>Peça um novo reset de password na app.</p></div></body></html>";
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $postedToken = trim((string)($_POST['token'] ?? $_POST['code'] ?? ''));
            if ($postedToken === '' || !hash_equals($code, $postedToken)) {
                echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Sessão inválida</h2><p>Volte a abrir o link do email.</p></div></body></html>";
                return;
            }
            $newPassword = trim((string)($_POST['new_password'] ?? ''));
            $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

            if ($newPassword === '' || strlen($newPassword) < 4 || $newPassword !== $confirmPassword) {
                echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Password inválida</h2><p>A password deve ter pelo menos 4 caracteres e os campos devem coincidir.</p></div></body></html>";
                return;
            }

            $ok = $this->utilizador->updatePasswordByPessoaId((int)$pessoa['pessoa_id'], $this->hashPassword($newPassword));
            if ($ok) {
                $clr = $this->db->prepare("UPDATE pessoas SET password_reset_codigo = NULL, password_reset_data = NULL WHERE pessoa_id = :id");
                $clr->bindValue(':id', (int)$pessoa['pessoa_id'], PDO::PARAM_INT);
                $clr->execute();
                echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#faf7ff;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#5B25F0;'>Password alterada com sucesso</h2><p>Já pode voltar à app e iniciar sessão com a nova password.</p></div></body></html>";
                return;
            }

            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Erro ao atualizar</h2><p>Tente novamente mais tarde.</p></div></body></html>";
            return;
        }

        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeToken = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#faf7ff;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#5B25F0;'>Redefinir password</h2><p>Email: {$safeEmail}</p><form method='post'><label>Nova password</label><br><input type='password' name='new_password' minlength='4' required style='width:100%;padding:10px;margin:8px 0 14px;border:1px solid #ddd;border-radius:8px;'><label>Confirmar password</label><br><input type='password' name='confirm_password' minlength='4' required style='width:100%;padding:10px;margin:8px 0 18px;border:1px solid #ddd;border-radius:8px;'><input type='hidden' name='email' value='{$safeEmail}'><input type='hidden' name='token' value='{$safeToken}'><button type='submit' style='background:#5B25F0;color:#fff;border:none;padding:12px 18px;border-radius:8px;font-weight:700;cursor:pointer;'>Guardar nova password</button></form></div></body></html>";
    }

    public function verifyEmail($data) {
        $this->ensureEmailVerificationSchema();
        $token = trim((string)($data['token'] ?? $data['code'] ?? ''));
        if (!isset($data['email']) || $token === '') {
            http_response_code(400);
            echo json_encode(["message" => "email e token são obrigatórios"]);
            return;
        }

        $email = trim((string)$data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["message" => "Dados inválidos"]);
            return;
        }

        $ok = $this->pessoa->verifyEmailWithCode($email, $token);
        if (!$ok) {
            http_response_code(400);
            echo json_encode(["message" => "Código de verificação inválido"]);
            return;
        }

        echo json_encode(["success" => true, "message" => "Email verificado com sucesso"]);
    }

    public function verifyEmailLink($email, $token) {
        $this->ensureEmailVerificationSchema();
        $ok = $this->pessoa->verifyEmailWithCode($email, $token);
        header("Content-Type: text/html; charset=UTF-8");
        if ($ok) {
            echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#faf7ff;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#5B25F0;'>Email confirmado com sucesso</h2><p>A sua conta já está ativa. Pode voltar à app e iniciar sessão.</p></div></body></html>";
            return;
        }
        echo "<!doctype html><html><body style='font-family:Arial,sans-serif;background:#fff7f7;padding:24px;'><div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;'><h2 style='color:#b00020;'>Não foi possível confirmar</h2><p>O link é inválido ou já expirou.</p></div></body></html>";
    }
}
?>
