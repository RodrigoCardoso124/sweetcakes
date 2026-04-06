<?php
/**
 * Configuração global da aplicação.
 * Sobrescreve com app_config.local.php (não versionar).
 */
$appConfig = [
    'app_env' => 'local',
    'app_debug' => true,
    /** Origens permitidas para CORS (vazio = só mesmo host, sem header Access-Control-Allow-Origin) */
    'cors_origins' => [],
    'session_name' => 'SWEETCAKESSESSID',
    // URL pública da API para links de verificação de email
    // Exemplo: https://sweetcakes-pi.vercel.app/api/index.php
    'public_api_base_url' => null,
];

$localApp = __DIR__ . '/app_config.local.php';
if (file_exists($localApp)) {
    $local = require $localApp;
    if (is_array($local)) {
        $appConfig = array_merge($appConfig, $local);
    }
}

return $appConfig;
