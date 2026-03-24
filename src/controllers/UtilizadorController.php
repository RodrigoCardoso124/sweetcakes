<?php
include_once __DIR__ . '/../models/Utilizador.php';
include_once __DIR__ . '/../models/Pessoa.php';
include_once __DIR__ . '/../models/Funcionario.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/PasswordHelper.php';

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
        if (!isset($data['pessoas_pessoa_id'], $data['password'])) {
            http_response_code(400);
            echo json_encode(['message' => 'pessoas_pessoa_id e password são obrigatórios']);

            return;
        }

        $this->pessoa->pessoa_id = $data['pessoas_pessoa_id'];
        $stmt = $this->pessoa->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
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
            echo json_encode(['message' => 'Utilizador criado com sucesso']);
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
