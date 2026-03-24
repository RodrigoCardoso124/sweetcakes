<?php
include_once __DIR__ . "/../models/Venda.php";
include_once __DIR__ . "/../models/ProdutosVendidos.php";
include_once __DIR__ . "/../models/ProdutoIngrediente.php";
include_once __DIR__ . "/../models/Ingredientes.php";
include_once __DIR__ . "/../models/Produto.php";
include_once __DIR__ . "/../models/Pessoa.php";
include_once __DIR__ . "/../models/Funcionario.php";

class VendaController {
    private $db;
    private $venda;
    private $produtosVendidos;
    private $produtoIngrediente;
    private $ingrediente;
    private $produto;
    private $pessoa;
    private $funcionario;

    public function __construct($db) {
        $this->db = $db;
        $this->venda = new Venda($db);
        $this->produtosVendidos = new ProdutosVendidos($db);
        $this->produtoIngrediente = new ProdutoIngrediente($db);
        $this->ingrediente = new Ingredientes($db);
        $this->produto = new Produto($db);
        $this->pessoa = new Pessoa($db);
        $this->funcionario = new Funcionario($db);
    }

    // ----------------------------------------------------------

    public function index() {
        $stmt = $this->venda->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ----------------------------------------------------------

    public function show($id) {
        $this->venda->venda_id = $id;
        $stmt = $this->venda->getById();
        $venda = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$venda) {
            http_response_code(404);
            echo json_encode(["message" => "Venda não encontrada"]);
            return;
        }

        $this->produtosVendidos->venda_id = $id;
        $produtos = $this->produtosVendidos->getByVenda();

        $venda["produtos"] = $produtos;

        echo json_encode($venda);
    }

    // ----------------------------------------------------------

    public function store($data) {
        if (!isset($data['funcionario_id'], $data['cliente_id'], $data['produtos'])) {
            http_response_code(400);
            echo json_encode(["message" => "Campos obrigatórios: funcionario_id, cliente_id, produtos"]);
            return;
        }

        // ------------------------------------------------------

        $this->funcionario->funcionario_id = $data['funcionario_id'];
        $stmt = $this->funcionario->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(["message" => "Funcionário não encontrado"]);
            return;
        }

        // ------------------------------------------------------

        $this->pessoa->pessoa_id = $data['cliente_id'];
        $stmt = $this->pessoa->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(["message" => "Cliente não encontrado"]);
            return;
        }

        // ------------------------------------------------------

        $this->venda->funcionario_id = $data['funcionario_id'];
        $this->venda->pessoas_pessoa_id = $data['cliente_id'];
        $this->venda->total = 0;

        if (!$this->venda->create()) {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar venda"]);
            return;
        }

        $vendaID = $this->db->lastInsertId();
        $totalFinal = 0;

        // ------------------------------------------------------

        foreach ($data['produtos'] as $item) {
            if (!isset($item['produto_id'], $item['quantidade'])) continue;

            $produto_id = $item['produto_id'];
            $quantidade = $item['quantidade'];

            // Buscar info do produto
            $this->produto->produto_id = $produto_id;
            $stmt = $this->produto->getById();
            $produtoInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$produtoInfo) {
                http_response_code(400);
                echo json_encode(["message" => "Produto ID $produto_id não existe"]);
                return;
            }

            $precoUnit = $produtoInfo['preco'];

            // Inserir no produtos_vendidos
            $this->produtosVendidos->venda_id = $vendaID;
            $this->produtosVendidos->produto_id = $produto_id;
            $this->produtosVendidos->quantidade = $quantidade;
            $this->produtosVendidos->preco_unitario = $precoUnit;
            $this->produtosVendidos->create();

            // Baixar stock automaticamente
            $this->baixarStockIngredientes($produto_id, $quantidade);

            // Atualizar total
            $totalFinal += $precoUnit * $quantidade;

            // Atualizar disponibilidade do produto
            $this->updateProdutoDisponibilidade($produto_id);
        }

        // ------------------------------------------------------
   
        $this->venda->venda_id = $vendaID;
        $this->venda->total = $totalFinal;
        $this->venda->updateTotal();

        echo json_encode([
            "message" => "Venda criada com sucesso",
            "venda_id" => $vendaID,
            "total" => $totalFinal
        ]);
    }

    // ----------------------------------------------------------

    public function destroy($id) {
        $this->venda->venda_id = $id;

        // Buscar produtos vendidos
        $this->produtosVendidos->venda_id = $id;
        $produtos = $this->produtosVendidos->getByVenda();

        // Repor stock
        foreach ($produtos as $item) {
            $this->reporStockIngredientes($item['produto_id'], $item['quantidade']);
            $this->updateProdutoDisponibilidade($item['produto_id']);
        }

        // Apagar produtos vendidos
        $this->produtosVendidos->deleteByVenda();

        // Apagar venda
        if ($this->venda->delete()) {
            echo json_encode(["message" => "Venda apagada com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao apagar venda"]);
        }
    }

    // ----------------------------------------------------------

    private function baixarStockIngredientes($produto_id, $qtdVendida) {
        $this->produtoIngrediente->produto_id = $produto_id;
        $ingredientes = $this->produtoIngrediente->getIngredientesByProduto();

        foreach ($ingredientes as $ing) {
            $necessario = $ing['quantidade'] * $qtdVendida;
            $idIng = $ing['ingrediente_id'];

            $this->ingrediente->ingrediente_id = $idIng;
            $stmt = $this->ingrediente->getById();
            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            $novoStock = $info['quantidade_atual'] - $necessario;
            $this->ingrediente->updateStock($idIng, $novoStock);
        }
    }

    private function reporStockIngredientes($produto_id, $qtdVendida) {
        $this->produtoIngrediente->produto_id = $produto_id;
        $ingredientes = $this->produtoIngrediente->getIngredientesByProduto();

        foreach ($ingredientes as $ing) {
            $quantRepor = $ing['quantidade'] * $qtdVendida;
            $idIng = $ing['ingrediente_id'];

            $this->ingrediente->ingrediente_id = $idIng;
            $stmt = $this->ingrediente->getById();
            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            $novoStock = $info['quantidade_atual'] + $quantRepor;
            $this->ingrediente->updateStock($idIng, $novoStock);
        }
    }

    private function updateProdutoDisponibilidade($produto_id) {
        $this->produtoIngrediente->produto_id = $produto_id;
        $ingredientes = $this->produtoIngrediente->getIngredientesByProduto();

        foreach ($ingredientes as $row) {
            $necessario = $row['quantidade'];
            $idIng = $row['ingrediente_id'];

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
