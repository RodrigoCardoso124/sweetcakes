<?php
class Produto {
    private $conn;
    private $table = 'produtos';

    public $produto_id;
    public $nome;
    public $descricao;
    public $preco;
    public $disponivel;
    public $stock_atual;
    public $stock_minimo;
    public $imagem;
    public $alergenios;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll() {
        $query = "SELECT produto_id, nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios
                  FROM {$this->table}";
        $stmt  = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function getById() {
        $query = "SELECT produto_id, nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios
                  FROM {$this->table}
                  WHERE produto_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(':id', $this->produto_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios)
                  VALUES (:nome, :descricao, :preco, :disponivel, :stock_atual, :stock_minimo, :imagem, :alergenios)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(':nome', $this->nome);
        $stmt->bindValue(':descricao', $this->descricao);
        $stmt->bindValue(':preco', $this->preco);
        $stmt->bindValue(':disponivel', $this->disponivel);
        $stmt->bindValue(':stock_atual', (int) $this->stock_atual, PDO::PARAM_INT);
        $stmt->bindValue(':stock_minimo', (int) $this->stock_minimo, PDO::PARAM_INT);
        $stmt->bindValue(':imagem', $this->imagem);
        $stmt->bindValue(':alergenios', $this->alergenios);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET nome = :nome,
                      descricao = :descricao,
                      preco = :preco,
                      disponivel = :disponivel,
                      stock_atual = :stock_atual,
                      stock_minimo = :stock_minimo,
                      imagem = :imagem,
                      alergenios = :alergenios
                  WHERE produto_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(':nome', $this->nome);
        $stmt->bindValue(':descricao', $this->descricao);
        $stmt->bindValue(':preco', $this->preco);
        $stmt->bindValue(':disponivel', $this->disponivel);
        $stmt->bindValue(':stock_atual', (int) $this->stock_atual, PDO::PARAM_INT);
        $stmt->bindValue(':stock_minimo', (int) $this->stock_minimo, PDO::PARAM_INT);
        $stmt->bindValue(':imagem', $this->imagem);
        $stmt->bindValue(':alergenios', $this->alergenios);
        $stmt->bindValue(':id', $this->produto_id);
        return $stmt->execute();
    }

    public function incrementStock(int $produtoId, int $delta): bool
    {
        if ($delta === 0) {
            return true;
        }
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET stock_atual = stock_atual + :d WHERE produto_id = :id"
        );
        $stmt->bindValue(':d', $delta, PDO::PARAM_INT);
        $stmt->bindValue(':id', $produtoId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE produto_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(':id', $this->produto_id);
        return $stmt->execute();
    }
}
