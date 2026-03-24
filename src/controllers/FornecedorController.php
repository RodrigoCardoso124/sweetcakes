<?php
include_once __DIR__ . "/../models/Fornecedor.php";
include_once __DIR__ . "/../models/Pessoa.php";

class FornecedorController {
    private $db;
    private $fornecedor;
    private $pessoa;

    public function __construct($db) {
        $this->db = $db;
        $this->fornecedor = new Fornecedor($db);
        $this->pessoa = new Pessoa($db);
    }

    // ----------------------------------------------------------

    public function index() {
        $stmt = $this->fornecedor->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ----------------------------------------------------------

    public function show($id) {
        $this->fornecedor->fornecedor_id = $id;
        $stmt = $this->fornecedor->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Fornecedor não encontrado"]);
        }
    }

    // ----------------------------------------------------------
 
    public function store($data) {
        if (!isset($data['empresa'], $data['pessoa_id'])) {
            http_response_code(400);
            echo json_encode(["message" => "empresa e pessoa_id são obrigatórios"]);
            return;
        }

        // Validar pessoa
        $this->pessoa->pessoa_id = $data['pessoa_id'];
        $stmt = $this->pessoa->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(["message" => "Pessoa associada não existe"]);
            return;
        }

        // Criar fornecedor
        $this->fornecedor->empresa = $data['empresa'];
        $this->fornecedor->pessoas_pessoa_id = $data['pessoa_id'];

        if ($this->fornecedor->create()) {
            http_response_code(201);
            echo json_encode(["message" => "Fornecedor criado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar fornecedor"]);
        }
    }

    // ----------------------------------------------------------

    public function update($id, $data) {
        $this->fornecedor->fornecedor_id = $id;

        // Verificar se fornecedor existe
        $stmt = $this->fornecedor->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(["message" => "Fornecedor não encontrado"]);
            return;
        }

        // Validar pessoa, se for enviada
        if (isset($data['pessoa_id'])) {
            $this->pessoa->pessoa_id = $data['pessoa_id'];
            $stmt = $this->pessoa->getById();
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(400);
                echo json_encode(["message" => "Pessoa associada não existe"]);
                return;
            }
        }

        $this->fornecedor->empresa = $data['empresa'] ?? null;
        $this->fornecedor->pessoas_pessoa_id = $data['pessoa_id'] ?? null;

        if ($this->fornecedor->update()) {
            echo json_encode(["message" => "Fornecedor atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar fornecedor"]);
        }
    }

    // ----------------------------------------------------------

    public function destroy($id) {
        $this->fornecedor->fornecedor_id = $id;

        if ($this->fornecedor->delete()) {
            echo json_encode(["message" => "Fornecedor removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover fornecedor"]);
        }
    }
}
?>
