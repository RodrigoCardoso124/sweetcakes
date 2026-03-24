<?php
include_once __DIR__ . "/../models/ProdutosVendidos.php";
include_once __DIR__ . "/../models/ProdutoIngrediente.php";
include_once __DIR__ . "/../models/Ingredientes.php";
include_once __DIR__ . "/../models/Produto.php";

class ProdutosVendidosController {
    private $db;
    private $produtosVendidos;
    private $produtoIngrediente;
    private $ingrediente;
    private $produto;

    public function __construct($db) {
        $this->db = $db;
        $this->produtosVendidos = new ProdutosVendidos($db);
        $this->produtoIngrediente = new ProdutoIngrediente($db);
        $this->ingrediente = new Ingredientes($db);
        $this->produto = new Produto($db);
    }

    // ----------------------------------------------------------

    public function index($venda_id) {
        $this->produtosVendidos->venda_id = $venda_id;
        $items = $this->produtosVendidos->getByVenda();
        echo json_encode($items);
    }

    // ----------------------------------------------------------

    public function show($id) {
        $this->produtosVendidos->produto_vendido_id = $id;
        $stmt = $this->produtosVendidos->getById();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            echo json_encode($item);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Item vendido não encontrado"]);
        }
    }

    // ----------------------------------------------------------

    public function store($data) {
        if (!isset($data['venda_id'], $data['produto_id'], $data['quantidade'])) {
            http_response_code(400);
            echo json_encode(["message" => "venda_id, produto_id e quantidade são obrigatórios"]);
            return;
        }

        // Obter preço do produto
        $this->produto->produto_id = $data['produto_id'];
        $stmt = $this->produto->getById();
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produto) {
            http_response_code(400);
            echo json_encode(["message" => "Produto inválido"]);
            return;
        }

        $precoUnit = $produto['preco'];

        // Criar item de venda
        $this->produtosVendidos->venda_id = $data['venda_id'];
        $this->produtosVendidos->produto_id = $data['produto_id'];
        $this->produtosVendidos->quantidade = $data['quantidade'];
        $this->produtosVendidos->preco_unitario = $precoUnit;

        if ($this->produtosVendidos->create()) {
            echo json_encode(["message" => "Item vendido adicionado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar item vendido"]);
        }
    }

    // ----------------------------------------------------------

    public function update($data) {
        if (!isset($data['produto_vendido_id'], $data['quantidade'])) {
            http_response_code(400);
            echo json_encode(["message" => "produto_vendido_id e quantidade são obrigatórios"]);
            return;
        }

        $this->produtosVendidos->produto_vendido_id = $data['produto_vendido_id'];
        $this->produtosVendidos->quantidade = $data['quantidade'];

        if ($this->produtosVendidos->update()) {
            echo json_encode(["message" => "Item atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar item"]);
        }
    }

    // ----------------------------------------------------------

    public function destroy($data) {
        if (!isset($data['produto_vendido_id'])) {
            http_response_code(400);
            echo json_encode(["message" => "produto_vendido_id é obrigatório"]);
            return;
        }

        $this->produtosVendidos->produto_vendido_id = $data['produto_vendido_id'];

        if ($this->produtosVendidos->delete()) {
            echo json_encode(["message" => "Item removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover item"]);
        }
    }
}
?>
