<?php
include_once __DIR__ . '/../models/Funcionario.php';
include_once __DIR__ . '/../models/Pessoa.php';
include_once __DIR__ . '/../models/Utilizador.php';
require_once __DIR__ . '/../helpers/PasswordHelper.php';

class FuncionarioController {
    private $db;
    private $funcionario;
    private $pessoa;
    private $utilizador;

    public function __construct($db) {
        $this->db = $db;
        $this->funcionario = new Funcionario($db);
        $this->pessoa = new Pessoa($db);
        $this->utilizador = new Utilizador($db);
    }

    public function index() {
        $stmt = $this->funcionario->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function show($id) {
        $this->funcionario->funcionario_id = $id;
        $stmt = $this->funcionario->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            http_response_code(404);
            echo json_encode(['message' => 'Funcionário não encontrado']);
            return;
        }

        $this->pessoa->pessoa_id = $data['pessoas_pessoa_id'];
        $pStmt = $this->pessoa->getById();
        $pessoa = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $data['pessoa'] = $pessoa;

        echo json_encode($data);
    }

    public function store($data) {
        if (!is_array($data)) {
            $data = [];
        }

        // Compatibilidade: só pessoa_id + cargo (fluxo antigo)
        if (!empty($data['pessoa_id']) && empty($data['nome']) && empty($data['email'])) {
            $this->storeFromExistingPessoa($data);
            return;
        }

        $this->storeNovoFuncionario($data);
    }

