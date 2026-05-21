<?php
/**
 * Migração 013 — PDFs na base de dados (Vercel).
 * GET: /api/migrate_013_documento_conteudo.php
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

    if (!tableExists($db, 'documento_ficheiros')) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'message' => 'Execute primeiro /api/migrate_012_documentos.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!columnExists($db, 'documento_ficheiros', 'conteudo')) {
        $sqlFile = __DIR__ . '/../sql/013_documento_conteudo_bd.sql';
        if (file_exists($sqlFile)) {
            $db->exec(file_get_contents($sqlFile));
        } else {
            $db->exec(
                'ALTER TABLE documento_ficheiros ADD COLUMN conteudo MEDIUMBLOB NULL AFTER caminho_relativo'
            );
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Migração 013 aplicada — PDFs passam a guardar-se na base de dados (Vercel).',
        'coluna_conteudo' => columnExists($db, 'documento_ficheiros', 'conteudo'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
