<?php

/**
 * Upload e download de PDF/documentos no Cloudinary (Vercel — disco não persiste).
 */
class CloudinaryUploadHelper
{
    /** Lê env no Vercel (getenv por vezes falha; $_ENV/$_SERVER funcionam). */
    private static function envVar(string $key): ?string
    {
        if (!empty($_ENV[$key]) && is_string($_ENV[$key])) {
            return trim($_ENV[$key]);
        }
        if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return trim($_SERVER[$key]);
        }
        $v = getenv($key);

        return ($v !== false && $v !== '') ? trim((string) $v) : null;
    }

    public static function loadConfig(): array
    {
        $cfg = [
            'enabled' => true,
            'cloud_name' => null,
            'api_key' => null,
            'api_secret' => null,
            'upload_preset' => null,
            'folder' => 'sweet_cakes/produtos/faturacao',
        ];

        $file = __DIR__ . '/../config/cloudinary_config.php';
        if (file_exists($file)) {
            $local = require $file;
            if (is_array($local)) {
                $cfg = array_merge($cfg, $local);
            }
        }
        $override = __DIR__ . '/../config/cloudinary_config.local.php';
        if (file_exists($override)) {
            $local = require $override;
            if (is_array($local)) {
                $cfg = array_merge($cfg, $local);
            }
        }

        // Produção (Vercel): variáveis de ambiente têm prioridade sobre ficheiros locais
        $envMap = [
            'cloud_name' => 'CLOUDINARY_CLOUD_NAME',
            'api_key' => 'CLOUDINARY_API_KEY',
            'api_secret' => 'CLOUDINARY_API_SECRET',
            'upload_preset' => 'CLOUDINARY_UPLOAD_PRESET',
        ];
        foreach ($envMap as $cfgKey => $envKey) {
            $val = self::envVar($envKey);
            if ($val !== null) {
                $cfg[$cfgKey] = $val;
            }
        }

        $fatFolder = self::envVar('CLOUDINARY_FATURACAO_FOLDER');
        if ($fatFolder !== null) {
            $cfg['folder'] = $fatFolder;
        } else {
            $base = self::envVar('CLOUDINARY_FOLDER') ?: ($cfg['folder'] ?? 'sweet_cakes/produtos');
            $base = rtrim((string) $base, '/');
            if (!preg_match('#/faturacao$#i', $base)) {
                $base .= '/faturacao';
            }
            $cfg['folder'] = $base;
        }

        $hasPreset = !empty($cfg['upload_preset']) && !empty($cfg['cloud_name']);
        $hasSigned = !empty($cfg['api_key']) && !empty($cfg['api_secret']) && !empty($cfg['cloud_name']);
        $allow = !array_key_exists('enabled', $cfg) || $cfg['enabled'] !== false;
        $cfg['enabled'] = $allow && !empty($cfg['cloud_name']) && ($hasPreset || $hasSigned);
        $cfg['missing'] = [];
        if (empty($cfg['cloud_name'])) {
            $cfg['missing'][] = 'CLOUDINARY_CLOUD_NAME';
        }
        if (!$hasPreset && !$hasSigned) {
            $cfg['missing'][] = 'CLOUDINARY_API_KEY + CLOUDINARY_API_SECRET (ou CLOUDINARY_UPLOAD_PRESET)';
        }

        return $cfg;
    }

    /** Cloud name da config ou extraído do secure_url guardado na BD. */
    public static function resolveCloudName(?string $secureUrl = null): ?string
    {
        $cfg = self::loadConfig();
        if (!empty($cfg['cloud_name'])) {
            return (string) $cfg['cloud_name'];
        }
        if ($secureUrl && preg_match('#res\.cloudinary\.com/([^/]+)/#i', $secureUrl, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function configErrorMessage(): string
    {
        $cfg = self::loadConfig();
        if (!empty($cfg['missing'])) {
            return 'Cloudinary incompleto no Vercel: ' . implode(', ', $cfg['missing'])
                . '. Para PDFs use CLOUDINARY_CLOUD_NAME (ex.: djmseghov), API_KEY e API_SECRET.';
        }

        return 'Cloudinary não configurado.';
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
            $error = self::configErrorMessage();

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

        if (empty($decoded['public_id']) || (int) ($decoded['bytes'] ?? 0) <= 0) {
            $error = 'Cloudinary não confirmou o PDF (resposta incompleta).';

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
            '#res\.cloudinary\.com/([^/]+)/(raw|image)/(upload|authenticated|private)(?:/s--[^/]+--/)?(?:/v(\d+))?/(.+?)(?:\?.*)?$#i',
            $url,
            $m
        )) {
            return null;
        }

        $publicId = ltrim($m[5], '/');
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

    /** public_id sem .pdf nem prefixo v123456/ (erro comum na API download). */
    private static function sanitizePublicId(string $publicId): string
    {
        $publicId = trim($publicId, " \t\n\r\0\x0B/");
        $publicId = preg_replace('/\.pdf$/i', '', $publicId);
        $publicId = preg_replace('#^v\d+/#', '', $publicId);

        return trim($publicId, '/');
    }

    private static function versionFromMetaOrUrl(?array $meta, ?array $parsed): ?string
    {
        if (is_array($meta) && isset($meta['version'])) {
            return (string) $meta['version'];
        }
        if (is_array($parsed) && !empty($parsed['version'])) {
            return (string) $parsed['version'];
        }

        return null;
    }

    /**
     * Metadados via Admin API (public_id exacto, type, format).
     */
    public static function adminFetchResource(string $secureUrl): ?array
    {
        $parsed = self::parseDeliveryUrl($secureUrl);
        $cfg = self::loadConfig();
        $cloudName = self::resolveCloudName($secureUrl);
        if ($parsed === null || empty($cfg['api_key']) || empty($cfg['api_secret']) || empty($cloudName)) {
            return null;
        }

        $apiId = preg_replace('/\.pdf$/i', '', $parsed['public_id']);
        $apiId = self::sanitizePublicId($apiId);
        $idVariants = [$apiId];
        $base = basename($apiId);
        if ($base !== '' && $base !== $apiId) {
            $idVariants[] = $base;
        }
        if (preg_match('#^(.+)/faturacao/([^/]+)$#', $apiId, $fm)) {
            $idVariants[] = $fm[1] . '/' . $fm[2];
            $idVariants[] = 'sweet_cakes/produtos/' . $fm[2];
        }
        $idVariants = array_values(array_unique(array_filter($idVariants)));

        $types = array_values(array_unique([$parsed['delivery_type'], 'upload', 'authenticated', 'private']));

        foreach ($idVariants as $tryId) {
            $encoded = str_replace('/', '%2F', $tryId);
            foreach ($types as $type) {
                $url = 'https://api.cloudinary.com/v1_1/' . $cloudName
                    . '/resources/' . $parsed['resource_type'] . '/' . $type . '/' . $encoded;

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD => $cfg['api_key'] . ':' . $cfg['api_secret'],
                    CURLOPT_TIMEOUT => 10,
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
        ?string $type = null,
        ?string $version = null
    ): ?string {
        $cfg = self::loadConfig();
        $cloudName = self::resolveCloudName();
        if (empty($cfg['api_key']) || empty($cfg['api_secret']) || empty($cloudName)) {
            return null;
        }

        $publicId = self::sanitizePublicId($publicId);
        if ($publicId === '') {
            return null;
        }

        $timestamp = time();
        $params = [
            'format' => $format,
            'public_id' => $publicId,
            'timestamp' => (string) $timestamp,
        ];
        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }
        if ($version !== null && $version !== '') {
            $params['version'] = (string) $version;
        }
        $signature = self::apiSignature($params, (string) $cfg['api_secret']);
        $params['api_key'] = $cfg['api_key'];
        $params['signature'] = $signature;

        return 'https://api.cloudinary.com/v1_1/' . $cloudName
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

        $cloud = $parsed['cloud'] ?? self::resolveCloudName($secureUrl) ?? '';
        $resourceType = $parsed['resource_type'] ?? 'raw';
        $deliveryType = $meta['type'] ?? $parsed['delivery_type'] ?? 'upload';
        $version = $parsed['version'] ?? (isset($meta['version']) ? (string) $meta['version'] : null);

        $baseId = self::sanitizePublicId((string) ($meta['public_id'] ?? $parsed['public_id'] ?? ''));
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
     * Reconstrói URL CDN com extensão .pdf e tipo de entrega correcto (upload/authenticated).
     */
    public static function normalizeDeliveryUrl(string $secureUrl, ?array $meta = null): string
    {
        $parsed = self::parseDeliveryUrl($secureUrl);
        if ($parsed === null) {
            if (!preg_match('/\.pdf$/i', $secureUrl)) {
                return preg_replace('/(\?[^#]*)?$/', '.pdf$1', $secureUrl);
            }

            return $secureUrl;
        }

        $publicId = $parsed['public_id'];
        if ($parsed['resource_type'] === 'raw' && !preg_match('/\.pdf$/i', $publicId)) {
            $publicId .= '.pdf';
        }

        $deliveryType = (string) ($meta['type'] ?? $parsed['delivery_type']);
        $version = isset($meta['version']) ? (string) $meta['version'] : $parsed['version'];
        $vPart = $version !== null && $version !== '' ? 'v' . $version . '/' : '';

        return 'https://res.cloudinary.com/'
            . $parsed['cloud'] . '/'
            . $parsed['resource_type'] . '/'
            . $deliveryType . '/'
            . $vPart
            . $publicId;
    }

    /**
     * URL para o browser abrir o PDF.
     * Prioridade: API /raw/download (funciona com authenticated) → CDN assinada → CDN directa.
     */
    public static function browserDownloadUrl(string $secureUrl): ?string
    {
        $cfg = self::loadConfig();
        $parsed = self::parseDeliveryUrl($secureUrl);
        $meta = self::adminFetchResource($secureUrl);

        if (!is_array($meta)) {
            if ($parsed === null) {
                return null;
            }
            $publicId = self::sanitizePublicId($parsed['public_id']);
            $format = 'pdf';
            $resourceType = $parsed['resource_type'];
            $version = $parsed['version'];
            if ($publicId !== '' && !empty($cfg['api_secret'])) {
                foreach (['upload', 'authenticated', null] as $type) {
                    $apiUrl = self::apiPrivateDownloadUrl($publicId, $format, $resourceType, $type, $version);
                    if ($apiUrl !== null) {
                        return $apiUrl;
                    }
                }
            }
            if (!empty($cfg['api_secret'])) {
                $signed = self::signedDeliveryUrl($secureUrl, null);
                if ($signed) {
                    return $signed;
                }
            }

            return self::normalizeDeliveryUrl($secureUrl);
        }

        $publicId = self::sanitizePublicId((string) ($meta['public_id'] ?? ''));
        $format = (string) ($meta['format'] ?? 'pdf');
        $resourceType = (string) ($meta['resource_type'] ?? 'raw');
        $version = self::versionFromMetaOrUrl($meta, $parsed);
        $base = (string) ($meta['secure_url'] ?? $secureUrl);

        if ($publicId !== '' && !empty($cfg['api_secret'])) {
            $types = array_values(array_unique(array_filter([
                $meta['type'] ?? null,
                'upload',
                'authenticated',
                null,
            ], static fn ($t) => $t !== '')));
            foreach ($types as $type) {
                $apiUrl = self::apiPrivateDownloadUrl($publicId, $format, $resourceType, $type, $version);
                if ($apiUrl !== null) {
                    return $apiUrl;
                }
            }
        }

        if (!empty($cfg['api_secret'])) {
            $signedList = self::signedDeliveryUrlCandidates($base, $meta);
            if ($signedList !== []) {
                return $signedList[0];
            }
        }

        return self::normalizeDeliveryUrl($base, $meta);
    }

    /**
     * Várias URLs assinadas possíveis (Cloudinary varia com/sem versão no toSign).
     *
     * @return string[]
     */
    private static function signedDeliveryUrlCandidates(string $secureUrl, ?array $meta): array
    {
        $cfg = self::loadConfig();
        if (empty($cfg['api_secret'])) {
            return [];
        }
        $parsed = self::parseDeliveryUrl($secureUrl);
        if ($parsed === null && $meta === null) {
            return [];
        }

        $cloud = $parsed['cloud'] ?? self::resolveCloudName($secureUrl) ?? '';
        $resourceType = $parsed['resource_type'] ?? 'raw';
        $deliveryType = (string) ($meta['type'] ?? $parsed['delivery_type'] ?? 'authenticated');
        $version = $parsed['version'] ?? (isset($meta['version']) ? (string) $meta['version'] : null);

        $baseId = self::sanitizePublicId((string) ($meta['public_id'] ?? $parsed['public_id'] ?? ''));
        $format = (string) ($meta['format'] ?? 'pdf');
        $withExt = $baseId . '.' . $format;

        $toSignList = [$withExt];
        if ($version !== null && $version !== '') {
            array_unshift($toSignList, 'v' . $version . '/' . $withExt);
        }

        $urls = [];
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

        return array_values(array_unique($urls));
    }

    private static function looksLikePdf(?string $body): bool
    {
        return $body !== null && strlen($body) > 50 && strncmp($body, '%PDF', 4) === 0;
    }

    public static function downloadBytes(string $secureUrl, ?string &$error = null): ?string
    {
        $cfg = self::loadConfig();
        $parsed = self::parseDeliveryUrl($secureUrl);
        $meta = self::adminFetchResource($secureUrl);

        $publicId = self::sanitizePublicId((string) ($meta['public_id'] ?? $parsed['public_id'] ?? ''));
        $format = (string) ($meta['format'] ?? 'pdf');
        $resourceType = (string) ($meta['resource_type'] ?? $parsed['resource_type'] ?? 'raw');
        $deliveryType = (string) ($meta['type'] ?? $parsed['delivery_type'] ?? 'upload');
        $accessMode = (string) ($meta['access_mode'] ?? '');
        $version = self::versionFromMetaOrUrl($meta, $parsed);

        $candidates = [];

        $cdn = self::normalizeDeliveryUrl($secureUrl, $meta);
        if ($accessMode === 'public' || $deliveryType === 'upload') {
            $candidates[] = ['url' => $cdn, 'api' => false];
            if (!empty($meta['secure_url'])) {
                $candidates[] = ['url' => self::normalizeDeliveryUrl((string) $meta['secure_url'], $meta), 'api' => false];
            }
        }

        if ($publicId !== '' && !empty($cfg['api_secret'])) {
            $apiUrl = self::apiPrivateDownloadUrl($publicId, $format, $resourceType, $deliveryType, $version);
            if ($apiUrl) {
                $candidates[] = ['url' => $apiUrl, 'api' => true];
            }
        }

        $signed = self::signedDeliveryUrl($secureUrl, $meta);
        if ($signed) {
            $candidates[] = ['url' => $signed, 'api' => false];
        }

        $lastErr = null;
        foreach ($candidates as $c) {
            $body = self::downloadBytesDirect($c['url'], $err, !empty($c['api']));
            if (self::looksLikePdf($body)) {
                return $body;
            }
            $lastErr = $err;
        }

        $error = $lastErr ?: 'Não foi possível obter o PDF do Cloudinary';
        if (!empty($cfg['missing'])) {
            $error .= ' — ' . self::configErrorMessage();
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
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
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
