<?php
/**
 * Configuração de upload de imagens para Cloudinary.
 * Em produção (Vercel), preferir variáveis de ambiente.
 */
return [
    // true por padrão para usar Cloudinary quando houver credenciais/env vars.
    // Se quiser desativar explicitamente, usar cloudinary_config.local.php com enabled => false.
    'enabled' => true,
    'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME') ?: null,
    'api_key' => getenv('CLOUDINARY_API_KEY') ?: null,
    'api_secret' => getenv('CLOUDINARY_API_SECRET') ?: null,
    'upload_preset' => getenv('CLOUDINARY_UPLOAD_PRESET') ?: null,
    'folder' => getenv('CLOUDINARY_FOLDER') ?: 'sweet_cakes/produtos',
];

