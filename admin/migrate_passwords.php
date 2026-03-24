<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/helpers/PasswordHelper.php';

$key = isset($_GET['key']) ? (string) $_GET['key'] : '';
if ($key === '' || $key !== 'sweetcakes-migrate-2026') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$db = (new Database($databaseConfig))->getConnection();
if ($db === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$stmt = $db->query("SELECT utilizador_id, password FROM utilizadores");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$total = count($rows);
$alreadyHashed = 0;
$migrated = 0;
$failed = 0;

$update = $db->prepare("UPDATE utilizadores SET password = :pw WHERE utilizador_id = :id");

foreach ($rows as $row) {
    $id = (int) ($row['utilizador_id'] ?? 0);
    $stored = trim((string) ($row['password'] ?? ''));
    $info = password_get_info($stored);
    if (($info['algo'] ?? 0) !== 0) {
        $alreadyHashed++;
        continue;
    }
    $hashed = PasswordHelper::hash($stored);
    $ok = $update->execute([
        ':pw' => $hashed,
        ':id' => $id,
    ]);
    if ($ok) {
        $migrated++;
    } else {
        $failed++;
    }
}

echo json_encode([
    'success' => true,
    'total' => $total,
    'already_hashed' => $alreadyHashed,
    'migrated' => $migrated,
    'failed' => $failed,
]);

