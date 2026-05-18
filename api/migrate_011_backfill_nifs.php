<?php
/**
 * Migração 011 — Atribuir NIF a clientes/pessoas existentes sem NIF.
 * Gera NIFs portugueses válidos (dígito de controlo), únicos por registo.
 *
 * GET: /api/migrate_011_backfill_nifs.php
 */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/helpers/NifHelper.php';

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function normalizarNif(?string $nif): ?string
{
    if ($nif === null) {
        return null;
    }
    $n = preg_replace('/\s+/', '', trim($nif));

    return $n === '' ? null : $n;
}

try {
    $db = (new Database())->getConnection();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Sem ligação à base de dados']);
        exit;
    }

    if (!columnExists($db, 'pessoas', 'nif')) {
        $db->exec('ALTER TABLE pessoas ADD COLUMN nif VARCHAR(20) NULL DEFAULT NULL AFTER morada');
    }

    $usados = [];
    $stmt = $db->query(
        "SELECT nif FROM pessoas WHERE nif IS NOT NULL AND TRIM(nif) <> ''"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $n = normalizarNif($row['nif'] ?? null);
        if ($n !== null) {
            $usados[$n] = true;
        }
    }

    $semNif = $db->query(
        "SELECT pessoa_id, nome, email FROM pessoas
         WHERE nif IS NULL OR TRIM(nif) = ''
         ORDER BY pessoa_id ASC"
    );
    $upd = $db->prepare('UPDATE pessoas SET nif = :nif WHERE pessoa_id = :id');

    $atualizados = [];
    while ($row = $semNif->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $row['pessoa_id'];
        $nif = NifHelper::gerarUnico($id, $usados, 2);
        $upd->execute([':nif' => $nif, ':id' => $id]);
        $atualizados[] = [
            'pessoa_id' => $id,
            'nome' => $row['nome'],
            'email' => $row['email'],
            'nif' => $nif,
        ];
    }

    $encomendasSync = 0;
    if (columnExists($db, 'encomendas', 'fatura_nif')) {
        $encomendasSync = $db->exec(
            'UPDATE encomendas e
             INNER JOIN pessoas p ON p.pessoa_id = e.cliente_id
             SET e.fatura_nif = p.nif
             WHERE p.nif IS NOT NULL AND TRIM(p.nif) <> \'\'
               AND (e.fatura_nif IS NULL OR TRIM(e.fatura_nif) = \'\')'
        );
        if ($encomendasSync === false) {
            $encomendasSync = 0;
        }
    }

    $cfgNif = null;
    if (columnExists($db, 'faturacao_config', 'config_key')) {
        $chk = $db->prepare(
            "SELECT config_value FROM faturacao_config WHERE config_key = 'nif' LIMIT 1"
        );
        $chk->execute();
        $atual = trim((string) ($chk->fetchColumn() ?: ''));
        if ($atual === '') {
            $cfgNif = NifHelper::gerar(999999, 5);
            $ins = $db->prepare(
                'INSERT INTO faturacao_config (config_key, config_value) VALUES (\'nif\', ?)
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
            );
            $ins->execute([$cfgNif]);
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'NIFs atribuídos a pessoas existentes sem NIF.',
        'pessoas_atualizadas' => count($atualizados),
        'detalhes' => $atualizados,
        'encomendas_fatura_nif_sincronizadas' => (int) $encomendasSync,
        'faturacao_config_nif' => $cfgNif,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
