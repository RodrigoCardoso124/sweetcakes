<?php

require_once __DIR__ . '/FaturacaoService.php';
require_once __DIR__ . '/IvaHelper.php';
require_once __DIR__ . '/LucroCalculator.php';

/**
 * Liga compras (pedidos fornecedor, despesas) ao módulo de IVA dedutível.
 */
class FaturacaoIntegracaoService
{
    public static function sincronizarPedidoRecebido(PDO $db, int $pedidoId, ?float $taxaIva = null): array
    {
        if (!FaturacaoService::tabelasOk($db)) {
            return ['skipped' => true, 'hint' => 'migrate_009'];
        }
        if (!LucroCalculator::tableExists($db, 'pedidos_ingrediente')) {
            return ['skipped' => true];
        }
        $stmt = $db->prepare(
            'SELECT p.*, i.nome AS ingrediente_nome, f.empresa AS fornecedor_empresa
             FROM pedidos_ingrediente p
             LEFT JOIN ingredientes i ON i.ingrediente_id = p.ingrediente_id
             LEFT JOIN fornecedores f ON f.fornecedor_id = p.fornecedor_id
             WHERE p.pedido_id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || ($p['estado'] ?? '') !== 'recebido') {
            return ['skipped' => true];
        }

        $existente = $db->prepare(
            'SELECT recebida_id FROM faturas_recebidas WHERE pedido_id = ? LIMIT 1'
        );
        $existente->execute([$pedidoId]);
        $row = $existente->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['recebida_id' => (int) $row['recebida_id'], 'existente' => true];
        }

        $nome = trim((string) ($p['fornecedor_empresa'] ?? ''));
        if ($nome === '') {
            $nome = 'Fornecedor — ' . trim((string) ($p['ingrediente_nome'] ?? 'Materiais'));
        }
        $valor = (float) ($p['valor_total'] ?? 0);
        if ($valor <= 0) {
            return ['error' => 'Pedido sem valor_total'];
        }

        $taxa = $taxaIva ?? (float) (FaturacaoService::getConfig($db)['taxa_iva_padrao'] ?? IvaHelper::TAXA_PADRAO);

        return FaturacaoService::criarRecebida($db, [
            'tipo' => 'fornecedor',
            'pedido_id' => $pedidoId,
            'entidade_nome' => $nome,
            'entidade_nif' => null,
            'numero' => $p['num_fatura'] ?? null,
            'data_documento' => $p['data_recebido'] ?? date('Y-m-d'),
            'valor' => $valor,
            'taxa_iva_pct' => $taxa,
            'modo_valor' => 'com_iva',
            'notas' => 'Auto: pedido fornecedor #' . $pedidoId,
        ]);
    }

    public static function sincronizarDespesa(PDO $db, int $despesaId, ?float $taxaIva = null): array
    {
        if (!LucroCalculator::tableExists($db, 'despesas')) {
            return ['skipped' => true];
        }
        $stmt = $db->prepare('SELECT * FROM despesas WHERE despesa_id = ? LIMIT 1');
        $stmt->execute([$despesaId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return ['error' => 'Despesa não encontrada'];
        }

        $chk = $db->prepare('SELECT recebida_id FROM faturas_recebidas WHERE despesa_id = ? LIMIT 1');
        $chk->execute([$despesaId]);
        $ex = $chk->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            return ['recebida_id' => (int) $ex['recebida_id'], 'existente' => true];
        }

        $taxa = $taxaIva ?? (float) ($d['taxa_iva_pct'] ?? FaturacaoService::getConfig($db)['taxa_iva_padrao'] ?? IvaHelper::TAXA_PADRAO);
        $total = (float) ($d['valor'] ?? 0);
        if ($total <= 0) {
            return ['error' => 'Despesa sem valor'];
        }

        $modo = 'com_iva';
        if (!empty($d['total_base']) && (float) $d['total_base'] > 0 && abs($total - (float) $d['total_base']) < 0.02) {
            $modo = 'sem_iva';
            $total = (float) $d['total_base'];
        }

        return FaturacaoService::criarRecebida($db, [
            'tipo' => 'despesa',
            'despesa_id' => $despesaId,
            'entidade_nome' => trim((string) ($d['descricao'] ?? 'Despesa')) ?: 'Despesa #' . $despesaId,
            'data_documento' => $d['data_despesa'] ?? date('Y-m-d'),
            'valor' => $total,
            'taxa_iva_pct' => $taxa,
            'modo_valor' => $modo,
            'notas' => 'Auto: despesa #' . $despesaId,
        ]);
    }
}
