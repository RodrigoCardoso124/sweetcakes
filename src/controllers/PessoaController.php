<?php
include_once __DIR__ . '/../models/Pessoa.php';
require_once __DIR__ . '/../helpers/Auth.php';

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
        $stmt = $this->pessoa->getAll();
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

        if ($this->pessoa->create()) {
            http_response_code(201);
            echo json_encode(['message' => 'Pessoa criada com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar pessoa']);
        }
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

        if ($this->pessoa->update()) {
            echo json_encode(['message' => 'Pessoa atualizada com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar pessoa']);
        }
    }

    public function destroy($id)
    {
        $this->pessoa->pessoa_id = $id;

        if ($this->pessoa->delete()) {
            echo json_encode(['message' => 'Pessoa removida com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover pessoa']);
        }
    }
}
