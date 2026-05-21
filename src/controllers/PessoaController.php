<?php
include_once __DIR__ . '/../models/Pessoa.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/NifHelper.php';

class PessoaController
{
    private $db;
    private $pessoa;

    public function __construct($db)
    {
        $this->db = $db;
        $this->pessoa = new Pessoa($db);
    }

    public function index()
    {
        $apenasClientes = isset($_GET['apenas_clientes'])
            && in_array(strtolower((string) $_GET['apenas_clientes']), ['1', 'true', 'yes'], true);
        $stmt = $apenasClientes ? $this->pessoa->getAllApenasClientes() : $this->pessoa->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function show($id)
    {
        if (!Auth::isAdmin() && (string) Auth::pessoaId() !== (string) $id) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão']);

            return;
        }

        $this->pessoa->pessoa_id = $id;
        $stmt = $this->pessoa->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Pessoa não encontrada']);
        }
    }

    public function store($data)
    {
        if (!isset($data['nome'], $data['email'], $data['telemovel'], $data['morada'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Todos os campos são obrigatórios']);

            return;
        }

        $this->pessoa->nome = $data['nome'];
        $this->pessoa->email = $data['email'];
        $this->pessoa->telemovel = $data['telemovel'];
        $this->pessoa->morada = $data['morada'];
        $nif = isset($data['nif']) ? trim((string) $data['nif']) : '';
        if ($nif !== '' && !NifHelper::valido($nif)) {
            http_response_code(400);
            echo json_encode(['message' => 'NIF inválido']);

            return;
        }
        $this->pessoa->nif = $nif !== '' ? $nif : null;

        if ($this->pessoa->create()) {
            $novoId = (int) $this->db->lastInsertId();
            http_response_code(201);
            echo json_encode([
                'message' => 'Pessoa criada com sucesso',
                'pessoa_id' => $novoId,
                'nome' => $this->pessoa->nome,
                'email' => $this->pessoa->email,
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar pessoa']);
        }
    }

    public function checkEmail($data)
    {
        if (!is_array($data)) {
            $data = [];
        }
        $email = trim((string) ($data['email'] ?? ($_GET['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['message' => 'Email inválido']);
            return;
        }

        $stmt = $this->db->prepare('SELECT pessoa_id FROM pessoas WHERE email = :email LIMIT 1');
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['exists' => $row ? true : false]);
    }

    public function update($id, $data)
    {
        if (!Auth::isAdmin() && (string) Auth::pessoaId() !== (string) $id) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão']);

            return;
        }

        $this->pessoa->pessoa_id = $id;

        $this->pessoa->nome = $data['nome'] ?? null;
        $this->pessoa->email = $data['email'] ?? null;
        $this->pessoa->telemovel = $data['telemovel'] ?? null;
        $this->pessoa->morada = $data['morada'] ?? null;
        if (array_key_exists('nif', $data)) {
            $nif = trim((string) $data['nif']);
            if ($nif !== '' && !NifHelper::valido($nif)) {
                http_response_code(400);
                echo json_encode(['message' => 'NIF inválido']);

                return;
            }
            $this->pessoa->nif = $nif !== '' ? $nif : null;
        }

        if ($this->pessoa->update()) {
            echo json_encode(['message' => 'Pessoa atualizada com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar pessoa']);
        }
    }

    public function destroy($id)
    {
        $chk = $this->db->prepare(
            'SELECT funcionario_id FROM funcionarios WHERE pessoas_pessoa_id = :id LIMIT 1'
        );
        $chk->execute([':id' => (int) $id]);
        if ($chk->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode([
                'message' => 'Esta pessoa é funcionária. Remova primeiro em Funcionários.',
            ]);

            return;
        }

        $this->pessoa->pessoa_id = $id;

        if ($this->pessoa->delete()) {
            echo json_encode(['message' => 'Pessoa removida com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover pessoa']);
        }
    }
}
