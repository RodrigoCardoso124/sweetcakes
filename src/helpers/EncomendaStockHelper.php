<?php

require_once __DIR__ . '/../models/Produto.php';

/**
 * Liga stock de produtos às linhas de encomenda (desconto ao reservar linha; reposição ao apagar ou cancelar).
 */
class EncomendaStockHelper
{
    public static function quantidadeParaInt($quantidade): int
    {
        $n = (float) $quantidade;
        if ($n <= 0 || !is_finite($n)) {
            return 0;
        }

        return (int) max(1, (int) round($n));
    }

    public static function fetchDetalhes(PDO $db, int $encomendaId): array
    {
        $stmt = $db->prepare(
            'SELECT detalhe_id, produto_id, quantidade FROM encomenda_detalhes WHERE encomenda_id = :eid'
        );
        $stmt->bindValue(':eid', $encomendaId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function encomendaEstaCancelada(PDO $db, int $encomendaId): bool
    {
        $stmt = $db->prepare('SELECT estado FROM encomendas WHERE encomenda_id = :id LIMIT 1');
        $stmt->bindValue(':id', $encomendaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && strtolower((string) ($row['estado'] ?? '')) === 'cancelada';
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function tentarDescontarStock(PDO $db, int $produtoId, int $qty): array
    {
        if ($qty <= 0) {
            return ['ok' => true];
        }
        $produto = new Produto($db);
        $produto->produto_id = $produtoId;
        $row = $produto->getById()->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Produto não encontrado'];
        }
        $atual = (int) ($row['stock_atual'] ?? 0);
        if ($atual < $qty) {
            return [
                'ok' => false,
                'message' => 'Stock insuficiente para este produto (disponível: '.$atual.', pedido: '.$qty.').',
            ];
        }
        if (!$produto->incrementStock($produtoId, -$qty)) {
            return ['ok' => false, 'message' => 'Erro ao actualizar stock do produto'];
        }

        return ['ok' => true];
    }

    public static function reporStock(PDO $db, int $produtoId, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }
        $produto = new Produto($db);
        $produto->incrementStock($produtoId, $qty);
    }

    public static function reporTodasLinhasEncomenda(PDO $db, int $encomendaId): void
    {
        foreach (self::fetchDetalhes($db, $encomendaId) as $ln) {
            $pid = (int) ($ln['produto_id'] ?? 0);
            $q = self::quantidadeParaInt($ln['quantidade'] ?? 0);
            self::reporStock($db, $pid, $q);
        }
    }

    public static function descontarTodasLinhasEncomenda(PDO $db, int $encomendaId): ?string
    {
        foreach (self::fetchDetalhes($db, $encomendaId) as $ln) {
            $pid = (int) ($ln['produto_id'] ?? 0);
            $q = self::quantidadeParaInt($ln['quantidade'] ?? 0);
            $r = self::tentarDescontarStock($db, $pid, $q);
            if (!$r['ok']) {
                return $r['message'] ?? 'Stock insuficiente';
            }
        }

        return null;
    }
}
