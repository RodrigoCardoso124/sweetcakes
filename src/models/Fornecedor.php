<?php
class Fornecedor {
    private $conn;
    private $table = "fornecedores";

    public $fornecedor_id;
    public $empresa;
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
        $query = "SELECT * FROM {$this->table} WHERE fornecedor_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->fornecedor_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (empresa, pessoas_pessoa_id)
                  VALUES (:empresa, :pid)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":empresa", $this->empresa);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET empresa = :empresa,
                      pessoas_pessoa_id = :pid
                  WHERE fornecedor_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":empresa", $this->empresa);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        $stmt->bindValue(":id", $this->fornecedor_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE fornecedor_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->fornecedor_id);
        return $stmt->execute();
    }
}
?>
