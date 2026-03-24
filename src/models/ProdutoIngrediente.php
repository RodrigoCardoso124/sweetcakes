<?php
class ProdutoIngrediente {
    private $conn;
    private $table = "produto_ingrediente";

    public $produto_id;
    public $ingrediente_id;
    public $quantidade;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getIngredientesByProduto() {
        $query = "SELECT * FROM {$this->table} WHERE produto_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->produto_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (produto_id, ingrediente_id, quantidade)
                  VALUES (:pid, :iid, :q)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":pid", $this->produto_id);
        $stmt->bindValue(":iid", $this->ingrediente_id);
        $stmt->bindValue(":q", $this->quantidade);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET quantidade = :q
                  WHERE produto_id = :pid AND ingrediente_id = :iid";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":q", $this->quantidade);
        $stmt->bindValue(":pid", $this->produto_id);
        $stmt->bindValue(":iid", $this->ingrediente_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table}
                  WHERE produto_id = :pid AND ingrediente_id = :iid";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":pid", $this->produto_id);
        $stmt->bindValue(":iid", $this->ingrediente_id);
        return $stmt->execute();
    }
}
?>
