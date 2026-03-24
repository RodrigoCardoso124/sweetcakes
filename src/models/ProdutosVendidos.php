<?php
class ProdutosVendidos {
    private $conn;
    private $table = "produtos_vendidos";

    public $produto_vendido_id;
    public $venda_id;
    public $produto_id;
    public $quantidade;
    public $preco_unitario;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getByVenda() {
        $query = "SELECT * FROM {$this->table} WHERE venda_id = :vid";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":vid", $this->venda_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById() {
        $query = "SELECT * FROM {$this->table}
                  WHERE produto_vendido_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->produto_vendido_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (venda_id, produto_id, quantidade, preco_unitario)
                  VALUES (:vid, :pid, :q, :preco)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":vid", $this->venda_id);
        $stmt->bindValue(":pid", $this->produto_id);
        $stmt->bindValue(":q", $this->quantidade);
        $stmt->bindValue(":preco", $this->preco_unitario);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET quantidade = :q
                  WHERE produto_vendido_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":q", $this->quantidade);
        $stmt->bindValue(":id", $this->produto_vendido_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table}
                  WHERE produto_vendido_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->produto_vendido_id);
        return $stmt->execute();
    }

    public function deleteByVenda() {
        $query = "DELETE FROM {$this->table} WHERE venda_id = :vid";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":vid", $this->venda_id);
        return $stmt->execute();
    }
}
?>
