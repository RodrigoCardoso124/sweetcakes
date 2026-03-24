<?php
class Utilizador {
    private $conn;
    private $table = "utilizadores";

    public $utilizador_id;
    public $password;
    public $pessoas_pessoa_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (password, pessoas_pessoa_id)
                  VALUES (:password, :pid)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":password", $this->password);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        return $stmt->execute();
    }

    public function existsByPessoaID() {
        $query = "SELECT utilizador_id
                  FROM {$this->table}
                  WHERE pessoas_pessoa_id = :pid
                  LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByPessoaId() {
        $query = "SELECT *
                  FROM {$this->table}
                  WHERE pessoas_pessoa_id = :pid
                  LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        $stmt->execute();
        return $stmt;
    }

    public function getAllByPessoaId() {
        $query = "SELECT *
                  FROM {$this->table}
                  WHERE pessoas_pessoa_id = :pid
                  ORDER BY utilizador_id DESC";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":pid", $this->pessoas_pessoa_id);
        $stmt->execute();
        return $stmt;
    }

    public function getById() {
        $query = "SELECT *
                  FROM {$this->table}
                  WHERE utilizador_id = :id
                  LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->utilizador_id);
        $stmt->execute();
        return $stmt;
    }

    public function updatePasswordByPessoaId($pessoaId, $hashedPassword) {
        $query = "UPDATE {$this->table}
                  SET password = :pw
                  WHERE pessoas_pessoa_id = :pid
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":pw", $hashedPassword);
        $stmt->bindValue(":pid", $pessoaId);
        return $stmt->execute();
    }
}
?>
