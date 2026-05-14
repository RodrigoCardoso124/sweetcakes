<?php

class Receita
{
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function listAll(): array
    {
        $sql = 'SELECT r.*, p.nome AS produto_nome
                FROM receitas r
                INNER JOIN produtos p ON p.produto_id = r.produto_id
                ORDER BY r.ativo DESC, r.nome ASC';
        $stmt = $this->conn->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function listActive(): array
    {
        $sql = 'SELECT r.*, p.nome AS produto_nome
                FROM receitas r
                INNER JOIN produtos p ON p.produto_id = r.produto_id
                WHERE r.ativo = 1
                ORDER BY r.nome ASC';
        $stmt = $this->conn->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT r.*, p.nome AS produto_nome FROM receitas r
             INNER JOIN produtos p ON p.produto_id = r.produto_id
             WHERE r.receita_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLinhas(int $receitaId): array
    {
        $sql = 'SELECT ri.*, i.nome AS ingrediente_nome, i.unidade
                FROM receita_ingredientes ri
                INNER JOIN ingredientes i ON i.ingrediente_id = ri.ingrediente_id
                WHERE ri.receita_id = :r
                ORDER BY i.nome ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':r', $receitaId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(array $d): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO receitas (nome, produto_id, rendimento, ativo, notas)
             VALUES (:nome, :pid, :rend, :ativo, :notas)'
        );
        $stmt->bindValue(':nome', $d['nome']);
        $stmt->bindValue(':pid', (int) $d['produto_id'], PDO::PARAM_INT);
        $stmt->bindValue(':rend', max(1, (int) $d['rendimento']), PDO::PARAM_INT);
        $stmt->bindValue(':ativo', !empty($d['ativo']) ? 1 : 0, PDO::PARAM_INT);
        if (!empty($d['notas'])) {
            $stmt->bindValue(':notas', $d['notas']);
        } else {
            $stmt->bindValue(':notas', null, PDO::PARAM_NULL);
        }
        $stmt->execute();
        return (int) $this->conn->lastInsertId();
    }

    public function update(int $id, array $d): bool
    {
        $stmt = $this->conn->prepare(
            'UPDATE receitas SET nome = :nome, produto_id = :pid, rendimento = :rend,
             ativo = :ativo, notas = :notas WHERE receita_id = :id'
        );
        $stmt->bindValue(':nome', $d['nome']);
        $stmt->bindValue(':pid', (int) $d['produto_id'], PDO::PARAM_INT);
        $stmt->bindValue(':rend', max(1, (int) $d['rendimento']), PDO::PARAM_INT);
        $stmt->bindValue(':ativo', !empty($d['ativo']) ? 1 : 0, PDO::PARAM_INT);
        if (!empty($d['notas'])) {
            $stmt->bindValue(':notas', $d['notas']);
        } else {
            $stmt->bindValue(':notas', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM receitas WHERE receita_id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function replaceLinhas(int $receitaId, array $linhas): void
    {
        $del = $this->conn->prepare('DELETE FROM receita_ingredientes WHERE receita_id = :r');
        $del->bindValue(':r', $receitaId, PDO::PARAM_INT);
        $del->execute();

        $ins = $this->conn->prepare(
            'INSERT INTO receita_ingredientes (receita_id, ingrediente_id, quantidade)
             VALUES (:r, :i, :q)'
        );
        foreach ($linhas as $ln) {
            $iid = (int) ($ln['ingrediente_id'] ?? 0);
            $q = (float) ($ln['quantidade'] ?? 0);
            if ($iid <= 0 || $q <= 0) {
                continue;
            }
            $ins->bindValue(':r', $receitaId, PDO::PARAM_INT);
            $ins->bindValue(':i', $iid, PDO::PARAM_INT);
            $ins->bindValue(':q', $q);
            $ins->execute();
        }
    }
}
