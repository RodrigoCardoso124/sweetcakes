<?php
class Produto {
    private $conn;
    private $table = 'produtos';

    public $produto_id;
    public $nome;
    public $descricao;
    public $preco;
    public $disponivel;
    public $stock_atual;
    public $stock_minimo;
    public $imagem;
    public $alergenios;
    public $categoria_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function hasCategoriaColumn(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM {$this->table} LIKE 'categoria_id'");
            $cache = $stmt && (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache = false;
        }

        return $cache;
    }

    private function selectColumns(): string
    {
        $base = 'p.produto_id, p.nome, p.descricao, p.preco, p.disponivel, p.stock_atual, p.stock_minimo, p.imagem, p.alergenios';
        if (!$this->hasCategoriaColumn()) {
            return str_replace('p.', '', $base);
        }

        return $base . ', p.categoria_id, c.nome AS categoria_nome, c.slug AS categoria_slug';
    }

    private function fromJoin(): string
    {
        if (!$this->hasCategoriaColumn()) {
            return $this->table;
        }

        return "{$this->table} p LEFT JOIN categorias_produto c ON c.categoria_id = p.categoria_id";
    }

    public function getAll(?int $categoriaId = null) {
        $query = 'SELECT ' . $this->selectColumns() . ' FROM ' . $this->fromJoin();
        if ($categoriaId !== null && $this->hasCategoriaColumn()) {
            $query .= ' WHERE p.categoria_id = :cid';
        }
        $stmt = $this->conn->prepare($query);
        if ($categoriaId !== null && $this->hasCategoriaColumn()) {
            $stmt->bindValue(':cid', $categoriaId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt;
    }

    public function getById() {
        $query = 'SELECT ' . $this->selectColumns() . ' FROM ' . $this->fromJoin()
            . ' WHERE p.produto_id = :id LIMIT 1';
        if (!$this->hasCategoriaColumn()) {
            $query = "SELECT produto_id, nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios
                      FROM {$this->table} WHERE produto_id = :id LIMIT 1";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $this->produto_id);
        $stmt->execute();

        return $stmt;
    }

    public function create() {
        if ($this->hasCategoriaColumn()) {
            $query = "INSERT INTO {$this->table}
                        (nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios, categoria_id)
                      VALUES (:nome, :descricao, :preco, :disponivel, :stock_atual, :stock_minimo, :imagem, :alergenios, :categoria_id)";
        } else {
            $query = "INSERT INTO {$this->table}
                        (nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios)
                      VALUES (:nome, :descricao, :preco, :disponivel, :stock_atual, :stock_minimo, :imagem, :alergenios)";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':nome', $this->nome);
        $stmt->bindValue(':descricao', $this->descricao);
        $stmt->bindValue(':preco', $this->preco);
        $stmt->bindValue(':disponivel', $this->disponivel);
        $stmt->bindValue(':stock_atual', (int) $this->stock_atual, PDO::PARAM_INT);
        $stmt->bindValue(':stock_minimo', (int) $this->stock_minimo, PDO::PARAM_INT);
        $stmt->bindValue(':imagem', $this->imagem);
        $stmt->bindValue(':alergenios', $this->alergenios);
        if ($this->hasCategoriaColumn()) {
            $stmt->bindValue(':categoria_id', $this->categoria_id, $this->categoria_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        }

        return $stmt->execute();
    }

    public function update() {
        if ($this->hasCategoriaColumn()) {
            $query = "UPDATE {$this->table}
                      SET nome = :nome,
                          descricao = :descricao,
                          preco = :preco,
                          disponivel = :disponivel,
                          stock_atual = :stock_atual,
                          stock_minimo = :stock_minimo,
                          imagem = :imagem,
                          alergenios = :alergenios,
                          categoria_id = :categoria_id
                      WHERE produto_id = :id";
        } else {
            $query = "UPDATE {$this->table}
                      SET nome = :nome,
                          descricao = :descricao,
                          preco = :preco,
                          disponivel = :disponivel,
                          stock_atual = :stock_atual,
                          stock_minimo = :stock_minimo,
                          imagem = :imagem,
                          alergenios = :alergenios
                      WHERE produto_id = :id";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':nome', $this->nome);
        $stmt->bindValue(':descricao', $this->descricao);
        $stmt->bindValue(':preco', $this->preco);
        $stmt->bindValue(':disponivel', $this->disponivel);
        $stmt->bindValue(':stock_atual', (int) $this->stock_atual, PDO::PARAM_INT);
        $stmt->bindValue(':stock_minimo', (int) $this->stock_minimo, PDO::PARAM_INT);
        $stmt->bindValue(':imagem', $this->imagem);
        $stmt->bindValue(':alergenios', $this->alergenios);
        if ($this->hasCategoriaColumn()) {
            $stmt->bindValue(':categoria_id', $this->categoria_id, $this->categoria_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
        }
        $stmt->bindValue(':id', $this->produto_id);

        return $stmt->execute();
    }

    public function incrementStock(int $produtoId, int $delta): bool
    {
        if ($delta === 0) {
            return true;
        }
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET stock_atual = stock_atual + :d WHERE produto_id = :id"
        );
        $stmt->bindValue(':d', $delta, PDO::PARAM_INT);
        $stmt->bindValue(':id', $produtoId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function delete() {
        $query = "DELETE FROM {$this->table} WHERE produto_id = :id";
        $stmt  = $this->conn->prepare($query);
        $stmt->bindValue(':id', $this->produto_id);
        return $stmt->execute();
    }
}
