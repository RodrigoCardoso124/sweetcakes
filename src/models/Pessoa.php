<?php
class Pessoa {
    private $conn;
    private $table = "pessoas";

    public $pessoa_id;
    public $nome;
    public $email;
    public $telemovel;
    public $morada;
    public $data_registo;

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
        $query = "SELECT * FROM {$this->table} WHERE pessoa_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->pessoa_id);
        $stmt->execute();
        return $stmt;
    }

    public function getByEmail() {
        $query = "SELECT * FROM {$this->table} WHERE email = :email LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":email", $this->email);
        $stmt->execute();
        return $stmt;
    }

    public function getAllByLoginIdentifier() {
        $query = "SELECT *
                  FROM {$this->table}
                  WHERE LOWER(TRIM(email)) = LOWER(TRIM(:identifier))
                     OR LOWER(TRIM(SUBSTRING_INDEX(email, '@', 1))) = LOWER(TRIM(:identifier))
                  ORDER BY pessoa_id DESC";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":identifier", $this->email);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table} (nome, email, telemovel, morada)
                  VALUES (:nome, :email, :telemovel, :morada)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":email", $this->email);
        $stmt->bindValue(":telemovel", $this->telemovel);
        $stmt->bindValue(":morada", $this->morada);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET nome = :nome, email = :email, telemovel = :telemovel, morada = :morada
                  WHERE pessoa_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":email", $this->email);
        $stmt->bindValue(":telemovel", $this->telemovel);
        $stmt->bindValue(":morada", $this->morada);
        $stmt->bindValue(":id", $this->pessoa_id);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE pessoa_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->pessoa_id);
        return $stmt->execute();
    }

    public function setEmailVerificationCode($pessoaId, $code) {
        $query = "UPDATE {$this->table}
                  SET email_verificado = 0,
                      email_verificacao_codigo = :code,
                      email_verificacao_data = NOW()
                  WHERE pessoa_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":code", $code);
        $stmt->bindValue(":id", $pessoaId);
        return $stmt->execute();
    }

    public function verifyEmailWithCode($email, $code) {
        $query = "UPDATE {$this->table}
                  SET email_verificado = 1,
                      email_verificacao_codigo = NULL
                  WHERE email = :email
                    AND email_verificacao_codigo = :code
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":email", $email);
        $stmt->bindValue(":code", $code);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
?>
