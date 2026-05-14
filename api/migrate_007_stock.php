<?php
/**
 * Migração 007 — stock de produtos, receitas, pedidos de ingredientes, log.
 * Idempotente. GET: https://<host>/api/migrate_007_stock.php
 */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../src/config/database.php';

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Sem ligação à base de dados']);
        exit;
    }

    if (!columnExists($db, 'produtos', 'stock_atual')) {
        $db->exec('ALTER TABLE produtos ADD COLUMN stock_atual INT NOT NULL DEFAULT 0 AFTER disponivel');
    }
    if (!columnExists($db, 'produtos', 'stock_minimo')) {
        $db->exec('ALTER TABLE produtos ADD COLUMN stock_minimo INT NOT NULL DEFAULT 0 AFTER stock_atual');
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS receitas (
            receita_id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            produto_id INT NOT NULL,
            rendimento INT NOT NULL DEFAULT 1,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            notas TEXT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_receitas_produto (produto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS receita_ingredientes (
            receita_id INT NOT NULL,
            ingrediente_id INT NOT NULL,
            quantidade DECIMAL(12,4) NOT NULL,
            PRIMARY KEY (receita_id, ingrediente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS pedidos_ingrediente (
            pedido_id INT AUTO_INCREMENT PRIMARY KEY,
            ingrediente_id INT NOT NULL,
            quantidade DECIMAL(12,4) NOT NULL,
            estado ENUM('pendente','recebido','cancelado') NOT NULL DEFAULT 'pendente',
            notas VARCHAR(500) NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pi_estado (estado),
            INDEX idx_pi_ing (ingrediente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!columnExists($db, 'pedidos_ingrediente', 'email_fornecedor')) {
        $db->exec('ALTER TABLE pedidos_ingrediente ADD COLUMN email_fornecedor VARCHAR(255) NULL DEFAULT NULL AFTER notas');
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS producao_log (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('manual_produto','receita') NOT NULL,
            receita_id INT NULL,
            produto_id INT NULL,
            quantidade_produto INT NULL,
            vezes_receita INT NULL,
            funcionario_id INT NULL,
            pessoa_id INT NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pl_tipo (tipo),
            INDEX idx_pl_criado (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    echo json_encode([
        'ok' => true,
        'message' => 'Migração 007 aplicada (stock, receitas, pedidos, log).',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro na migração 007',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
