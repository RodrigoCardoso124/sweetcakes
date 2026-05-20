<?php

/**
 * PDFs no Cloudinary — mesmo fluxo que ProdutoController::uploadImagemCloudinary.
 * Guarda secure_url na BD; o painel serve via /faturacao?view=download (proxy, mesmo domínio).
 */
class CloudinaryUploadHelper
{
    /** PHP 8.5+: curl_close() está obsoleto (handle liberta-se automaticamente). */
    private static function curlClose($ch): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    }

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
            'folder' => 'sweet_cakes/produtos',
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

        foreach ([
            'cloud_name' => 'CLOUDINARY_CLOUD_NAME',
            'api_key' => 'CLOUDINARY_API_KEY',
            'api_secret' => 'CLOUDINARY_API_SECRET',
            'upload_preset' => 'CLOUDINARY_UPLOAD_PRESET',
        ] as $cfgKey => $envKey) {
            $val = self::envVar($envKey);
            if ($val !== null) {
                $cfg[$cfgKey] = $val;
            }
        }

        $base = self::envVar('CLOUDINARY_FATURACAO_FOLDER')
            ?: self::envVar('CLOUDINARY_FOLDER')
            ?: ($cfg['folder'] ?? 'sweet_cakes/produtos');
        $base = rtrim((string) $base, '/');
        if (!preg_match('#/faturacao$#i', $base)) {
            $base .= '/faturacao';
        }
        $cfg['folder'] = $base;

        foreach (['cloud_name', 'api_key', 'api_secret', 'upload_preset'] as $k) {
            if (!empty($cfg[$k]) && is_string($cfg[$k])) {
                $cfg[$k] = trim($cfg[$k]);
            }
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
            $cfg['missing'][] = 'CLOUDINARY_UPLOAD_PRESET ou CLOUDINARY_API_KEY + CLOUDINARY_API_SECRET';
        }

        return $cfg;
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::loadConfig()['enabled'] ?? false);
    }

    public static function configErrorMessage(): string
    {
        $cfg = self::loadConfig();
        if (!empty($cfg['missing'])) {
            return 'Cloudinary: ' . implode(', ', $cfg['missing']);
        }

        return 'Cloudinary não configurado.';
    }

    public static function isUrlArmazenamento(string $caminho): bool
    {
        return (bool) preg_match('#^https?://#i', $caminho);
    }

    public static function uploadRawFile(string $path, string $mime, string $displayName, ?string &$error = null): ?string
    {
        if (!is_readable($path)) {
            $error = 'Ficheiro não legível';

            return null;
        }

        return self::uploadPdfCurl(
            new CURLFile($path, $mime ?: 'application/pdf', $displayName),
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

    /**
     * Upload PDF com raw/upload (PDF não pode ir para image/upload).
     * Assinatura igual às fotos: folder + timestamp + secret (só muda o endpoint).
     */
    private static function uploadPdfCurl(CURLFile $curlFile, ?string &$error = null): ?string
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
        $folder = $cfg['folder'];
        $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload";
        $postFields = [
            'file' => $curlFile,
            'folder' => $folder,
        ];

        if (!empty($cfg['upload_preset'])) {
            $postFields['upload_preset'] = $cfg['upload_preset'];
        } else {
            $apiKey = $cfg['api_key'];
            $apiSecret = $cfg['api_secret'];
            if (empty($apiKey) || empty($apiSecret)) {
                $error = 'Cloudinary sem upload_preset ou API key/secret';

                return null;
            }
            $timestamp = time();
            $signature = sha1('folder=' . $folder . '&timestamp=' . $timestamp . $apiSecret);
            $postFields['api_key'] = $apiKey;
            $postFields['timestamp'] = $timestamp;
            $postFields['signature'] = $signature;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        self::curlClose($ch);

        if ($response === false || $curlError) {
            $error = 'Cloudinary: ' . ($curlError ?: 'erro de rede');

            return null;
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || empty($decoded['secure_url'])) {
            $error = 'Cloudinary: ' . ($decoded['error']['message'] ?? 'upload recusado (HTTP ' . $httpCode . ')');

            return null;
        }

        if ((int) ($decoded['bytes'] ?? 0) <= 0 || empty($decoded['public_id'])) {
            $error = 'Cloudinary não confirmou o ficheiro (resposta incompleta).';

            return null;
        }

        return (string) $decoded['secure_url'];
    }

    /**
     * @return array{cloud: string, resource_type: string, delivery_type: string, version: ?string, public_id: string}|null
     */
    public static function parseDeliveryUrl(string $url): ?array
    {
        if (!preg_match(
            '#res\.cloudinary\.com/([^/]+)/(image|raw)/(upload|authenticated|private)(?:/s--[^/]+--/)?(?:/v(\d+))?/(.+?)(?:\?.*)?$#i',
            $url,
            $m
        )) {
            return null;
        }

        $publicId = ltrim((string) $m[5], '/');
        $publicId = preg_replace('/\.pdf$/i', '', $publicId);

        return [
            'cloud' => $m[1],
            'resource_type' => strtolower($m[2]),
            'delivery_type' => strtolower($m[3]),
            'version' => isset($m[4]) && $m[4] !== '' ? (string) $m[4] : null,
            'public_id' => $publicId,
        ];
    }

    public static function normalizeDeliveryUrl(string $secureUrl): string
    {
        $url = trim($secureUrl);
        $url = preg_replace('#/(image|raw)/(upload|authenticated|private)/s--[^/]+--/#i', '/$1/$2/', $url);
        if (preg_match('#res\.cloudinary\.com/.+/(image|raw)/upload/#i', $url) && !preg_match('/\.pdf(\?|#|$)/i', $url)) {
            $url = preg_replace('/(\?[^#]*)?$/', '.pdf$1', $url);
        }

        return $url;
    }

    /**
     * Obtém bytes do PDF: CDN público (como imagens) → fallback API com Basic Auth (PDFs antigos/privados).
     */
    public static function fetchUrlBytes(string $secureUrl, ?string &$error = null): ?string
    {
        if (!function_exists('curl_init')) {
            $error = 'cURL não disponível';

            return null;
        }

        $url = self::normalizeDeliveryUrl($secureUrl);
        $body = self::httpGet($url, false, $code, $curlErr);
        if (self::isPdfBody($body)) {
            return $body;
        }

        $parsed = self::parseDeliveryUrl($secureUrl) ?? self::parseDeliveryUrl($url);
        $cfg = self::loadConfig();
        if ($parsed && !empty($cfg['api_key']) && !empty($cfg['api_secret'])) {
            $apiBody = self::fetchViaApiDownload($parsed, $cfg, $code, $curlErr);
            if (self::isPdfBody($apiBody)) {
                return $apiBody;
            }
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['error']['message'])) {
                $error = (string) $decoded['error']['message'];
            } else {
                $error = 'Cloudinary HTTP ' . $code . ($curlErr ? ' — ' . $curlErr : '');
            }
        } else {
            $error = $curlErr ?: ('Cloudinary HTTP ' . $code);
        }

        return null;
    }

    private static function isPdfBody(?string $body): bool
    {
        return $body !== null && strlen($body) > 50 && strncmp($body, '%PDF', 4) === 0;
    }

    private static function httpGet(string $url, bool $useApiAuth, ?int &$httpCode, ?string &$curlErr): ?string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($useApiAuth) {
            $cfg = self::loadConfig();
            $opts[CURLOPT_USERPWD] = $cfg['api_key'] . ':' . $cfg['api_secret'];
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch) ?: null;
        self::curlClose($ch);
        if ($body === false || $httpCode < 200 || $httpCode >= 400) {
            return is_string($body) ? $body : null;
        }

        return $body;
    }

    /**
     * @param array{cloud: string, resource_type: string, delivery_type: string, version: ?string, public_id: string} $parsed
     */
    private static function fetchViaApiDownload(array $parsed, array $cfg, ?int &$httpCode, ?string &$curlErr): ?string
    {
        $cloud = $cfg['cloud_name'] ?: $parsed['cloud'];
        $q = [
            'public_id' => $parsed['public_id'],
            'format' => 'pdf',
        ];
        $types = array_values(array_unique([$parsed['delivery_type'], 'upload', 'authenticated']));
        foreach ($types as $type) {
            $q['type'] = $type;
            if (!empty($parsed['version'])) {
                $q['version'] = $parsed['version'];
            } else {
                unset($q['version']);
            }
            $url = 'https://api.cloudinary.com/v1_1/' . $cloud . '/'
                . $parsed['resource_type'] . '/download?' . http_build_query($q);
            $body = self::httpGet($url, true, $httpCode, $curlErr);
            if (self::isPdfBody($body)) {
                return $body;
            }
        }

        return null;
    }
}
