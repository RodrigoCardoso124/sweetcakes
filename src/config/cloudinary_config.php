<?php
/**
 * Configuração de upload de imagens para Cloudinary.
 * Em produção (Vercel), preferir variáveis de ambiente.
 */
return [
    'enabled' => false,
    'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME') ?: null,
    'api_key' => getenv('CLOUDINARY_API_KEY') ?: null,
    'api_secret' => getenv('CLOUDINARY_API_SECRET') ?: null,
    'upload_preset' => getenv('CLOUDINARY_UPLOAD_PRESET') ?: null,
    'folder' => getenv('CLOUDINARY_FOLDER') ?: 'sweet_cakes/produtos',
];

