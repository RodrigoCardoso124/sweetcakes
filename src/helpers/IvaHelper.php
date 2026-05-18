<?php

/**
 * Cálculos de IVA (apoio informativo — confirmar com contabilista).
 */
class IvaHelper
{
    public const TAXA_PADRAO = 23.0;

    public static function splitTotalComIva(float $totalComIva, float $taxaPct): array
    {
        $totalComIva = max(0, round($totalComIva, 2));
        $taxaPct = max(0, min(100, (float) $taxaPct));
        if ($totalComIva <= 0) {
            return ['base' => 0.0, 'iva' => 0.0, 'total' => 0.0, 'taxa' => $taxaPct];
        }
        $base = round($totalComIva / (1 + $taxaPct / 100), 2);
        $iva = round($totalComIva - $base, 2);

        return ['base' => $base, 'iva' => $iva, 'total' => $totalComIva, 'taxa' => $taxaPct];
    }

    public static function linhaFromPrecoSemIva(float $qtd, float $precoSemIva, float $taxaPct): array
    {
        $qtd = max(0.0001, (float) $qtd);
        $precoSemIva = max(0, (float) $precoSemIva);
        $taxaPct = max(0, min(100, (float) $taxaPct));
        $base = round($qtd * $precoSemIva, 2);
        $iva = round($base * ($taxaPct / 100), 2);
        $total = round($base + $iva, 2);

        return [
            'quantidade' => $qtd,
            'preco_unitario_sem_iva' => round($precoSemIva, 4),
            'taxa_iva_pct' => $taxaPct,
            'base_linha' => $base,
            'iva_linha' => $iva,
            'total_linha' => $total,
        ];
    }

    public static function totaisLinhas(array $linhas): array
    {
        $base = 0.0;
        $iva = 0.0;
        foreach ($linhas as $l) {
            $base += (float) ($l['base_linha'] ?? 0);
            $iva += (float) ($l['iva_linha'] ?? 0);
        }
        $base = round($base, 2);
        $iva = round($iva, 2);

        return [
            'total_base' => $base,
            'total_iva' => $iva,
            'total_com_iva' => round($base + $iva, 2),
        ];
    }
}
