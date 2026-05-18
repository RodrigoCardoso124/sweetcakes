<?php
/**
 * Migração 009 — Faturação.
 * GET: /api/migrate_009_faturacao.php
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
    $db = (new Database())->getConnection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Sem ligação à base de dados']);
        exit;
    }

    if (!tableExists($db, 'fatura_series')) {
        $db->exec(
            "CREATE TABLE fatura_series (
                serie_id INT AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(10) NOT NULL UNIQUE,
                descricao VARCHAR(120) NULL,
                proximo_numero INT NOT NULL DEFAULT 1,
                activa TINYINT(1) NOT NULL DEFAULT 1
            )"
        );
        $db->exec(
            "INSERT INTO fatura_series (codigo, descricao, proximo_numero, activa) VALUES ('FT', 'Fatura', 1, 1)"
        );
    }

    if (!tableExists($db, 'faturas_emitidas')) {
        $db->exec(
            "CREATE TABLE faturas_emitidas (
                fatura_id INT AUTO_INCREMENT PRIMARY KEY,
                serie VARCHAR(10) NOT NULL DEFAULT 'FT',
                numero INT NOT NULL,
                encomenda_id INT NULL,
                cliente_nome VARCHAR(200) NOT NULL,
                cliente_nif VARCHAR(20) NULL,
                cliente_morada VARCHAR(500) NULL,
                cliente_email VARCHAR(255) NULL,
                data_emissao DATE NOT NULL,
                estado ENUM('emitida', 'anulada') NOT NULL DEFAULT 'emitida',
                total_base DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_iva DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_com_iva DECIMAL(12,2) NOT NULL DEFAULT 0,
                notas TEXT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_serie_num (serie, numero),
                UNIQUE KEY uk_encomenda (encomenda_id),
                INDEX idx_data (data_emissao),
                INDEX idx_estado (estado)
            )"
        );
    }

    if (!tableExists($db, 'fatura_linhas')) {
        $db->exec(
            "CREATE TABLE fatura_linhas (
                linha_id INT AUTO_INCREMENT PRIMARY KEY,
                fatura_id INT NOT NULL,
                produto_id INT NULL,
                descricao VARCHAR(255) NOT NULL,
                quantidade DECIMAL(12,4) NOT NULL DEFAULT 1,
                preco_unitario_sem_iva DECIMAL(12,4) NOT NULL,
                taxa_iva_pct DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                base_linha DECIMAL(12,2) NOT NULL,
                iva_linha DECIMAL(12,2) NOT NULL,
                total_linha DECIMAL(12,2) NOT NULL,
                INDEX idx_fatura (fatura_id)
            )"
        );
    }

    if (!tableExists($db, 'faturas_recebidas')) {
        $db->exec(
            "CREATE TABLE faturas_recebidas (
                recebida_id INT AUTO_INCREMENT PRIMARY KEY,
                tipo ENUM('fornecedor', 'despesa', 'outro') NOT NULL DEFAULT 'outro',
                pedido_id INT NULL,
                despesa_id INT NULL,
                numero VARCHAR(80) NULL,
                data_documento DATE NOT NULL,
                entidade_nome VARCHAR(200) NOT NULL,
                entidade_nif VARCHAR(20) NULL,
                total_base DECIMAL(12,2) NOT NULL,
                taxa_iva_pct DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                total_iva DECIMAL(12,2) NOT NULL,
                total_com_iva DECIMAL(12,2) NOT NULL,
                notas TEXT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_data (data_documento),
                INDEX idx_tipo (tipo)
            )"
        );
    }

    if (!columnExists($db, 'pessoas', 'nif')) {
        $db->exec('ALTER TABLE pessoas ADD COLUMN nif VARCHAR(20) NULL DEFAULT NULL AFTER morada');
    }

    if (tableExists($db, 'despesas') && !columnExists($db, 'despesas', 'taxa_iva_pct')) {
        $db->exec('ALTER TABLE despesas ADD COLUMN taxa_iva_pct DECIMAL(5,2) NULL DEFAULT 23.00 AFTER valor');
        $db->exec('ALTER TABLE despesas ADD COLUMN total_base DECIMAL(12,2) NULL DEFAULT NULL AFTER taxa_iva_pct');
        $db->exec('ALTER TABLE despesas ADD COLUMN total_iva DECIMAL(12,2) NULL DEFAULT NULL AFTER total_base');
    }

    if (!tableExists($db, 'faturacao_config')) {
        $db->exec(
            'CREATE TABLE faturacao_config (
                config_key VARCHAR(50) NOT NULL PRIMARY KEY,
                config_value VARCHAR(500) NULL
            )'
        );
        $defaults = [
            ['nome', 'Sweet Cakes'],
            ['nif', ''],
            ['morada', ''],
            ['email', ''],
            ['taxa_iva_padrao', '23'],
        ];
        $ins = $db->prepare('INSERT IGNORE INTO faturacao_config (config_key, config_value) VALUES (?,?)');
        foreach ($defaults as $row) {
            $ins->execute($row);
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Migração 009 aplicada (faturação: emitidas, recebidas, NIF clientes).',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
