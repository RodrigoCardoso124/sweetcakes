<?php
/**
 * Teste ponta-a-ponta: upload PDF + leitura (como produtos).
 * GET /api/test_pdf_cloudinary.php?access_token=SEU_apiSessionId
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
    echo json_encode(['ok' => false, 'message' => 'Login necessário. Use ?access_token=apiSessionId'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = CloudinaryUploadHelper::loadConfig();
$out = [
    'ok' => false,
    'cloudinary' => [
        'enabled' => (bool) ($cfg['enabled'] ?? false),
        'cloud_name' => $cfg['cloud_name'] ?? null,
        'folder' => $cfg['folder'] ?? null,
        'has_preset' => !empty($cfg['upload_preset']),
        'has_signed' => !empty($cfg['api_key']) && !empty($cfg['api_secret']),
    ],
    'passos' => [],
];

if (!$cfg['enabled']) {
    $out['message'] = CloudinaryUploadHelper::configErrorMessage();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$minimalPdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$err = null;
$url = CloudinaryUploadHelper::uploadRawBytes($minimalPdf, 'application/pdf', 'teste_sweetcakes.pdf', $err);
$out['passos']['upload'] = $url ? ['ok' => true, 'secure_url' => $url] : ['ok' => false, 'erro' => $err];

if ($url) {
    $fetchErr = null;
    $bytes = CloudinaryUploadHelper::fetchUrlBytes($url, $fetchErr);
    $out['passos']['fetch'] = [
        'ok' => $bytes !== null && strncmp($bytes, '%PDF', 4) === 0,
        'bytes' => $bytes !== null ? strlen($bytes) : 0,
        'erro' => $fetchErr,
    ];
    $out['passos']['url_normalizada'] = CloudinaryUploadHelper::normalizeDeliveryUrl($url);
}

$out['ok'] = !empty($out['passos']['upload']['ok']) && !empty($out['passos']['fetch']['ok']);
$out['message'] = $out['ok']
    ? 'Upload e leitura PDF OK — faturação deve funcionar após deploy.'
    : 'Falhou — corrija Cloudinary antes de voltar a testar faturação.';

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
