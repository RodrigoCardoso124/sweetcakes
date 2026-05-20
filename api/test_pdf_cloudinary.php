<?php
/**
 * Teste ponta-a-ponta: upload PDF + leitura.
 * GET /api/test_pdf_cloudinary.php?access_token=SEU_apiSessionId
 */
ini_set('display_errors', '0');
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
        'upload_endpoint' => 'raw/upload',
    ],
    'passos' => [],
];

if (!$cfg['enabled']) {
    $out['message'] = CloudinaryUploadHelper::configErrorMessage();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/** PDF mínimo mas válido (Cloudinary rejeita PDF inválido em raw/upload). */
$minimalPdf = "%PDF-1.4\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/MediaBox[0 0 200 200]/Parent 2 0 R>>endobj\n"
    . "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n"
    . "trailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF\n";

$err = null;
$url = CloudinaryUploadHelper::uploadRawBytes($minimalPdf, 'application/pdf', 'teste_sweetcakes.pdf', $err);
$out['passos']['upload'] = $url ? ['ok' => true, 'secure_url' => $url] : ['ok' => false, 'erro' => $err];

if ($url) {
    $fetchErr = null;
    $bytes = CloudinaryUploadHelper::fetchUrlBytes($url, $fetchErr);
    $parsed = CloudinaryUploadHelper::parseDeliveryUrl($url);
    $out['passos']['fetch'] = [
        'ok' => $bytes !== null && strncmp($bytes, '%PDF', 4) === 0,
        'bytes' => $bytes !== null ? strlen($bytes) : 0,
        'erro' => $fetchErr,
        'public_id' => $parsed['public_id'] ?? null,
        'version' => $parsed['version'] ?? null,
    ];
    $out['passos']['url_normalizada'] = CloudinaryUploadHelper::normalizeDeliveryUrl($url);
    $out['nota_upload'] = 'Novos PDFs usam access_mode=public. PDFs antigos (antes deste deploy) podem precisar de reenvio.';
}

$out['ok'] = !empty($out['passos']['upload']['ok']) && !empty($out['passos']['fetch']['ok']);
$out['message'] = $out['ok']
    ? 'Upload e leitura PDF OK — faturação deve funcionar.'
    : 'Falhou — veja passos.upload / passos.fetch.erro.';

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
