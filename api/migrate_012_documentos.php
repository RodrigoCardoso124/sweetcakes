<?php
/**
 * Migração 012 — Arquivo de ficheiros fiscais (PDF).
 * GET: /api/migrate_012_documentos.php
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

    $sqlFile = __DIR__ . '/../sql/012_documentos_fiscais.sql';
    if (file_exists($sqlFile)) {
        $db->exec(file_get_contents($sqlFile));
    }

    if (tableExists($db, 'faturas_emitidas') && !columnExists($db, 'faturas_emitidas', 'ficheiro_id')) {
        $db->exec('ALTER TABLE faturas_emitidas ADD COLUMN ficheiro_id INT NULL DEFAULT NULL AFTER notas');
    }
    if (tableExists($db, 'faturas_recebidas') && !columnExists($db, 'faturas_recebidas', 'ficheiro_id')) {
        $db->exec('ALTER TABLE faturas_recebidas ADD COLUMN ficheiro_id INT NULL DEFAULT NULL AFTER notas');
    }

    $storageRoot = dirname(__DIR__) . '/storage/faturacao';
    if (!is_dir($storageRoot)) {
        mkdir($storageRoot, 0750, true);
    }
    $htaccess = $storageRoot . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    $index = $storageRoot . '/index.html';
    if (!file_exists($index)) {
        file_put_contents($index, '');
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Migração 012 aplicada (arquivo de documentos fiscais).',
        'storage' => $storageRoot,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
