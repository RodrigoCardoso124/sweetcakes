<?php
/**
 * Migração 005 — backfill da tabela fidelidade_pontos a partir das encomendas
 * existentes. Idempotente; pode ser corrida múltiplas vezes sem alterar o
 * resultado final. Acede via:
 *   GET https://<host>/api/migrate_005.php
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../src/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Sem ligação à base de dados']);
        exit;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS fidelidade_pontos (
            pessoas_pessoa_id INT NOT NULL PRIMARY KEY,
            pontos INT NOT NULL DEFAULT 0,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Reset rebuild a partir das encomendas (entregue ou qualquer estado: contam todas as compradas).
    $db->exec(
        "INSERT INTO fidelidade_pontos (pessoas_pessoa_id, pontos)
         SELECT cliente_id, FLOOR(COALESCE(SUM(total), 0))
         FROM encomendas
         WHERE cliente_id IS NOT NULL
         GROUP BY cliente_id
         ON DUPLICATE KEY UPDATE pontos = VALUES(pontos)"
    );

    $stmt = $db->query('SELECT pessoas_pessoa_id, pontos FROM fidelidade_pontos ORDER BY pontos DESC LIMIT 10');
    $top = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'message' => 'Backfill de fidelidade concluído',
        'top10' => $top,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro a correr backfill',
        'error' => $e->getMessage(),
    ]);
}
