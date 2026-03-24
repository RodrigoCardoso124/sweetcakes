<?php
class Ingredientes {
    private $conn;
    private $table = "ingredientes";

    public $ingrediente_id;
    public $nome;
    public $quantidade_atual;
    public $unidade;
    public $quantidade_minima;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll() {
        $query = "SELECT * FROM {$this->table}";
        $stmt  = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function getById() {
        $query = "SELECT * FROM {$this->table} WHERE ingrediente_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->ingrediente_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (nome, quantidade_atual, unidade, quantidade_minima)
                  VALUES (:nome, :q_atual, :unidade, :q_min)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":q_atual", $this->quantidade_atual);
        $stmt->bindValue(":unidade", $this->unidade);
        $stmt->bindValue(":q_min", $this->quantidade_minima);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET nome = :nome,
                      quantidade_atual = :q_atual,
                      unidade = :unidade,
                      quantidade_minima = :q_min
                  WHERE ingrediente_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":q_atual", $this->quantidade_atual);
        $stmt->bindValue(":unidade", $this->unidade);
        $stmt->bindValue(":q_min", $this->quantidade_minima);
        $stmt->bindValue(":id", $this->ingrediente_id);
        return $stmt->execute();
    }

    public function updateStock($id, $quantidade) {
        $query = "UPDATE {$this->table}
                  SET quantidade_atual = :q
                  WHERE ingrediente_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":q", $quantidade);
        $stmt->bindValue(":id", $id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE ingrediente_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->ingrediente_id);
        return $stmt->execute();
    }
}
?>

