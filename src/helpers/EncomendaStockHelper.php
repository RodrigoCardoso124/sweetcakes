<?php

require_once __DIR__ . '/../models/Produto.php';

/**
 * Stock de produtos nas encomendas:
 * - Pendente: linhas sem desconto (cliente ainda não confirmado pela loja).
 * - Aceite / em preparação / pronta / entregue: stock descontado.
 * - Cancelada ou volta a pendente: repõe o que tinha sido descontado.
 */
class EncomendaStockHelper
{
    /** Estados em que o stock da encomenda já está comprometido. */
    public static function estadoComprometeStock(string $estado): bool
    {
        $e = strtolower(trim($estado));

        return in_array($e, ['aceite', 'em_preparacao', 'pronta', 'entregue'], true);
    }

    public static function fetchEstadoEncomenda(PDO $db, int $encomendaId): string
    {
        $stmt = $db->prepare('SELECT estado FROM encomendas WHERE encomenda_id = :id LIMIT 1');
        $stmt->bindValue(':id', $encomendaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return strtolower(trim((string) ($row['estado'] ?? '')));
    }

    /**
     * Ao mudar o estado da encomenda: desconta linhas ao aceitar; repõe ao cancelar ou reverter para pendente.
     *
     * @return string|null mensagem de erro (ex. stock insuficiente) ou null se OK
     */
    public static function aplicarTransicaoStockEstado(
        PDO $db,
        int $encomendaId,
        string $estadoAntigo,
        string $estadoNovo
    ): ?string {
        $tinha = self::estadoComprometeStock($estadoAntigo);
        $passa = self::estadoComprometeStock($estadoNovo);
        if (!$tinha && $passa) {
            return self::descontarTodasLinhasEncomenda($db, $encomendaId);
        }
        if ($tinha && !$passa) {
            self::reporTodasLinhasEncomenda($db, $encomendaId);
        }

        return null;
    }

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
