-- Migrar produtos → categorias (collation + safe updates no Workbench).
-- Executar no MySQL / Aiven. Ficheiro: sql/017b_fix_collation_categorias.sql

SET NAMES utf8mb4;

UPDATE produtos p
INNER JOIN categorias_produto c
    ON LOWER(TRIM(p.descricao)) COLLATE utf8mb4_unicode_ci
     = LOWER(TRIM(c.nome)) COLLATE utf8mb4_unicode_ci
SET p.categoria_id = c.categoria_id
WHERE p.produto_id > 0 AND p.categoria_id IS NULL;

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

SELECT c.nome, COUNT(p.produto_id) AS produtos
FROM categorias_produto c
LEFT JOIN produtos p ON p.categoria_id = c.categoria_id
GROUP BY c.categoria_id, c.nome
ORDER BY c.ordem;
