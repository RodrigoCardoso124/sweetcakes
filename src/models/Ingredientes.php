<?php
class Ingredientes {
    private $conn;
    private $table = "ingredientes";

    public $ingrediente_id;
    public $nome;
    public $quantidade_atual;
    public $unidade;
    public $quantidade_minima;
    public $preco_unitario;

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
        $hasPreco = $this->columnExists('preco_unitario');
        if ($hasPreco) {
            $query = "INSERT INTO {$this->table}
                        (nome, quantidade_atual, unidade, quantidade_minima, preco_unitario)
                      VALUES (:nome, :q_atual, :unidade, :q_min, :preco)";
        } else {
            $query = "INSERT INTO {$this->table}
                        (nome, quantidade_atual, unidade, quantidade_minima)
                      VALUES (:nome, :q_atual, :unidade, :q_min)";
        }
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":q_atual", $this->quantidade_atual);
        $stmt->bindValue(":unidade", $this->unidade);
        $stmt->bindValue(":q_min", $this->quantidade_minima);
        if ($hasPreco) {
            $stmt->bindValue(':preco', $this->preco_unitario ?? 0);
        }
        return $stmt->execute();
    }

    private function columnExists(string $column): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $this->table, ':c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function lastInsertId(): int
    {
        return (int) $this->conn->lastInsertId();
    }

    public function update() {
        $sets = 'nome = :nome, quantidade_atual = :q_atual, unidade = :unidade, quantidade_minima = :q_min';
        if ($this->columnExists('preco_unitario') && $this->preco_unitario !== null) {
            $sets .= ', preco_unitario = :preco';
        }
        $query = "UPDATE {$this->table} SET {$sets} WHERE ingrediente_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":nome", $this->nome);
        $stmt->bindValue(":q_atual", $this->quantidade_atual);
        $stmt->bindValue(":unidade", $this->unidade);
        $stmt->bindValue(":q_min", $this->quantidade_minima);
        if ($this->columnExists('preco_unitario') && $this->preco_unitario !== null) {
            $stmt->bindValue(':preco', $this->preco_unitario);
        }
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

    /**
     * Adiciona ou remove stock (delta pode ser negativo).
     */
    public function adjustQuantidade(int $id, float $delta): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET quantidade_atual = quantidade_atual + :d WHERE ingrediente_id = :id"
        );
        $stmt->bindValue(':d', $delta);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
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

