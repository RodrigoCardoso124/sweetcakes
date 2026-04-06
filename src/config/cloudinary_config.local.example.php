<?php
/**
 * Copiar para cloudinary_config.local.php e ajustar.
 * Nota: este ficheiro é apenas exemplo.
 */
return [
    'enabled' => true,
    'cloud_name' => 'o_teu_cloud_name',
    // Opção 1 (recomendada): upload preset unsigned
    'upload_preset' => 'sweetcakes_unsigned',
    // Opção 2 (se não usares preset): API key + secret
    'api_key' => null,
    'api_secret' => null,
    'folder' => 'sweet_cakes/produtos',
];

