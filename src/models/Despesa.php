<?php



class Despesa

{

    private $conn;



    public function __construct($db)

    {

        $this->conn = $db;

    }



    public function listAll(?string $de = null, ?string $ate = null): array

    {

        $sql = 'SELECT * FROM despesas WHERE 1=1';

        $params = [];

        if ($de !== null && $ate !== null) {

            $sql .= ' AND data_despesa BETWEEN :de AND :ate';

            $params[':de'] = $de;

            $params[':ate'] = $ate;

        }

        $sql .= ' ORDER BY data_despesa DESC, despesa_id DESC';

        $stmt = $this->conn->prepare($sql);

        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    }



    public function getById(int $id): ?array

    {

        $stmt = $this->conn->prepare('SELECT * FROM despesas WHERE despesa_id = :id LIMIT 1');

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;

    }



    public function create(array $d): int

    {

        $cols = 'tipo, descricao, valor, data_despesa, ingrediente_id, fornecedor_id, notas';

        $vals = ':tipo, :desc, :val, :data, :ing, :forn, :notas';

        if ($this->hasIvaColumns()) {

            $cols .= ', taxa_iva_pct, total_base, total_iva';

            $vals .= ', :taxa, :base, :iva';

        }

        $stmt = $this->conn->prepare("INSERT INTO despesas ($cols) VALUES ($vals)");

        $stmt->bindValue(':tipo', $d['tipo']);

        $stmt->bindValue(':desc', $d['descricao']);

        $stmt->bindValue(':val', $d['valor']);

        $stmt->bindValue(':data', $d['data_despesa']);

        $stmt->bindValue(':ing', $d['ingrediente_id'] ?? null, $d['ingrediente_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);

        $stmt->bindValue(':forn', $d['fornecedor_id'] ?? null, $d['fornecedor_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);

        $stmt->bindValue(':notas', $d['notas'] ?? null);

        if ($this->hasIvaColumns()) {

            $stmt->bindValue(':taxa', $d['taxa_iva_pct'] ?? 23);

            $stmt->bindValue(':base', $d['total_base'] ?? null);

            $stmt->bindValue(':iva', $d['total_iva'] ?? null);

        }

        $stmt->execute();

        return (int) $this->conn->lastInsertId();

    }



    public function update(int $id, array $d): bool

    {

        $set = 'tipo = :tipo, descricao = :desc, valor = :val, data_despesa = :data,

             ingrediente_id = :ing, fornecedor_id = :forn, notas = :notas';

        if ($this->hasIvaColumns()) {

            $set .= ', taxa_iva_pct = :taxa, total_base = :base, total_iva = :iva';

        }

        $stmt = $this->conn->prepare("UPDATE despesas SET $set WHERE despesa_id = :id");

        $stmt->bindValue(':tipo', $d['tipo']);

        $stmt->bindValue(':desc', $d['descricao']);

        $stmt->bindValue(':val', $d['valor']);

        $stmt->bindValue(':data', $d['data_despesa']);

        $stmt->bindValue(':ing', $d['ingrediente_id'] ?? null, $d['ingrediente_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);

        $stmt->bindValue(':forn', $d['fornecedor_id'] ?? null, $d['fornecedor_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);

        $stmt->bindValue(':notas', $d['notas'] ?? null);

        if ($this->hasIvaColumns()) {

            $stmt->bindValue(':taxa', $d['taxa_iva_pct'] ?? 23);

            $stmt->bindValue(':base', $d['total_base'] ?? null);

            $stmt->bindValue(':iva', $d['total_iva'] ?? null);

        }

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();

    }

    private function hasIvaColumns(): bool

    {

        static $ok = null;

        if ($ok !== null) {

            return $ok;

        }

        $stmt = $this->conn->prepare(

            'SELECT COUNT(*) FROM information_schema.COLUMNS

             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'despesas\' AND COLUMN_NAME = \'taxa_iva_pct\''

        );

        $stmt->execute();

        $ok = (int) $stmt->fetchColumn() > 0;

        return $ok;

    }



    public function delete(int $id): bool

    {

        $stmt = $this->conn->prepare('DELETE FROM despesas WHERE despesa_id = :id');

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();

    }

}

