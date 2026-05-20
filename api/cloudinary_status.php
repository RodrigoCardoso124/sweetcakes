<?php
/**
 * Diagnóstico Cloudinary (sessão admin). GET /api/cloudinary_status.php
 */
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../src/config/app_config.php';
require_once __DIR__ . '/../src/helpers/Auth.php';
require_once __DIR__ . '/../src/helpers/CloudinaryUploadHelper.php';

$appConfig = require __DIR__ . '/../src/config/app_config.php';
Auth::setConfig($appConfig);
Auth::startSession();

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'message' => 'Sessão não ativa.',
        'hint' => 'Faça login no painel admin. Depois abra este URL com o token: '
            . '/api/cloudinary_status.php?access_token=COLE_AQUI_o_valor_de_localStorage_apiSessionId '
            . '(F12 → Application → Local Storage → apiSessionId).',
        'alternativa' => 'Na consola do painel (F12): API.getSessionInfo() — após deploy inclui cloudinary.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

$cfg = CloudinaryUploadHelper::loadConfig();

$probeUrl = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$probe = null;
if ($probeUrl !== '' && preg_match('#^https?://#i', $probeUrl)) {
    $norm = CloudinaryUploadHelper::normalizeDeliveryUrl($probeUrl);
    $err = null;
    $bytes = CloudinaryUploadHelper::fetchUrlBytes($probeUrl, $err);
    $probe = [
        'url' => $probeUrl,
        'url_normalizada' => $norm,
        'pdf_ok' => $bytes !== null && strncmp($bytes, '%PDF', 4) === 0,
        'bytes' => $bytes !== null ? strlen($bytes) : 0,
        'erro' => $err,
        'nota' => 'O painel abre PDFs via /faturacao?view=download (mesmo domínio), não via URL Cloudinary no browser.',
    ];
}

$payload = [
    'cloudinary' => [
        'enabled' => (bool) ($cfg['enabled'] ?? false),
        'cloud_name' => $cfg['cloud_name'] ?? null,
        'folder_pdfs' => $cfg['folder'] ?? null,
        'has_api_key' => !empty($cfg['api_key']),
        'has_api_secret' => !empty($cfg['api_secret']),
        'has_upload_preset' => !empty($cfg['upload_preset']),
        'missing' => $cfg['missing'] ?? [],
        'nota' => 'PDFs usam image/upload (igual às fotos), pasta CLOUDINARY_FOLDER/faturacao. Teste completo: /api/test_pdf_cloudinary.php?access_token=...',
    ],
    'env_detected' => [
        'CLOUDINARY_CLOUD_NAME' => !empty($_ENV['CLOUDINARY_CLOUD_NAME']) || !empty($_SERVER['CLOUDINARY_CLOUD_NAME']) || getenv('CLOUDINARY_CLOUD_NAME'),
        'CLOUDINARY_API_KEY' => !empty($_ENV['CLOUDINARY_API_KEY']) || !empty($_SERVER['CLOUDINARY_API_KEY']) || getenv('CLOUDINARY_API_KEY'),
        'CLOUDINARY_API_SECRET' => !empty($_ENV['CLOUDINARY_API_SECRET']) || !empty($_SERVER['CLOUDINARY_API_SECRET']) || getenv('CLOUDINARY_API_SECRET'),
        'CLOUDINARY_FOLDER' => !empty($_ENV['CLOUDINARY_FOLDER']) || !empty($_SERVER['CLOUDINARY_FOLDER']) || getenv('CLOUDINARY_FOLDER'),
    ],
];

if ($probe !== null) {
    $payload['probe'] = $probe;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
