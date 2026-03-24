<?php
include_once __DIR__ . "/../models/ProdutoIngrediente.php";
include_once __DIR__ . "/../models/Produto.php";
include_once __DIR__ . "/../models/Ingredientes.php";

class ProdutoIngredienteController {
    private $db;
    private $produtoIngrediente;
    private $produto;
    private $ingrediente;

    public function __construct($db) {
        $this->db = $db;
        $this->produtoIngrediente = new ProdutoIngrediente($db);
        $this->produto = new Produto($db);
        $this->ingrediente = new Ingredientes($db);
    }

    // -----------------------------------------------------

    public function index($produto_id) {
        $this->produtoIngrediente->produto_id = $produto_id;
        $ingredientes = $this->produtoIngrediente->getIngredientesByProduto();
        echo json_encode($ingredientes);
    }

    // -----------------------------------------------------

    public function store($data) {
        if (!isset($data['produto_id'], $data['ingrediente_id'], $data['quantidade'])) {
            http_response_code(400);
            echo json_encode(["message" => "produto_id, ingrediente_id e quantidade são obrigatórios"]);
            return;
        }

        // Validar ingrediente
        $this->ingrediente->ingrediente_id = $data['ingrediente_id'];
        $stmt = $this->ingrediente->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(["message" => "Ingrediente não existe"]);
            return;
        }

        // Validar produto
        $this->produto->produto_id = $data['produto_id'];
        $stmt = $this->produto->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(["message" => "Produto não existe"]);
            return;
        }

        // Criar relação
        $this->produtoIngrediente->produto_id = $data['produto_id'];
        $this->produtoIngrediente->ingrediente_id = $data['ingrediente_id'];
        $this->produtoIngrediente->quantidade = $data['quantidade'];

        if ($this->produtoIngrediente->create()) {
            $this->updateProdutoDisponibilidade($data['produto_id']);
            http_response_code(201);
            echo json_encode(["message" => "Ingrediente adicionado à receita com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao adicionar ingrediente"]);
        }
    }

    // -----------------------------------------------------

    public function update($data) {
        if (!isset($data['produto_id'], $data['ingrediente_id'], $data['quantidade'])) {
            http_response_code(400);
            echo json_encode(["message" => "produto_id, ingrediente_id e quantidade são obrigatórios"]);
            return;
        }

        $this->produtoIngrediente->produto_id = $data['produto_id'];
        $this->produtoIngrediente->ingrediente_id = $data['ingrediente_id'];
        $this->produtoIngrediente->quantidade = $data['quantidade'];

        if ($this->produtoIngrediente->update()) {
            $this->updateProdutoDisponibilidade($data['produto_id']);
            echo json_encode(["message" => "Receita atualizada com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar receita"]);
        }
    }

    // -----------------------------------------------------

    public function destroy($data) {
        if (!isset($data['produto_id'], $data['ingrediente_id'])) {
            http_response_code(400);
            echo json_encode(["message" => "produto_id e ingrediente_id são obrigatórios"]);
            return;
        }

        $this->produtoIngrediente->produto_id = $data['produto_id'];
        $this->produtoIngrediente->ingrediente_id = $data['ingrediente_id'];

        if ($this->produtoIngrediente->delete()) {
            $this->updateProdutoDisponibilidade($data['produto_id']);
            echo json_encode(["message" => "Ingrediente removido da receita"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover ingrediente"]);
        }
    }

    // --------------------------------------------------------

    private function updateProdutoDisponibilidade($produto_id) {
        $this->produtoIngrediente->produto_id = $produto_id;
        $ingredientes = $this->produtoIngrediente->getIngredientesByProduto();

        foreach ($ingredientes as $item) {
            $idIng = $item['ingrediente_id'];
            $necessario = $item['quantidade'];

            $this->ingrediente->ingrediente_id = $idIng;
            $stmt = $this->ingrediente->getById();
            $ing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ing['quantidade_atual'] < $necessario) {
                $this->produto->setDisponibilidade($produto_id, 0);
                return;
            }
        }

        $this->produto->setDisponibilidade($produto_id, 1);
    }
}
?>
