<?php
include_once __DIR__ . "/../models/Funcionario.php";
include_once __DIR__ . "/../models/Pessoa.php";

class FuncionarioController {
    private $db;
    private $funcionario;
    private $pessoa;

    public function __construct($db) {
        $this->db = $db;
        $this->funcionario = new Funcionario($db);
        $this->pessoa = new Pessoa($db);
    }

    public function index() {
        $stmt = $this->funcionario->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function show($id) {
        $this->funcionario->funcionario_id = $id;
        $stmt = $this->funcionario->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Funcionário não encontrado"]);
        }
    }

    public function store($data) {
        if (!isset($data['pessoa_id'], $data['cargo'])) {
            http_response_code(400);
            echo json_encode(["message" => "pessoa_id e cargo são obrigatórios"]);
            return;
        }

        // Verificar se a pessoa existe
        $this->pessoa->pessoa_id = $data['pessoa_id'];
        $stmt = $this->pessoa->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(["message" => "A pessoa indicada não existe"]);
            return;
        }

        // Criar funcionário
        $this->funcionario->pessoas_pessoa_id = $data['pessoa_id'];
        $this->funcionario->cargo = $data['cargo'];

        if ($this->funcionario->create()) {
            http_response_code(201);
            echo json_encode(["message" => "Funcionário criado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar funcionário"]);
        }
    }

    public function update($id, $data) {
        $this->funcionario->funcionario_id = $id;

        $this->funcionario->cargo = $data['cargo'] ?? null;

        if ($this->funcionario->update()) {
            echo json_encode(["message" => "Funcionário atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar funcionário"]);
        }
    }

    public function destroy($id) {
        $this->funcionario->funcionario_id = $id;

        if ($this->funcionario->delete()) {
            echo json_encode(["message" => "Funcionário removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover funcionário"]);
        }
    }
}
?>
