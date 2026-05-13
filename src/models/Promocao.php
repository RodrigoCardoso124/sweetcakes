<?php
/**
 * Model das promocoes dinamicas.
 * Tabelas criadas em sql/004_promocoes.sql.
 */
class Promocao {
    private $conn;
    private $table = 'promocoes';
    private $usoTable = 'promocao_uso';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function listAll(): array {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} ORDER BY data_inicio DESC, promocao_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listActive(?int $pessoaId = null): array {
        $sql = "SELECT p.* FROM {$this->table} p
                WHERE NOW() BETWEEN p.data_inicio AND p.data_fim";
        if ($pessoaId !== null) {
            $sql .= " AND (p.uso_unico = 0 OR NOT EXISTS (
                        SELECT 1 FROM {$this->usoTable} u
                        WHERE u.promocao_id = p.promocao_id AND u.pessoa_id = :pid
                     ))";
        }
        $sql .= " ORDER BY p.data_inicio ASC, p.promocao_id ASC";
        $stmt = $this->conn->prepare($sql);
        if ($pessoaId !== null) {
            $stmt->bindValue(':pid', $pessoaId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getById(int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE promocao_id = :id LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int {
        $sql = "INSERT INTO {$this->table}
                  (titulo, subtitulo, tipo, valor_percentual, valor_fixo,
                   leve_qtd, pague_qtd, mensagem_oferta, min_compra, uso_unico,
                   data_inicio, data_fim)
                VALUES
                  (:titulo, :subtitulo, :tipo, :valor_percentual, :valor_fixo,
                   :leve_qtd, :pague_qtd, :mensagem_oferta, :min_compra, :uso_unico,
                   :data_inicio, :data_fim)";
        $stmt = $this->conn->prepare($sql);
        $this->bindCommon($stmt, $data);
        $stmt->execute();
        return (int)$this->conn->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $sql = "UPDATE {$this->table} SET
                  titulo = :titulo,
                  subtitulo = :subtitulo,
                  tipo = :tipo,
                  valor_percentual = :valor_percentual,
                  valor_fixo = :valor_fixo,
                  leve_qtd = :leve_qtd,
                  pague_qtd = :pague_qtd,
                  mensagem_oferta = :mensagem_oferta,
                  min_compra = :min_compra,
                  uso_unico = :uso_unico,
                  data_inicio = :data_inicio,
                  data_fim = :data_fim
                WHERE promocao_id = :id";
        $stmt = $this->conn->prepare($sql);
        $this->bindCommon($stmt, $data);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function delete(int $id): bool {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE promocao_id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function registarUso(int $promocaoId, int $pessoaId, ?int $encomendaId, float $desconto): bool {
        $sql = "INSERT IGNORE INTO {$this->usoTable}
                  (promocao_id, pessoa_id, encomenda_id, desconto)
                VALUES (:p, :u, :e, :d)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':p', $promocaoId, PDO::PARAM_INT);
        $stmt->bindValue(':u', $pessoaId, PDO::PARAM_INT);
        if ($encomendaId === null) {
            $stmt->bindValue(':e', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':e', $encomendaId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':d', $desconto);
        return $stmt->execute();
    }

    public function pessoaJaUsou(int $promocaoId, int $pessoaId): bool {
        $stmt = $this->conn->prepare("SELECT 1 FROM {$this->usoTable}
                                       WHERE promocao_id = :p AND pessoa_id = :u LIMIT 1");
        $stmt->bindValue(':p', $promocaoId, PDO::PARAM_INT);
        $stmt->bindValue(':u', $pessoaId, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    private function bindCommon(PDOStatement $stmt, array $d): void {
        $stmt->bindValue(':titulo', $d['titulo']);
        $stmt->bindValue(':subtitulo', $d['subtitulo']);
        $stmt->bindValue(':tipo', $d['tipo']);

        $bindNullable = function (string $param, $value, int $type = PDO::PARAM_STR) use ($stmt) {
            if ($value === null || $value === '') {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, $value, $type);
            }
        };

        $bindNullable(':valor_percentual', $d['valor_percentual'] ?? null);
        $bindNullable(':valor_fixo',       $d['valor_fixo'] ?? null);
        $bindNullable(':leve_qtd',         $d['leve_qtd'] ?? null, PDO::PARAM_INT);
        $bindNullable(':pague_qtd',        $d['pague_qtd'] ?? null, PDO::PARAM_INT);
        $bindNullable(':mensagem_oferta',  $d['mensagem_oferta'] ?? null);

        $stmt->bindValue(':min_compra', $d['min_compra'] ?? 0.0);
        $stmt->bindValue(':uso_unico',  !empty($d['uso_unico']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':data_inicio', $d['data_inicio']);
        $stmt->bindValue(':data_fim',    $d['data_fim']);
    }
}
