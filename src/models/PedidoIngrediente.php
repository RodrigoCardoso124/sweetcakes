<?php

class PedidoIngrediente
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function listAll(?string $estado = null): array
    {
        $sql = 'SELECT p.*, i.nome AS ingrediente_nome, i.unidade, i.quantidade_atual, i.quantidade_minima
                FROM pedidos_ingrediente p
                INNER JOIN ingredientes i ON i.ingrediente_id = p.ingrediente_id';
        if ($estado !== null && $estado !== '') {
            $sql .= ' WHERE p.estado = :e';
        }
        $sql .= ' ORDER BY p.criado_em DESC';
        $stmt = $this->conn->prepare($sql);
        if ($estado !== null && $estado !== '') {
            $stmt->bindValue(':e', $estado);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(int $ingredienteId, float $quantidade, ?string $notas, ?string $emailFornecedor = null): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO pedidos_ingrediente (ingrediente_id, quantidade, estado, notas, email_fornecedor)
             VALUES (:i, :q, \'pendente\', :n, :e)'
        );
        $stmt->bindValue(':i', $ingredienteId, PDO::PARAM_INT);
        $stmt->bindValue(':q', $quantidade);
        if ($notas === null || $notas === '') {
            $stmt->bindValue(':n', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':n', $notas);
        }
        if ($emailFornecedor === null || trim($emailFornecedor) === '') {
            $stmt->bindValue(':e', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':e', trim($emailFornecedor));
        }
        $stmt->execute();
        return (int) $this->conn->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT p.*, i.nome AS ingrediente_nome FROM pedidos_ingrediente p
             INNER JOIN ingredientes i ON i.ingrediente_id = p.ingrediente_id
             WHERE p.pedido_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function setEstado(int $id, string $estado): bool
    {
        $stmt = $this->conn->prepare(
            'UPDATE pedidos_ingrediente SET estado = :e WHERE pedido_id = :id'
        );
        $stmt->bindValue(':e', $estado);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
