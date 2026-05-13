<?php
/**
 * Migração 006 — repara promoções de uso único.
 *
 * Garante que existe promocao_uso, remove duplicados, adiciona unique
 * (promocao_id, pessoa_id) e faz backfill a partir das encomendas antigas.
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
        "CREATE TABLE IF NOT EXISTS promocao_uso (
            promocao_uso_id INT AUTO_INCREMENT PRIMARY KEY,
            promocao_id INT NOT NULL,
            pessoa_id INT NOT NULL,
            encomenda_id INT NULL DEFAULT NULL,
            desconto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            usado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_promocao_uso_pessoa (pessoa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "INSERT IGNORE INTO promocao_uso (promocao_id, pessoa_id, encomenda_id, desconto)
         SELECT promocao_id, cliente_id, encomenda_id, COALESCE(desconto, 0)
         FROM encomendas
         WHERE promocao_id IS NOT NULL
           AND promocao_id > 0
           AND cliente_id IS NOT NULL
           AND cliente_id > 0"
    );

    $db->exec(
        "DELETE u1 FROM promocao_uso u1
         INNER JOIN promocao_uso u2
           ON u1.promocao_id = u2.promocao_id
          AND u1.pessoa_id = u2.pessoa_id
          AND u1.promocao_uso_id > u2.promocao_uso_id"
    );

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'promocao_uso'
           AND INDEX_NAME = 'uniq_promocao_pessoa'"
    );
    $stmt->execute();
    $hasUnique = (int) $stmt->fetchColumn() > 0;

    if (!$hasUnique) {
        $db->exec('ALTER TABLE promocao_uso ADD UNIQUE KEY uniq_promocao_pessoa (promocao_id, pessoa_id)');
    }

    $count = (int) $db->query('SELECT COUNT(*) FROM promocao_uso')->fetchColumn();

    echo json_encode([
        'ok' => true,
        'message' => 'Promoções de uso único reparadas',
        'registos_uso' => $count,
        'unique_key' => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro ao reparar promoções de uso único',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
