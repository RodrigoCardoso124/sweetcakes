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
     * String para assinar pedidos API (Cloudinary signature v2).
     */
    private static function apiStringToSign(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $filtered[(string) $k] = is_array($v) ? implode(',', $v) : (string) $v;
        }
        ksort($filtered);
        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = str_replace('&', '%26', $k . '=' . $v);
        }

        return implode('&', $parts);
    }

    private static function apiSignature(array $params, string $apiSecret): string
    {
        return sha1(self::apiStringToSign($params) . $apiSecret);
    }

    /**
     * URL de download via API Cloudinary (authenticated/private/upload).
     */
    public static function apiPrivateDownloadUrl(
        string $publicId,
        string $format = 'pdf',
        string $resourceType = 'raw',
        ?string $type = null
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
        ];
        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }
        $signature = self::apiSignature($params, (string) $cfg['api_secret']);
        $params['api_key'] = $cfg['api_key'];
        $params['signature'] = $signature;

        return 'https://api.cloudinary.com/v1_1/' . $cfg['cloud_name']
            . '/' . $resourceType . '/download?' . http_build_query($params);
    }

    private static function buildSignedDeliveryUrl(
        string $cloud,
        string $resourceType,
        string $deliveryType,
        ?string $version,
        string $toSign,
        string $apiSecret
    ): string {
        $hash = sha1($toSign . $apiSecret, true);
        $b64 = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        $sig = 's--' . substr($b64, 0, 8) . '--';

        if (preg_match('#^v\d+/.#', $toSign)) {
            $pathAfterSig = $toSign;
        } else {
            $pathAfterSig = ($version !== null && $version !== '' ? 'v' . $version . '/' : '') . $toSign;
        }

        return 'https://res.cloudinary.com/'
            . $cloud . '/'
            . $resourceType . '/'
            . $deliveryType . '/'
            . $sig
            . $pathAfterSig;
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

        $baseId = $meta['public_id'] ?? $parsed['public_id'] ?? '';
        $format = $meta['format'] ?? 'pdf';
        $withExt = $baseId;
        if (!preg_match('/\.' . preg_quote($format, '/') . '$/i', $withExt)) {
            $withExt .= '.' . $format;
        }

        return self::buildSignedDeliveryUrl(
            $cloud,
            $resourceType,
            $deliveryType,
            $version,
            $withExt,
            (string) $cfg['api_secret']
        );
    }

    /**
     * @return string[]
     */
    private static function signedDeliveryUrlVariants(string $secureUrl, ?array $meta): array
    {
        $cfg = self::loadConfig();
        if (empty($cfg['api_secret'])) {
            return [];
        }
        $parsed = self::parseDeliveryUrl($secureUrl);
        if ($parsed === null && $meta === null) {
            return [];
        }

        $cloud = $parsed['cloud'] ?? ($cfg['cloud_name'] ?? '');
        $resourceType = $parsed['resource_type'] ?? 'raw';
        $version = $parsed['version'] ?? (isset($meta['version']) ? (string) $meta['version'] : null);
        $baseId = preg_replace('/\.pdf$/i', '', $meta['public_id'] ?? $parsed['public_id'] ?? '');
        $format = $meta['format'] ?? 'pdf';
        $withExt = $baseId . '.' . $format;

        $types = array_unique([
            $meta['type'] ?? $parsed['delivery_type'] ?? 'upload',
            'authenticated',
            'private',
            'upload',
        ]);

        $toSignList = [$withExt, $baseId];
        if ($version !== null && $version !== '') {
            $toSignList[] = 'v' . $version . '/' . $withExt;
            $toSignList[] = 'v' . $version . '/' . $baseId;
        }

        $urls = [];
        foreach ($types as $deliveryType) {
            foreach ($toSignList as $toSign) {
                $urls[] = self::buildSignedDeliveryUrl(
                    $cloud,
                    $resourceType,
                    $deliveryType,
                    $version,
                    $toSign,
                    (string) $cfg['api_secret']
                );
            }
        }

        return array_values(array_unique($urls));
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
            $apiTypes = [null, 'private', 'authenticated', 'upload'];
            foreach ($apiTypes as $type) {
                $apiUrl = self::apiPrivateDownloadUrl($publicId, $format, $resourceType, $type);
                if ($apiUrl === null) {
                    continue;
                }
                $body = self::downloadBytesDirect($apiUrl, $errApi, true);
                if ($body !== null) {
                    return $body;
                }
                $error = $errApi;
            }
        }

        foreach (self::signedDeliveryUrlVariants($secureUrl, $meta) as $signed) {
            $body = self::downloadBytesDirect($signed, $errSigned);
            if ($body !== null) {
                return $body;
            }
            $error = $errSigned;
        }

        $body = self::downloadBytesDirect($secureUrl, $errDirect);
        if ($body !== null) {
            return $body;
        }

        if (empty($cfg['api_secret'])) {
            $error = ($error ?: $errDirect) . ' — falta CLOUDINARY_API_SECRET no Vercel';
        } else {
            $error = $error ?: $errDirect;
        }

        return null;
    }

    private static function downloadBytesDirect(string $url, ?string &$error = null, bool $apiAuth = false): ?string
    {
        if (!function_exists('curl_init')) {
            $error = 'cURL não disponível';

            return null;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($apiAuth || strpos($url, 'api.cloudinary.com') !== false) {
            $cfg = self::loadConfig();
            if (!empty($cfg['api_key']) && !empty($cfg['api_secret'])) {
                $opts[CURLOPT_USERPWD] = $cfg['api_key'] . ':' . $cfg['api_secret'];
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
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
