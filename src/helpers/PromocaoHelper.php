<?php
/**
 * Funcoes auxiliares para validar e calcular o desconto de uma promocao.
 */
class PromocaoHelper {
    public static function isActive(array $promo): bool {
        $now = new DateTimeImmutable();
        try {
            $ini = new DateTimeImmutable((string)($promo['data_inicio'] ?? ''));
            $fim = new DateTimeImmutable((string)($promo['data_fim'] ?? ''));
        } catch (Throwable $e) {
            return false;
        }
        return $now >= $ini && $now <= $fim;
    }

    public static function calcularDesconto(array $promo, float $subtotal, array $items = []): float {
        if ($subtotal <= 0) return 0.0;

        $min = (float)($promo['min_compra'] ?? 0);
        if ($subtotal < $min) return 0.0;

        $tipo = (string)($promo['tipo'] ?? '');
        switch ($tipo) {
            case 'percentual':
                $pct = (float)($promo['valor_percentual'] ?? 0);
                if ($pct <= 0 || $pct > 100) return 0.0;
                return round($subtotal * ($pct / 100), 2);

            case 'valor_fixo':
                $v = (float)($promo['valor_fixo'] ?? 0);
                if ($v <= 0) return 0.0;
                return round(min($v, $subtotal), 2);

            case 'oferta':
                return 0.0;

            case 'leve_pague':
                $leve = (int)($promo['leve_qtd'] ?? 0);
                $pague = (int)($promo['pague_qtd'] ?? 0);
                if ($leve <= 0 || $pague <= 0 || $pague >= $leve) return 0.0;
                $totalItems = 0;
                foreach ($items as $it) {
                    $totalItems += max(0, (int)($it['quantidade'] ?? 0));
                }
                if ($totalItems < $leve) return 0.0;
                $grupos = intdiv($totalItems, $leve);
                $gratis = $grupos * ($leve - $pague);
                $precoMedio = $subtotal / max(1, $totalItems);
                return round($gratis * $precoMedio, 2);

            default:
                return 0.0;
        }
    }

    public static function validarParaPessoa(array $promo, ?int $pessoaId, Promocao $repo): ?string {
        if (!self::isActive($promo)) return 'Promocao expirada ou ainda nao iniciada';
        if (!empty($promo['uso_unico'])) {
            if ($pessoaId === null) return 'Esta promocao requer login';
            $id = (int)($promo['promocao_id'] ?? 0);
            if ($id > 0 && $repo->pessoaJaUsou($id, $pessoaId)) {
                return 'Ja usou esta promocao';
            }
        }
        return null;
    }
}
