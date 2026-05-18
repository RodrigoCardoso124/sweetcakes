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

    /**
     * Preço de catálogo / encomenda já inclui IVA (preço final ao cliente).
     */
    public static function linhaFromPrecoComIva(float $qtd, float $precoUnitarioComIva, float $taxaPct): array
    {
        $qtd = max(0.0001, (float) $qtd);
        $precoUnitarioComIva = max(0, (float) $precoUnitarioComIva);
        $taxaPct = max(0, min(100, (float) $taxaPct));
        $totalLinha = round($qtd * $precoUnitarioComIva, 2);
        $split = self::splitTotalComIva($totalLinha, $taxaPct);
        $precoSemIvaUnit = $qtd > 0 ? round($split['base'] / $qtd, 4) : 0.0;

        return [
            'quantidade' => $qtd,
            'preco_unitario_com_iva' => round($precoUnitarioComIva, 4),
            'preco_unitario_sem_iva' => $precoSemIvaUnit,
            'taxa_iva_pct' => $taxaPct,
            'base_linha' => $split['base'],
            'iva_linha' => $split['iva'],
            'total_linha' => $split['total'],
        ];
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

    /** Linha de desconto quando o valor descontado já inclui IVA. */
    public static function linhaDescontoComIva(float $valorDescontoComIva, float $taxaPct): array
    {
        $valorDescontoComIva = round(max(0, $valorDescontoComIva), 2);
        if ($valorDescontoComIva <= 0) {
            return self::linhaFromPrecoComIva(1, 0, $taxaPct);
        }
        $split = self::splitTotalComIva($valorDescontoComIva, $taxaPct);

        return [
            'quantidade' => 1,
            'preco_unitario_com_iva' => round(-$valorDescontoComIva, 4),
            'preco_unitario_sem_iva' => round(-$split['base'], 4),
            'taxa_iva_pct' => $taxaPct,
            'base_linha' => round(-$split['base'], 2),
            'iva_linha' => round(-$split['iva'], 2),
            'total_linha' => round(-$split['total'], 2),
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
