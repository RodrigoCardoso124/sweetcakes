<?php
include_once __DIR__ . "/../models/Ingredientes.php";

class IgredienteController {
    private $db;
    private $ingrediente;

    public function __construct($db) {
        $this->db = $db;
        $this->ingrediente = new Ingredientes($db);
    }

    // ----------------------------------------------------------------

    public function index() {
        $stmt = $this->ingrediente->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ----------------------------------------------------------------

    public function show($id) {
        $this->ingrediente->ingrediente_id = $id;
        $stmt = $this->ingrediente->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Ingrediente não encontrado"]);
        }
    }

    // ----------------------------------------------------------------

    public function store($data) {
        if (!isset($data['nome'], $data['quantidade_atual'], $data['unidade'], $data['quantidade_minima'])) {
            http_response_code(400);
            echo json_encode(["message" => "Todos os campos são obrigatórios"]);
            return;
        }

        $this->ingrediente->nome = $data['nome'];
        $this->ingrediente->quantidade_atual = $data['quantidade_atual'];
        $this->ingrediente->unidade = $data['unidade'];
        $this->ingrediente->quantidade_minima = $data['quantidade_minima'];

        if ($this->ingrediente->create()) {
            http_response_code(201);
            echo json_encode(["message" => "Ingrediente criado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar ingrediente"]);
        }
    }

    // ----------------------------------------------------------------

    public function update($id, $data) {
        $this->ingrediente->ingrediente_id = $id;

        $this->ingrediente->nome = $data['nome'] ?? null;
        $this->ingrediente->quantidade_atual = $data['quantidade_atual'] ?? null;
        $this->ingrediente->unidade = $data['unidade'] ?? null;
        $this->ingrediente->quantidade_minima = $data['quantidade_minima'] ?? null;

        if ($this->ingrediente->update()) {
            echo json_encode(["message" => "Ingrediente atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar ingrediente"]);
        }
    }

    // ----------------------------------------------------------------

    public function destroy($id) {
        $this->ingrediente->ingrediente_id = $id;

        if ($this->ingrediente->delete()) {
            echo json_encode(["message" => "Ingrediente removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover ingrediente"]);
        }
    }
}
?>
