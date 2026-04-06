<?php
class Produto {
    private $conn;
    private $table = "produtos";

    public $produto_id;
    public $nome;
    public $descricao;
    public $preco;
    public $disponivel;
    public $imagem;
    public $alergenios;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ------------------------------------------------------------
    // LISTAR TODOS
    // ------------------------------------------------------------
    public function getAll() {
        $query = "SELECT produto_id, nome, descricao, preco, disponivel, imagem, alergenios
                  FROM {$this->table}";
        $stmt  = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    // ------------------------------------------------------------
    // MOSTRAR POR ID
    // ------------------------------------------------------------
    public function getById() {
        $query = "SELECT produto_id, nome, descricao, preco, disponivel, imagem, alergenios
                  FROM {$this->table} 
                  WHERE produto_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->produto_id);
        $stmt->execute();
        return $stmt;
    }

    // ------------------------------------------------------------
    // CRIAR
    // ------------------------------------------------------------
    public function create() {
        $query = "INSERT INTO {$this->table}
                    (nome, descricao, preco, disponivel, imagem, alergenios)
                  VALUES (:nome, :descricao, :preco, :disponivel, :imagem, :alergenios)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":descricao", $this->descricao);
        $stmt->bindValue(":preco", $this->preco);
        $stmt->bindValue(":disponivel", $this->disponivel);
        $stmt->bindValue(":imagem", $this->imagem);
        $stmt->bindValue(":alergenios", $this->alergenios);
        return $stmt->execute();
    }

    // ------------------------------------------------------------
    // ATUALIZAR
    // ------------------------------------------------------------
    public function update() {
        $query = "UPDATE {$this->table}
                  SET nome = :nome,
                      descricao = :descricao,
                      preco = :preco,
                      disponivel = :disponivel,
                      imagem = :imagem,
                      alergenios = :alergenios
                  WHERE produto_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":descricao", $this->descricao);
        $stmt->bindValue(":preco", $this->preco);
        $stmt->bindValue(":disponivel", $this->disponivel);
        $stmt->bindValue(":imagem", $this->imagem);
        $stmt->bindValue(":alergenios", $this->alergenios);
        $stmt->bindValue(":id", $this->produto_id);
        return $stmt->execute();
    }

    // ------------------------------------------------------------
    // APAGAR
    // ------------------------------------------------------------
    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE produto_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->produto_id);
        return $stmt->execute();
    }
}
?>
