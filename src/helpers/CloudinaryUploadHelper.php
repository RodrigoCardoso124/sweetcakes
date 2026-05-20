<?php

/**
 * Upload de PDF/documentos para Cloudinary (obrigatório em Vercel — disco não persiste).
 */
class CloudinaryUploadHelper
{
    public static function loadConfig(): array
    {
        $cfg = [
            'enabled' => false,
            'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME') ?: null,
            'api_key' => getenv('CLOUDINARY_API_KEY') ?: null,
            'api_secret' => getenv('CLOUDINARY_API_SECRET') ?: null,
            'upload_preset' => getenv('CLOUDINARY_UPLOAD_PRESET') ?: null,
            'folder' => getenv('CLOUDINARY_FATURACAO_FOLDER') ?: 'sweet_cakes/faturacao',
        ];
        $file = __DIR__ . '/../config/cloudinary_config.php';
        if (file_exists($file)) {
            $local = require $file;
            if (is_array($local)) {
                $cfg = array_merge($cfg, $local);
                if (!empty($local['folder']) && empty(getenv('CLOUDINARY_FATURACAO_FOLDER'))) {
                    $cfg['folder'] = rtrim((string) $local['folder'], '/') . '/faturacao';
                }
            }
        }
        $override = __DIR__ . '/../config/cloudinary_config.local.php';
        if (file_exists($override)) {
            $local = require $override;
            if (is_array($local)) {
                $cfg = array_merge($cfg, $local);
            }
        }
        $hasPreset = !empty($cfg['upload_preset']);
        $hasSigned = !empty($cfg['api_key']) && !empty($cfg['api_secret']) && !empty($cfg['cloud_name']);
        $cfg['enabled'] = !empty($cfg['enabled']) && !empty($cfg['cloud_name']) && ($hasPreset || $hasSigned);

        return $cfg;
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::loadConfig()['enabled'] ?? false);
    }

    /**
     * @return string|null secure_url
     */
    public static function uploadRawFile(string $path, string $mime, string $displayName, ?string &$error = null): ?string
    {
        if (!is_readable($path)) {
            $error = 'Ficheiro não legível';

            return null;
        }

        return self::uploadRawCurl(
            new CURLFile($path, $mime ?: 'application/octet-stream', $displayName),
            $error
        );
    }

    /**
     * @return string|null secure_url
     */
    public static function uploadRawBytes(string $bytes, string $mime, string $displayName, ?string &$error = null): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'scfat_');
        if ($tmp === false) {
            $error = 'Sem espaço temporário';

            return null;
        }
        file_put_contents($tmp, $bytes);
        $url = self::uploadRawFile($tmp, $mime, $displayName, $error);
        @unlink($tmp);

        return $url;
    }

    private static function uploadRawCurl(CURLFile $curlFile, ?string &$error = null): ?string
    {
        $cfg = self::loadConfig();
        if (!$cfg['enabled']) {
            $error = 'Cloudinary não configurado (variáveis CLOUDINARY_* no Vercel)';

            return null;
        }
        if (!function_exists('curl_init')) {
            $error = 'cURL não disponível';

            return null;
        }

        $cloudName = $cfg['cloud_name'];
        $folder = $cfg['folder'] ?? 'sweet_cakes/faturacao';
        $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload";
        $postFields = [
            'file' => $curlFile,
            'folder' => $folder,
            'resource_type' => 'raw',
        ];

        if (!empty($cfg['upload_preset'])) {
            $postFields['upload_preset'] = $cfg['upload_preset'];
        } else {
            $timestamp = time();
            $toSign = "folder={$folder}&timestamp={$timestamp}" . $cfg['api_secret'];
            $postFields['api_key'] = $cfg['api_key'];
            $postFields['timestamp'] = $timestamp;
            $postFields['signature'] = sha1($toSign);
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError) {
            $error = 'Cloudinary: ' . ($curlError ?: 'erro de rede');

            return null;
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || empty($decoded['secure_url'])) {
            $error = 'Cloudinary: ' . ($decoded['error']['message'] ?? 'upload recusado');

            return null;
        }

        return (string) $decoded['secure_url'];
    }

    public static function isUrlArmazenamento(string $caminho): bool
    {
        return (bool) preg_match('#^https?://#i', $caminho);
    }
}
