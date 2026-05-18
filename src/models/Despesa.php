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
        $stmt = $this->conn->prepare(
            'INSERT INTO despesas (tipo, descricao, valor, data_despesa, ingrediente_id, fornecedor_id, notas)
             VALUES (:tipo, :desc, :val, :data, :ing, :forn, :notas)'
        );
        $stmt->bindValue(':tipo', $d['tipo']);
        $stmt->bindValue(':desc', $d['descricao']);
        $stmt->bindValue(':val', $d['valor']);
        $stmt->bindValue(':data', $d['data_despesa']);
        $stmt->bindValue(':ing', $d['ingrediente_id'] ?? null, $d['ingrediente_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':forn', $d['fornecedor_id'] ?? null, $d['fornecedor_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':notas', $d['notas'] ?? null);
        $stmt->execute();
        return (int) $this->conn->lastInsertId();
    }

    public function update(int $id, array $d): bool
    {
        $stmt = $this->conn->prepare(
            'UPDATE despesas SET tipo = :tipo, descricao = :desc, valor = :val,
             data_despesa = :data, ingrediente_id = :ing, fornecedor_id = :forn, notas = :notas
             WHERE despesa_id = :id'
        );
        $stmt->bindValue(':tipo', $d['tipo']);
        $stmt->bindValue(':desc', $d['descricao']);
        $stmt->bindValue(':val', $d['valor']);
        $stmt->bindValue(':data', $d['data_despesa']);
        $stmt->bindValue(':ing', $d['ingrediente_id'] ?? null, $d['ingrediente_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':forn', $d['fornecedor_id'] ?? null, $d['fornecedor_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':notas', $d['notas'] ?? null);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM despesas WHERE despesa_id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
