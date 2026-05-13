<?php
class FidelidadePontos
{
    private $conn;
    private $table = 'fidelidade_pontos';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function getPontos($pessoaId)
    {
        $sql = "SELECT pontos FROM {$this->table} WHERE pessoas_pessoa_id = :p LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':p', (int) $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['pontos'] : 0;
    }

    public function addPoints($pessoaId, $delta)
    {
        $delta = (int) $delta;
        if ($delta <= 0) {
            return true;
        }
        $sql = "INSERT INTO {$this->table} (pessoas_pessoa_id, pontos) VALUES (:p, :d)
                ON DUPLICATE KEY UPDATE pontos = pontos + :d2";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':p', (int) $pessoaId, PDO::PARAM_INT);
        $stmt->bindValue(':d', $delta, PDO::PARAM_INT);
        $stmt->bindValue(':d2', $delta, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function ensureSchema()
    {
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    pessoas_pessoa_id INT NOT NULL PRIMARY KEY,
                    pontos INT NOT NULL DEFAULT 0,
                    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            error_log('FidelidadePontos::ensureSchema: ' . $e->getMessage());
        }
    }
}
