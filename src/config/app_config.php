<?php
/**
 * Configuração global da aplicação.
 * Sobrescreve com app_config.local.php (não versionar).
 */
$appConfig = [
    'app_env' => 'local',
    'app_debug' => false,
    /** Origens permitidas para CORS (vazio = só mesmo host, sem header Access-Control-Allow-Origin) */
    'cors_origins' => [],
    'session_name' => 'SWEETCAKESSESSID',
    // URL pública da API para links de verificação de email / reset password.
    // Em Vercel, a rewrite /index.php → /api/index.php dropa query strings,
    // por isso geramos URLs apontando directamente para /api/index.php.
    'public_api_base_url' => null,
];

// Em deploys Vercel, define-se a URL pública automaticamente.
if (!empty(getenv('VERCEL'))) {
    $appConfig['app_env'] = 'production';
    $appConfig['app_debug'] = false;
    $appConfig['public_api_base_url'] = 'https://sweetcakes-pi.vercel.app/api/index.php';
}

$localApp = __DIR__ . '/app_config.local.php';
if (file_exists($localApp)) {
    $local = require $localApp;
    if (is_array($local)) {
        $appConfig = array_merge($appConfig, $local);
    }
}

return $appConfig;
