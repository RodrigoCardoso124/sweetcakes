<?php

/**
 * Geração e validação de NIF português (9 dígitos, dígito de controlo).
 */
class NifHelper
{
    public static function digitoControlo(string $base8): int
    {
        if (!preg_match('/^\d{8}$/', $base8)) {
            throw new InvalidArgumentException('Base NIF deve ter 8 dígitos');
        }
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $base8[$i] * (9 - $i);
        }
        $resto = $sum % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    }

    public static function gerar(int $seed, int $prefixo = 2): string
    {
        $prefixo = max(1, min(9, $prefixo));
        $corpo = $prefixo . str_pad((string) abs($seed % 10000000), 7, '0', STR_PAD_LEFT);
        $check = self::digitoControlo($corpo);

        return $corpo . $check;
    }

    public static function valido(?string $nif): bool
    {
        if ($nif === null) {
            return false;
        }
        $n = preg_replace('/\s+/', '', trim($nif));
        if (!preg_match('/^\d{9}$/', $n)) {
            return false;
        }
        $base = substr($n, 0, 8);
        $check = (int) substr($n, 8, 1);

        return self::digitoControlo($base) === $check;
    }

    /**
     * Gera NIF único face a um conjunto de NIFs já usados.
     *
     * @param array<string, true> $usados chaves = NIF normalizado
     */
    public static function gerarUnico(int $seed, array &$usados, int $prefixo = 2): string
    {
        for ($tentativa = 0; $tentativa < 500; $tentativa++) {
            $candidato = self::gerar($seed + $tentativa * 100000, $prefixo);
            if (!isset($usados[$candidato])) {
                $usados[$candidato] = true;

                return $candidato;
            }
        }
        throw new RuntimeException('Não foi possível gerar NIF único para seed ' . $seed);
    }
}
