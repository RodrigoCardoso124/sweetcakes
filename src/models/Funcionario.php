<?php
class Funcionario {
    private $conn;
    private $table = "funcionarios";

    public $funcionario_id;
    public $cargo;
    public $data_entrada;
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
        $query = "SELECT * FROM {$this->table} WHERE funcionario_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->funcionario_id);
        $stmt->execute();
        return $stmt;
    }

    public function getByPessoaId() {
        $query = "SELECT * FROM {$this->table} WHERE pessoas_pessoa_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->pessoas_pessoa_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table} (cargo, pessoas_pessoa_id)
                  VALUES (:cargo, :pid)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":cargo", $this->cargo);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET cargo = :cargo
                  WHERE funcionario_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":cargo", $this->cargo);
        $stmt->bindValue(":id", $this->funcionario_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE funcionario_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->funcionario_id);
        return $stmt->execute();
    }
}
?>
