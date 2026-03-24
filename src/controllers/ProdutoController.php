<?php
include_once __DIR__ . "/../models/Produto.php";
include_once __DIR__ . "/../models/ProdutoIngrediente.php";
include_once __DIR__ . "/../models/Ingredientes.php";

class ProdutoController {
    private $db;
    private $produto;
    private $produtoIngrediente;
    private $ingrediente;

    public function __construct($db) {
        $this->db = $db;
        $this->produto = new Produto($db);
        $this->produtoIngrediente = new ProdutoIngrediente($db);
        $this->ingrediente = new Ingredientes($db);
    }

    /** Diretório base das imagens de produtos (relativo à raiz do projeto). */
    private const UPLOAD_DIR = "uploads/produtos";

    /**
     * Converte o valor da BD (nome ou caminho) para o caminho completo para a API.
     * Na BD guardamos apenas o nome do ficheiro; na resposta enviamos o caminho completo.
     */
    private function imagemParaResposta($imagem) {
        if (empty($imagem)) return null;
        // CORREÇÃO: se for apenas nome do ficheiro, acrescenta o diretório
        return (strpos($imagem, '/') !== false) ? $imagem : self::UPLOAD_DIR . "/" . $imagem;
    }

    /**
     * Devolve o caminho absoluto no disco para apagar ou verificar o ficheiro.
     * Aceita tanto nome do ficheiro como caminho antigo na BD.
     */
    private function imagemParaFicheiro($imagem) {
        if (empty($imagem)) return null;
        $base = __DIR__ . "/../../";
        return (strpos($imagem, '/') !== false) ? $base . $imagem : $base . self::UPLOAD_DIR . "/" . $imagem;
    }

    // ------------------------------------------------------------
    // LISTAR TODOS
    // ------------------------------------------------------------
    public function index() {
        $stmt = $this->produto->getAll();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (isset($row['imagem'])) {
                $row['imagem'] = $this->imagemParaResposta($row['imagem']);
            }
        }
        echo json_encode($rows);
    }

    // ------------------------------------------------------------
    // MOSTRAR POR ID
    // ------------------------------------------------------------
    public function show($id) {
        $this->produto->produto_id = $id;
        $stmt = $this->produto->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            if (isset($data['imagem'])) {
                $data['imagem'] = $this->imagemParaResposta($data['imagem']);
            }
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Produto não encontrado"]);
        }
    }

    // ------------------------------------------------------------
    // CRIAR PRODUTO
    // Com imagem: guarda o FICHEIRO em uploads/produtos/ e só o NOME na BD.
    // O index() devolve depois uploads/produtos/nome para o frontend ir buscar.
    // ------------------------------------------------------------
    public function store($data, $files = null) {
        if (!isset($data['nome'], $data['descricao'], $data['preco'])) {
            http_response_code(400);
            echo json_encode(["message" => "Campos obrigatórios: nome, descricao, preco"]);
            return;
        }

        $nomeImagem = null;
        if ($files && isset($files['imagem']) && $files['imagem']['error'] === 0) {
            $targetDir = __DIR__ . "/../../" . self::UPLOAD_DIR . "/";
            if (!file_exists($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            $file = $files['imagem'];
            $nomeImagem = uniqid("produto_") . "_" . basename($file["name"]);
            $targetFile = $targetDir . $nomeImagem;
            move_uploaded_file($file["tmp_name"], $targetFile);
        }

        $this->produto->nome       = $data['nome'];
        $this->produto->descricao  = $data['descricao'];
        $this->produto->preco      = $data['preco'];
        $this->produto->disponivel = 1;
        $this->produto->imagem     = $nomeImagem;

        if ($this->produto->create()) {
            http_response_code(201);
            echo json_encode(["message" => "Produto criado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar produto"]);
        }
    }

    // ------------------------------------------------------------
    // ATUALIZAR PRODUTO
    // Se enviar nova imagem: guarda o FICHEIRO em uploads/produtos/ e só o NOME na BD.
    // O index() devolve o caminho para o frontend ir buscar.
    // ------------------------------------------------------------
    public function update($id, $data, $files = null) {
        $this->produto->produto_id = $id;

        $stmt = $this->produto->getById();
        $produtoAtual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produtoAtual) {
            http_response_code(404);
            echo json_encode(["message" => "Produto não encontrado"]);
            return;
        }

        $imagemValor = $produtoAtual['imagem'];

        if ($files && isset($files['imagem']) && $files['imagem']['error'] === 0) {
            $targetDir = __DIR__ . "/../../" . self::UPLOAD_DIR . "/";
            if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
            $file = $files['imagem'];
            $nomeImagem = uniqid("produto_") . "_" . basename($file["name"]);
            $targetFile = $targetDir . $nomeImagem;
            move_uploaded_file($file["tmp_name"], $targetFile);
            $imagemValor = $nomeImagem;
        }

        $this->produto->nome       = $data['nome']       ?? $produtoAtual['nome'];
        $this->produto->descricao  = $data['descricao']  ?? $produtoAtual['descricao'];
        $this->produto->preco      = $data['preco']      ?? $produtoAtual['preco'];
        $this->produto->disponivel = $produtoAtual['disponivel'];
        $this->produto->imagem     = $imagemValor;

        if ($this->produto->update()) {
            echo json_encode(["message" => "Produto atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar produto"]);
        }
    }

    // ------------------------------------------------------------
    // APAGAR PRODUTO
    // ------------------------------------------------------------
    public function destroy($id) {
        $this->produto->produto_id = $id;

        $stmt = $this->produto->getById();
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            http_response_code(404);
            echo json_encode(["message" => "Produto não encontrado"]);
            return;
        }

        if (!empty($prod['imagem'])) {
            $path = $this->imagemParaFicheiro($prod['imagem']);
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }

        if ($this->produto->delete()) {
            echo json_encode(["message" => "Produto removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover produto"]);
        }
    }
}
?>
