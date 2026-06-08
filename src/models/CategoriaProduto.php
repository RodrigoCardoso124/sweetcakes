<?php

class CategoriaProduto
{
    private PDO $conn;
    private string $table = 'categorias_produto';

    public $categoria_id;
    public $nome;
    public $slug;
    public $ordem;
    public $ativo;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS categorias_produto (
                    categoria_id INT AUTO_INCREMENT PRIMARY KEY,
                    nome VARCHAR(80) NOT NULL,
                    slug VARCHAR(80) NOT NULL,
                    ordem INT NOT NULL DEFAULT 0,
                    ativo TINYINT(1) NOT NULL DEFAULT 1,
                    UNIQUE KEY uk_categorias_slug (slug),
                    UNIQUE KEY uk_categorias_nome (nome)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $stmt = $db->query("SHOW COLUMNS FROM produtos LIKE 'categoria_id'");
            if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
                $db->exec(
                    'ALTER TABLE produtos ADD COLUMN categoria_id INT NULL AFTER descricao,
                     ADD INDEX idx_produtos_categoria (categoria_id)'
                );
            }

            $count = (int) $db->query('SELECT COUNT(*) FROM categorias_produto')->fetchColumn();
            if ($count === 0) {
                $seed = [
                    ['Semifrios', 'semifrios', 10],
                    ['Bolos', 'bolos', 20],
                    ['Tartes', 'tartes', 30],
                    ['Tortas', 'tortas', 40],
                ];
                $ins = $db->prepare(
                    'INSERT INTO categorias_produto (nome, slug, ordem, ativo) VALUES (?, ?, ?, 1)'
                );
                foreach ($seed as $row) {
                    $ins->execute($row);
                }
            }

            self::migrarCategoriaDesdeDescricao($db);
        } catch (Throwable $e) {
            error_log('[CategoriaProduto::ensureSchema] ' . $e->getMessage());
        }
    }

    private static function migrarCategoriaDesdeDescricao(PDO $db): void
    {
        $db->exec(
            'UPDATE produtos p
             INNER JOIN categorias_produto c
                ON LOWER(TRIM(p.descricao)) COLLATE utf8mb4_unicode_ci
                 = LOWER(TRIM(c.nome)) COLLATE utf8mb4_unicode_ci
             SET p.categoria_id = c.categoria_id
             WHERE p.categoria_id IS NULL'
        );
        $rules = [
            ['semifrios', "LOWER(nome) LIKE 'semifrio%'"],
            ['tartes', "LOWER(nome) LIKE 'tarte%'"],
            ['tortas', "LOWER(nome) LIKE 'torta%'"],
            ['bolos', 'categoria_id IS NULL'],
        ];
        foreach ($rules as [$slug, $cond]) {
            $db->exec(
                "UPDATE produtos p
                 INNER JOIN categorias_produto c ON c.slug = " . $db->quote($slug) . "
                 SET p.categoria_id = c.categoria_id
                 WHERE p.categoria_id IS NULL AND {$cond}"
            );
        }
    }

    public static function slugify(string $nome): string
    {
        $s = strtolower(trim($nome));
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;

        return trim($s, '-') ?: 'categoria';
    }

    public function getAll(bool $apenasAtivas = false): PDOStatement
    {
        $sql = "SELECT categoria_id, nome, slug, ordem, ativo FROM {$this->table}";
        if ($apenasAtivas) {
            $sql .= ' WHERE ativo = 1';
        }
        $sql .= ' ORDER BY ordem ASC, nome ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    public function getById(): PDOStatement
    {
        $stmt = $this->conn->prepare(
            "SELECT categoria_id, nome, slug, ordem, ativo FROM {$this->table} WHERE categoria_id = :id LIMIT 1"
        );
        $stmt->bindValue(':id', $this->categoria_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function create(): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (nome, slug, ordem, ativo) VALUES (:nome, :slug, :ordem, :ativo)"
        );
        $stmt->bindValue(':nome', $this->nome);
        $stmt->bindValue(':slug', $this->slug);
        $stmt->bindValue(':ordem', (int) $this->ordem, PDO::PARAM_INT);
        $stmt->bindValue(':ativo', (int) $this->ativo, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function update(): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET nome = :nome, slug = :slug, ordem = :ordem, ativo = :ativo WHERE categoria_id = :id"
        );
        $stmt->bindValue(':nome', $this->nome);
        $stmt->bindValue(':slug', $this->slug);
        $stmt->bindValue(':ordem', (int) $this->ordem, PDO::PARAM_INT);
        $stmt->bindValue(':ativo', (int) $this->ativo, PDO::PARAM_INT);
        $stmt->bindValue(':id', $this->categoria_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete(): bool
    {
        $chk = $this->conn->prepare('SELECT COUNT(*) FROM produtos WHERE categoria_id = :id');
        $chk->execute([':id' => (int) $this->categoria_id]);
        if ((int) $chk->fetchColumn() > 0) {
            return false;
        }
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE categoria_id = :id");
        $stmt->bindValue(':id', $this->categoria_id, PDO::PARAM_INT);

        return $stmt->execute();
    }
}
