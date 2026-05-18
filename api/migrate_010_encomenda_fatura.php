<?php
/**
 * Migração 010 — Fatura com contribuinte na encomenda.
 * GET: /api/migrate_010_encomenda_fatura.php
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
    $db = (new Database())->getConnection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Sem ligação à base de dados']);
        exit;
    }

    if (!columnExists($db, 'encomendas', 'quer_fatura_contribuinte')) {
        $db->exec(
            'ALTER TABLE encomendas ADD COLUMN quer_fatura_contribuinte TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
    if (!columnExists($db, 'encomendas', 'fatura_nif')) {
        $db->exec('ALTER TABLE encomendas ADD COLUMN fatura_nif VARCHAR(20) NULL DEFAULT NULL');
    }

    if (!columnExists($db, 'pessoas', 'nif')) {
        $db->exec('ALTER TABLE pessoas ADD COLUMN nif VARCHAR(20) NULL DEFAULT NULL AFTER morada');
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Migração 010 aplicada (fatura contribuinte na encomenda + NIF em pessoas).',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
