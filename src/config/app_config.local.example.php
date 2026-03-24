<?php
/**
 * Copiar para app_config.local.php no servidor (InfinityFree, etc.).
 *
 * InfinityFree:
 * - Coloca este ficheiro em src/config/app_config.local.php
 * - Mete o teu URL exato em cors_origins (com https://) se usares app noutro domínio
 * - Mantém app_debug false em produção
 */
return [
    'app_env' => 'production',
    'app_debug' => false,
    'cors_origins' => [
        'https://teu-site.infinityfreeapp.com',
    ],
];
