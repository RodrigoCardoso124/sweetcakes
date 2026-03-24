<?php
class EncomendaDetalhe {
    private $conn;
    private $table = "encomenda_detalhes";

    public $detalhe_id;
    public $encomenda_id;
    public $produto_id;
    public $quantidade;
    public $especifico;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getByEncomenda($encomenda_id) {
        $query = "SELECT * FROM {$this->table} WHERE encomenda_id = :eid";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":eid", $encomenda_id);
        $stmt->execute();
        return $stmt;
    }

    public function getById() {
        $query = "SELECT * FROM {$this->table} WHERE detalhe_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->detalhe_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (encomenda_id, produto_id, quantidade, especifico)
                  VALUES (:eid, :pid, :q, :esp)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":eid", $this->encomenda_id);
        $stmt->bindValue(":pid", $this->produto_id);
        $stmt->bindValue(":q", $this->quantidade);
        $stmt->bindValue(":esp", $this->especifico);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET encomenda_id = :eid,
                      produto_id = :pid,
                      quantidade = :q,
                      especifico = :esp
                  WHERE detalhe_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":eid", $this->encomenda_id);
        $stmt->bindValue(":pid", $this->produto_id);
        $stmt->bindValue(":q", $this->quantidade);
        $stmt->bindValue(":esp", $this->especifico);
        $stmt->bindValue(":id", $this->detalhe_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE detalhe_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->detalhe_id);
        return $stmt->execute();
    }
}
?>
