<?php
class Venda {
    private $conn;
    private $table = "vendas";

    public $venda_id;
    public $funcionario_id;
    public $data_venda;
    public $total;
    public $pessoas_pessoa_id;

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
        $query = "SELECT * FROM {$this->table} WHERE venda_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->venda_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (funcionario_id, pessoas_pessoa_id, total)
                  VALUES (:fid, :pid, :total)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":fid", $this->funcionario_id);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        $stmt->bindValue(":total", $this->total);
        return $stmt->execute();
    }

    public function updateTotal() {
        $query = "UPDATE {$this->table}
                  SET total = :total
                  WHERE venda_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":total", $this->total);
        $stmt->bindValue(":id", $this->venda_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE venda_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->venda_id);
        return $stmt->execute();
    }
}
?>
