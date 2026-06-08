-- Categorias de produtos (Semifrios, Bolos, Tartes, Tortas, …)
-- Executar uma vez na base de dados (Aiven / MySQL).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categorias_produto (
    categoria_id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_categorias_slug (slug),
    UNIQUE KEY uk_categorias_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categorias_produto (nome, slug, ordem, ativo) VALUES
    ('Semifrios', 'semifrios', 10, 1),
    ('Bolos', 'bolos', 20, 1),
    ('Tartes', 'tartes', 30, 1),
    ('Tortas', 'tortas', 40, 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), ordem = VALUES(ordem), ativo = VALUES(ativo);

-- Coluna em produtos (ignorar erro se já existir)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'produtos'
      AND COLUMN_NAME = 'categoria_id'
);
SET @sql_add := IF(
    @col_exists = 0,
    'ALTER TABLE produtos ADD COLUMN categoria_id INT NULL AFTER descricao, ADD INDEX idx_produtos_categoria (categoria_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migrar: o campo descricao tinha o nome da categoria no catálogo importado
-- COLLATE evita erro 1267 (general_ci vs unicode_ci entre tabelas antigas e novas)
UPDATE produtos p
INNER JOIN categorias_produto c
    ON LOWER(TRIM(p.descricao)) COLLATE utf8mb4_unicode_ci
     = LOWER(TRIM(c.nome)) COLLATE utf8mb4_unicode_ci
SET p.categoria_id = c.categoria_id
WHERE p.produto_id > 0 AND p.categoria_id IS NULL;

-- Semifrios pelo nome (fallback) — produto_id > 0 = safe updates no Workbench
UPDATE produtos p
INNER JOIN categorias_produto c ON c.slug = 'semifrios'
SET p.categoria_id = c.categoria_id
WHERE p.produto_id > 0 AND p.categoria_id IS NULL AND LOWER(p.nome) LIKE 'semifrio%';

UPDATE produtos p
INNER JOIN categorias_produto c ON c.slug = 'tartes'
SET p.categoria_id = c.categoria_id
WHERE p.produto_id > 0 AND p.categoria_id IS NULL AND LOWER(p.nome) LIKE 'tarte%';

UPDATE produtos p
INNER JOIN categorias_produto c ON c.slug = 'tortas'
SET p.categoria_id = c.categoria_id
WHERE p.produto_id > 0 AND p.categoria_id IS NULL AND LOWER(p.nome) LIKE 'torta%';

UPDATE produtos p
INNER JOIN categorias_produto c ON c.slug = 'bolos'
SET p.categoria_id = c.categoria_id
WHERE p.produto_id > 0 AND p.categoria_id IS NULL;
