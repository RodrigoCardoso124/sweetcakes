<?php

/**
 * Upload e download de PDF/documentos no Cloudinary (Vercel — disco não persiste).
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
            'format' => 'pdf',
            'type' => 'upload',
            'access_mode' => 'public',
        ];

        if (!empty($cfg['upload_preset'])) {
            $postFields['upload_preset'] = $cfg['upload_preset'];
        } else {
            $timestamp = time();
            $signParams = [
                'access_mode' => 'public',
                'folder' => $folder,
                'format' => 'pdf',
                'timestamp' => (string) $timestamp,
                'type' => 'upload',
            ];
            ksort($signParams);
            $pairs = [];
            foreach ($signParams as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $toSign = implode('&', $pairs) . $cfg['api_secret'];
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

    /**
     * @return array{cloud: string, resource_type: string, delivery_type: string, version: ?string, public_id: string}|null
     */
    public static function parseDeliveryUrl(string $url): ?array
    {
        if (!preg_match(
            '#res\.cloudinary\.com/([^/]+)/(raw|image)/(upload|authenticated|private)(?:/s--[^/]+--/)?(?:v(\d+)/)?(.+?)(?:\?.*)?$#i',
            $url,
            $m
        )) {
            return null;
        }

        $publicId = $m[5];
        while (preg_match('#^(?:fl_[^/]+|c_[^/]+|w_\d+|h_\d+)(?:,[^/]+)*/#', $publicId)) {
            $publicId = preg_replace('#^[^/]+/#', '', $publicId, 1);
        }

        return [
            'cloud' => $m[1],
            'resource_type' => strtolower($m[2]),
            'delivery_type' => strtolower($m[3]),
            'version' => isset($m[4]) && $m[4] !== '' ? $m[4] : null,
            'public_id' => $publicId,
        ];
    }

    public static function publicIdFromUrl(string $url): ?string
    {
        $p = self::parseDeliveryUrl($url);

        return $p ? $p['public_id'] : null;
    }

    /**
     * Metadados via Admin API (public_id exacto, type, format).
     */
    public static function adminFetchResource(string $secureUrl): ?array
    {
        $parsed = self::parseDeliveryUrl($secureUrl);
        $cfg = self::loadConfig();
        if ($parsed === null || empty($cfg['api_key']) || empty($cfg['api_secret']) || empty($cfg['cloud_name'])) {
            return null;
        }

        $apiId = preg_replace('/\.pdf$/i', '', $parsed['public_id']);
        $encoded = str_replace('/', '%252F', $apiId);
        $types = array_unique([$parsed['delivery_type'], 'authenticated', 'upload', 'private']);

        foreach ($types as $type) {
            $url = 'https://api.cloudinary.com/v1_1/' . $cfg['cloud_name']
                . '/resources/' . $parsed['resource_type'] . '/' . $type . '/' . $encoded;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $cfg['api_key'] . ':' . $cfg['api_secret'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response && $code >= 200 && $code < 300) {
                $data = json_decode($response, true);
                if (is_array($data) && !empty($data['public_id'])) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * Assinatura API (hex SHA-1) para endpoints como /raw/download.
     */
    private static function apiSignature(array $params, string $apiSecret): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }

        return sha1(implode('&', $pairs) . $apiSecret);
    }

    /**
     * URL de download via API Cloudinary (funciona com authenticated/private).
     * https://cloudinary.com/documentation/control_access_to_media#providing_time-limited_access_to_private_media_assets
     */
    public static function apiPrivateDownloadUrl(
        string $publicId,
        string $format = 'pdf',
        string $resourceType = 'raw',
        string $type = 'authenticated'
    ): ?string {
        $cfg = self::loadConfig();
        if (empty($cfg['api_key']) || empty($cfg['api_secret']) || empty($cfg['cloud_name'])) {
            return null;
        }

        $publicId = preg_replace('/\.pdf$/i', '', $publicId);
        $timestamp = time();
        $params = [
            'format' => $format,
            'public_id' => $publicId,
            'timestamp' => (string) $timestamp,
            'type' => $type,
        ];
        $signature = self::apiSignature($params, (string) $cfg['api_secret']);
        $params['api_key'] = $cfg['api_key'];
        $params['signature'] = $signature;

        return 'https://api.cloudinary.com/v1_1/' . $cfg['cloud_name']
            . '/' . $resourceType . '/download?' . http_build_query($params);
    }

    /**
     * URL de entrega assinada (s--xxxx--) — algoritmo oficial Cloudinary PHP SDK.
     */
    public static function signedDeliveryUrl(string $secureUrl, ?array $meta = null): ?string
    {
        $cfg = self::loadConfig();
        if (empty($cfg['api_secret'])) {
            return null;
        }

        $parsed = self::parseDeliveryUrl($secureUrl);
        if ($parsed === null && $meta === null) {
            return null;
        }

        $cloud = $parsed['cloud'] ?? ($cfg['cloud_name'] ?? '');
        $resourceType = $parsed['resource_type'] ?? 'raw';
        $deliveryType = $meta['type'] ?? $parsed['delivery_type'] ?? 'upload';
        $version = $parsed['version'] ?? (isset($meta['version']) ? (string) $meta['version'] : null);

        $publicId = $meta['public_id'] ?? $parsed['public_id'] ?? '';
        $format = $meta['format'] ?? 'pdf';
        if (!preg_match('/\.' . preg_quote($format, '/') . '$/i', $publicId)) {
            $publicId .= '.' . $format;
        }

        $hash = sha1($publicId . $cfg['api_secret'], true);
        $b64 = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        $sig = 's--' . substr($b64, 0, 8) . '--';
        $vPart = $version !== null && $version !== '' ? 'v' . $version . '/' : '';

        return 'https://res.cloudinary.com/'
            . $cloud . '/'
            . $resourceType . '/'
            . $deliveryType . '/'
            . $sig
            . $vPart
            . $publicId;
    }

    public static function downloadBytes(string $secureUrl, ?string &$error = null): ?string
    {
        $cfg = self::loadConfig();
        $parsed = self::parseDeliveryUrl($secureUrl);
        $meta = self::adminFetchResource($secureUrl);

        $publicId = $meta['public_id'] ?? ($parsed['public_id'] ?? '');
        $format = $meta['format'] ?? 'pdf';
        $resourceType = $meta['resource_type'] ?? ($parsed['resource_type'] ?? 'raw');
        $deliveryType = $meta['type'] ?? ($parsed['delivery_type'] ?? 'upload');

        if ($publicId !== '' && !empty($cfg['api_secret'])) {
            $types = array_unique([$deliveryType, 'authenticated', 'upload', 'private']);
            foreach ($types as $type) {
                $apiUrl = self::apiPrivateDownloadUrl($publicId, $format, $resourceType, $type);
                if ($apiUrl === null) {
                    continue;
                }
                $body = self::downloadBytesDirect($apiUrl, $errApi);
                if ($body !== null) {
                    return $body;
                }
            }
        }

        if ($parsed !== null && in_array($parsed['delivery_type'], ['authenticated', 'private'], true)) {
            $signed = self::signedDeliveryUrl($secureUrl, $meta);
            if ($signed !== null) {
                $body = self::downloadBytesDirect($signed, $errSigned);
                if ($body !== null) {
                    return $body;
                }
                $error = $errSigned;
            }
        }

        $body = self::downloadBytesDirect($secureUrl, $errDirect);
        if ($body !== null) {
            return $body;
        }

        if (!empty($cfg['api_secret'])) {
            $signed = self::signedDeliveryUrl($secureUrl, $meta);
            if ($signed !== null && $signed !== $secureUrl) {
                $body = self::downloadBytesDirect($signed, $errSigned2);
                if ($body !== null) {
                    return $body;
                }
                $error = $errSigned2 ?: $errDirect;
            }
        } else {
            $error = ($error ?: $errDirect) . ' — falta CLOUDINARY_API_SECRET no Vercel';
        }

        $error = $error ?: $errDirect;

        return null;
    }

    private static function downloadBytesDirect(string $url, ?string &$error = null): ?string
    {
        if (!function_exists('curl_init')) {
            $error = 'cURL não disponível';

            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 400) {
            $error = 'Não foi possível obter o PDF: ' . ($curlErr ?: 'HTTP ' . $code);

            return null;
        }

        return $body;
    }
}
