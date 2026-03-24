<?php

class PasswordHelper
{
    private static function normalizeLegacyPlaintext(string $value): string
    {
        $value = trim($value);
        // Remove espaços Unicode e caracteres de controlo invisíveis comuns em copy/paste.
        $value = preg_replace('/[\p{Z}\p{Cc}\p{Cf}]+/u', '', $value) ?? $value;
        return $value;
    }

    /**
     * @return true|string true = OK; 'legacy_rehash' = login OK mas deve atualizar hash na BD
     */
    public static function verify(string $plain, string $stored)
    {
        $plain = trim((string) $plain);
        $stored = trim((string) $stored);
        $stored = trim($stored, "\"'");
        if ($stored === '') {
            return false;
        }
        $info = password_get_info($stored);
        if (($info['algo'] ?? 0) !== 0) {
            return password_verify($plain, $stored) ? true : false;
        }
        // Suporte legado: plaintext com migracao automatica para hash.
        if (hash_equals($stored, $plain)) {
            return 'legacy_rehash';
        }
        $plainNormalized = self::normalizeLegacyPlaintext($plain);
        $storedNormalized = self::normalizeLegacyPlaintext($stored);
        if ($plainNormalized !== '' && hash_equals($storedNormalized, $plainNormalized)) {
            return 'legacy_rehash';
        }
        return false;
    }

    public static function hash(string $plain): string
    {
        return password_hash(trim((string) $plain), PASSWORD_DEFAULT);
    }
}