    private function storeFromExistingPessoa(array $data): void
    {
        if (empty($data['pessoa_id']) || empty($data['cargo'])) {
            http_response_code(400);
            echo json_encode(['message' => 'pessoa_id e cargo são obrigatórios']);
            return;
        }

        $pessoaId = (int) $data['pessoa_id'];
        $this->pessoa->pessoa_id = $pessoaId;
        if (!$this->pessoa->getById()->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'A pessoa indicada não existe']);
            return;
        }

        if ($this->funcionarioExistsForPessoa($pessoaId)) {
            http_response_code(400);
            echo json_encode(['message' => 'Esta pessoa já é funcionária']);
            return;
        }

        $password = trim((string) ($data['password'] ?? ''));
        if ($password === '') {
            http_response_code(400);
            echo json_encode(['message' => 'Password de acesso ao painel é obrigatória']);
            return;
        }

        try {
            $this->db->beginTransaction();
            $this->criarUtilizadorSeNecessario($pessoaId, $password);
            $this->marcarEmailVerificado($pessoaId);
            $funcionarioId = $this->inserirFuncionario($pessoaId, trim((string) $data['cargo']));
            $this->db->commit();
            http_response_code(201);
            echo json_encode([
                'message' => 'Funcionário criado com sucesso',
                'funcionario_id' => $funcionarioId,
                'pessoa_id' => $pessoaId,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar funcionário: ' . $e->getMessage()]);
        }
    }

    private function storeNovoFuncionario(array $data): void
    {
        $nome = trim((string) ($data['nome'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $cargo = trim((string) ($data['cargo'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));
        $telemovel = trim((string) ($data['telemovel'] ?? ''));
        $morada = trim((string) ($data['morada'] ?? ''));

        if ($nome === '' || $email === '' || $cargo === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['message' => 'Nome, email, cargo e password são obrigatórios']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['message' => 'Email inválido']);
            return;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['message' => 'A password deve ter pelo menos 8 caracteres']);
            return;
        }

        $this->pessoa->email = $email;
        if ($this->pessoa->getByEmail()->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Já existe uma pessoa com este email']);
            return;
        }

        try {
            $this->db->beginTransaction();

            $this->pessoa->nome = $nome;
            $this->pessoa->email = $email;
            $this->pessoa->telemovel = $telemovel !== '' ? $telemovel : '—';
            $this->pessoa->morada = $morada !== '' ? $morada : '—';
            $this->pessoa->nif = isset($data['nif']) ? trim((string) $data['nif']) : null;
            if (!$this->pessoa->create()) {
                throw new RuntimeException('Erro ao criar dados da pessoa');
            }

            $pessoaId = (int) $this->db->lastInsertId();
            $this->criarUtilizador($pessoaId, $password);
            $this->marcarEmailVerificado($pessoaId);
            $funcionarioId = $this->inserirFuncionario($pessoaId, $cargo);

            $this->db->commit();
            http_response_code(201);
            echo json_encode([
                'message' => 'Funcionário criado com sucesso',
                'funcionario_id' => $funcionarioId,
                'pessoa_id' => $pessoaId,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar funcionário: ' . $e->getMessage()]);
        }
    }

    public function update($id, $data) {
        if (!is_array($data)) {
            $data = [];
        }

        $this->funcionario->funcionario_id = (int) $id;
        $stmt = $this->funcionario->getById();
        $func = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$func) {
            http_response_code(404);
            echo json_encode(['message' => 'Funcionário não encontrado']);
            return;
        }

        $pessoaId = (int) $func['pessoas_pessoa_id'];
        $cargo = trim((string) ($data['cargo'] ?? $func['cargo'] ?? ''));
        if ($cargo === '') {
            http_response_code(400);
            echo json_encode(['message' => 'Cargo é obrigatório']);
            return;
        }

        try {
            $this->db->beginTransaction();

            if (isset($data['nome'], $data['email'], $data['telemovel'], $data['morada'])) {
                $email = trim((string) $data['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Email inválido');
                }
                $dup = $this->db->prepare(
                    'SELECT pessoa_id FROM pessoas WHERE LOWER(TRIM(email)) = LOWER(TRIM(:e)) AND pessoa_id <> :id LIMIT 1'
                );
                $dup->execute([':e' => $email, ':id' => $pessoaId]);
                if ($dup->fetch(PDO::FETCH_ASSOC)) {
                    throw new InvalidArgumentException('Este email já está em uso');
                }

                $this->pessoa->pessoa_id = $pessoaId;
                $this->pessoa->nome = trim((string) $data['nome']);
                $this->pessoa->email = $email;
                $this->pessoa->telemovel = trim((string) $data['telemovel']);
                $this->pessoa->morada = trim((string) $data['morada']);
                if (array_key_exists('nif', $data)) {
                    $this->pessoa->nif = trim((string) $data['nif']) ?: null;
                }
                if (!$this->pessoa->update()) {
                    throw new RuntimeException('Erro ao atualizar dados da pessoa');
                }
            }

            $this->funcionario->funcionario_id = (int) $id;
            $this->funcionario->cargo = $cargo;
            if (!$this->funcionario->update()) {
                throw new RuntimeException('Erro ao atualizar cargo');
            }

            $novaPassword = trim((string) ($data['password'] ?? ''));
            if ($novaPassword !== '') {
                if (strlen($novaPassword) < 8) {
                    throw new InvalidArgumentException('A password deve ter pelo menos 8 caracteres');
                }
                $this->utilizador->updatePasswordByPessoaId($pessoaId, PasswordHelper::hash($novaPassword));
            }

            $this->db->commit();
            echo json_encode(['message' => 'Funcionário atualizado com sucesso']);
        } catch (InvalidArgumentException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(400);
            echo json_encode(['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar funcionário']);
        }
    }

    public function destroy($id) {
        $this->funcionario->funcionario_id = (int) $id;
        $stmt = $this->funcionario->getById();
        $func = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$func) {
            http_response_code(404);
            echo json_encode(['message' => 'Funcionário não encontrado']);
            return;
        }

        $pessoaId = (int) $func['pessoas_pessoa_id'];

        try {
            $this->db->beginTransaction();

            $this->funcionario->funcionario_id = (int) $id;
            if (!$this->funcionario->delete()) {
                throw new RuntimeException('Erro ao remover funcionário');
            }

            $delU = $this->db->prepare('DELETE FROM utilizadores WHERE pessoas_pessoa_id = :pid');
            $delU->execute([':pid' => $pessoaId]);

            $enc = $this->db->prepare('SELECT COUNT(*) FROM encomendas WHERE cliente_id = :pid');
            $enc->execute([':pid' => $pessoaId]);
            $temEncomendas = (int) $enc->fetchColumn() > 0;

            if (!$temEncomendas) {
                $this->pessoa->pessoa_id = $pessoaId;
                $this->pessoa->delete();
            }

            $this->db->commit();
            echo json_encode([
                'message' => $temEncomendas
                    ? 'Funcionário removido. A ficha mantém-se como cliente (tinha encomendas).'
                    : 'Funcionário removido com sucesso',
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover funcionário']);
        }
    }

    private function funcionarioExistsForPessoa(int $pessoaId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT funcionario_id FROM funcionarios WHERE pessoas_pessoa_id = :pid LIMIT 1'
        );
        $stmt->execute([':pid' => $pessoaId]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function inserirFuncionario(int $pessoaId, string $cargo): int
    {
        $this->funcionario->pessoas_pessoa_id = $pessoaId;
        $this->funcionario->cargo = $cargo;
        if (!$this->funcionario->create()) {
            throw new RuntimeException('Erro ao criar registo de funcionário');
        }
        return (int) $this->db->lastInsertId();
    }

    private function criarUtilizador(int $pessoaId, string $password): void
    {
        $this->utilizador->pessoas_pessoa_id = $pessoaId;
        if ($this->utilizador->existsByPessoaID()) {
            $this->utilizador->updatePasswordByPessoaId($pessoaId, PasswordHelper::hash($password));
            return;
        }
        $this->utilizador->password = PasswordHelper::hash($password);
        if (!$this->utilizador->create()) {
            throw new RuntimeException('Erro ao criar acesso ao painel');
        }
    }

    private function criarUtilizadorSeNecessario(int $pessoaId, string $password): void
    {
        $this->criarUtilizador($pessoaId, $password);
    }

    private function marcarEmailVerificado(int $pessoaId): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pessoas' AND COLUMN_NAME = 'email_verificado'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            $up = $this->db->prepare(
                'UPDATE pessoas SET email_verificado = 1, email_verificacao_codigo = NULL WHERE pessoa_id = :id'
            );
            $up->execute([':id' => $pessoaId]);
        }
    }
}
