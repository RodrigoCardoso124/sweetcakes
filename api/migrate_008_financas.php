<?php
/**
 * Migração 008 — finanças: preços materiais, histórico, despesas, snapshots encomenda.
 * GET: https://<host>/api/migrate_008_financas.php
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

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute([':t' => $table]);
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

    if (!columnExists($db, 'ingredientes', 'preco_unitario')) {
        $db->exec('ALTER TABLE ingredientes ADD COLUMN preco_unitario DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER quantidade_minima');
    }
    if (!columnExists($db, 'ingredientes', 'ultima_atualizacao_preco')) {
        $db->exec('ALTER TABLE ingredientes ADD COLUMN ultima_atualizacao_preco DATETIME NULL DEFAULT NULL AFTER preco_unitario');
    }

    if (!columnExists($db, 'pedidos_ingrediente', 'preco_unitario_compra')) {
        $db->exec('ALTER TABLE pedidos_ingrediente ADD COLUMN preco_unitario_compra DECIMAL(12,4) NULL DEFAULT NULL AFTER quantidade');
    }
    if (!columnExists($db, 'pedidos_ingrediente', 'valor_total')) {
        $db->exec('ALTER TABLE pedidos_ingrediente ADD COLUMN valor_total DECIMAL(12,2) NULL DEFAULT NULL AFTER preco_unitario_compra');
    }
    if (!columnExists($db, 'pedidos_ingrediente', 'num_fatura')) {
        $db->exec('ALTER TABLE pedidos_ingrediente ADD COLUMN num_fatura VARCHAR(80) NULL DEFAULT NULL AFTER valor_total');
    }
    if (!columnExists($db, 'pedidos_ingrediente', 'data_recebido')) {
        $db->exec('ALTER TABLE pedidos_ingrediente ADD COLUMN data_recebido DATE NULL DEFAULT NULL AFTER num_fatura');
    }

    if (!columnExists($db, 'encomenda_detalhes', 'preco_unitario')) {
        $db->exec('ALTER TABLE encomenda_detalhes ADD COLUMN preco_unitario DECIMAL(12,2) NULL DEFAULT NULL AFTER quantidade');
    }
    if (!columnExists($db, 'encomenda_detalhes', 'custo_unitario_estimado')) {
        $db->exec('ALTER TABLE encomenda_detalhes ADD COLUMN custo_unitario_estimado DECIMAL(12,4) NULL DEFAULT NULL AFTER preco_unitario');
    }

    if (!columnExists($db, 'produtos', 'custo_estimado')) {
        $db->exec('ALTER TABLE produtos ADD COLUMN custo_estimado DECIMAL(12,4) NULL DEFAULT NULL AFTER preco');
    }

    if (!columnExists($db, 'encomendas', 'criado_em')) {
        $db->exec('ALTER TABLE encomendas ADD COLUMN criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    if (!tableExists($db, 'ingrediente_preco_historico')) {
        $db->exec(
            "CREATE TABLE ingrediente_preco_historico (
                historico_id INT AUTO_INCREMENT PRIMARY KEY,
                ingrediente_id INT NOT NULL,
                preco_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,
                data_vigencia DATE NOT NULL,
                pedido_id INT NULL,
                notas VARCHAR(255) NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_iph_ing (ingrediente_id),
                INDEX idx_iph_data (data_vigencia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!tableExists($db, 'despesas')) {
        $db->exec(
            "CREATE TABLE despesas (
                despesa_id INT AUTO_INCREMENT PRIMARY KEY,
                tipo ENUM('material','embalagem','equipamento','servicos','outro') NOT NULL DEFAULT 'outro',
                descricao VARCHAR(255) NOT NULL,
                valor DECIMAL(12,2) NOT NULL,
                data_despesa DATE NOT NULL,
                ingrediente_id INT NULL,
                fornecedor_id INT NULL,
                notas TEXT NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_desp_data (data_despesa),
                INDEX idx_desp_tipo (tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // Backfill preço nas linhas antigas (aproximado pelo preço actual do produto)
    if (columnExists($db, 'encomenda_detalhes', 'preco_unitario')) {
        $db->exec(
            'UPDATE encomenda_detalhes ed
             INNER JOIN produtos p ON p.produto_id = ed.produto_id
             SET ed.preco_unitario = p.preco
             WHERE ed.preco_unitario IS NULL AND p.preco IS NOT NULL'
        );
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Migração 008 aplicada (finanças: preços, despesas, snapshots).',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro na migração 008',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
