<?php
class Encomenda {
    private $conn;
    private $table = "encomendas";

    public $encomenda_id;
    public $cliente_id;
    public $funcionario_id;
    public $estado;
    public $total;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll() {
        $query = "SELECT * FROM {$this->table}";
        $stmt  = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function countRows(?int $clienteId = null) {
        if ($clienteId !== null) {
            $query = "SELECT COUNT(*) AS c FROM {$this->table} WHERE cliente_id = :cid";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":cid", $clienteId, PDO::PARAM_INT);
        } else {
            $query = "SELECT COUNT(*) AS c FROM {$this->table}";
            $stmt = $this->conn->prepare($query);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['c'] ?? 0);
    }

    public function getPaged(int $limit, int $offset, ?int $clienteId = null) {
        if ($clienteId !== null) {
            $query = "SELECT e.*, p.nome AS cliente_nome, p.email AS cliente_email
                      FROM {$this->table} e
                      LEFT JOIN pessoas p ON p.pessoa_id = e.cliente_id
                      WHERE e.cliente_id = :cid
                      ORDER BY encomenda_id DESC
                      LIMIT :lim OFFSET :off";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(":cid", $clienteId, PDO::PARAM_INT);
        } else {
            $query = "SELECT e.*, p.nome AS cliente_nome, p.email AS cliente_email
                      FROM {$this->table} e
                      LEFT JOIN pessoas p ON p.pessoa_id = e.cliente_id
                      ORDER BY e.encomenda_id DESC
                      LIMIT :lim OFFSET :off";
            $stmt = $this->conn->prepare($query);
        }
        $stmt->bindValue(":lim", $limit, PDO::PARAM_INT);
        $stmt->bindValue(":off", $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function getById() {
        $query = "SELECT e.*, p.nome AS cliente_nome, p.email AS cliente_email
                  FROM {$this->table} e
                  LEFT JOIN pessoas p ON p.pessoa_id = e.cliente_id
                  WHERE e.encomenda_id = :id LIMIT 1";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->encomenda_id);
        $stmt->execute();
        return $stmt;
    }

    public function create() {
        $query = "INSERT INTO {$this->table}
                    (cliente_id, funcionario_id, estado, total)
                  VALUES (:cid, :fid, :estado, :total)";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":cid", $this->cliente_id);
        $stmt->bindValue(":fid", $this->funcionario_id);
        $stmt->bindValue(":estado", $this->estado);
        $stmt->bindValue(":total", $this->total);
        return $stmt->execute();
    }

    public function update() {
        $query = "UPDATE {$this->table}
                  SET cliente_id = :cid,
                      funcionario_id = :fid,
                      estado = :estado,
                      total = :total
                  WHERE encomenda_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":cid", $this->cliente_id);
        $stmt->bindValue(":fid", $this->funcionario_id, PDO::PARAM_NULL);
        // Usar o estado diretamente - o controller já valida antes de chamar este método
        // NÃO fazer fallback aqui para evitar sobrescrever o estado fornecido
        $stmt->bindValue(":estado", $this->estado, PDO::PARAM_STR);
        $stmt->bindValue(":total", $this->total);
        $stmt->bindValue(":id", $this->encomenda_id);
        
        // Log da query antes de executar
        error_log("📝 [MODEL] Query: UPDATE encomendas SET cliente_id={$this->cliente_id}, funcionario_id=" . ($this->funcionario_id ?? 'NULL') . ", estado='{$this->estado}', total={$this->total} WHERE encomenda_id={$this->encomenda_id}");
        
        $result = $stmt->execute();
        
        // Debug: log se houver erro ou sucesso
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            error_log("❌ [MODEL] Erro ao atualizar encomenda ID {$this->encomenda_id}");
            error_log("   - SQL State: {$errorInfo[0]}");
            error_log("   - Error Code: {$errorInfo[1]}");
            error_log("   - Error Message: {$errorInfo[2]}");
            error_log("   - Estado tentado: '{$this->estado}'");
            error_log("   - Funcionario ID tentado: " . ($this->funcionario_id ?? 'NULL'));
            error_log("   - Cliente ID tentado: {$this->cliente_id}");
            
            // Se for constraint violation, lançar exceção com mensagem clara
            if ($errorInfo[0] === '23000') {
                // Foreign key constraint violation
                if (strpos($errorInfo[2], 'funcionarios') !== false) {
                    throw new Exception("O funcionário ID {$this->funcionario_id} não existe na base de dados. Por favor, verifique o funcionário selecionado.");
                } elseif (strpos($errorInfo[2], 'pessoas') !== false || strpos($errorInfo[2], 'clientes') !== false) {
                    throw new Exception("O cliente ID {$this->cliente_id} não existe na base de dados. Por favor, verifique o cliente selecionado.");
                } else {
                    throw new Exception("Erro de constraint na base de dados: " . $errorInfo[2]);
                }
            }
        } else {
            $rowsAffected = $stmt->rowCount();
            error_log("✅ [MODEL] Encomenda ID {$this->encomenda_id} atualizada: estado = '{$this->estado}' (linhas afetadas: {$rowsAffected})");
            
            // Se nenhuma linha foi afetada, pode ser que o estado não mudou ou há um problema
            if ($rowsAffected === 0) {
                error_log("⚠️ [MODEL] ATENÇÃO: Nenhuma linha foi afetada na atualização!");
            }
        }
        
        return $result;
    }

    // Atualizar apenas o estado - sem tocar nos outros campos
    public function updateEstado() {
        $query = "UPDATE {$this->table}
                  SET estado = :estado
                  WHERE encomenda_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":estado", $this->estado, PDO::PARAM_STR);
        $stmt->bindValue(":id", $this->encomenda_id);
        
        error_log("📝 [MODEL] Atualizando apenas estado: encomenda_id={$this->encomenda_id}, estado='{$this->estado}'");
        
        $result = $stmt->execute();
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            error_log("❌ [MODEL] Erro ao atualizar estado: " . json_encode($errorInfo));
        } else {
            $rowsAffected = $stmt->rowCount();
            error_log("✅ [MODEL] Estado atualizado: encomenda_id={$this->encomenda_id}, estado='{$this->estado}' (linhas: {$rowsAffected})");
        }
        
        return $result;
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE encomenda_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(":id", $this->encomenda_id);
        return $stmt->execute();
    }
}
?>
