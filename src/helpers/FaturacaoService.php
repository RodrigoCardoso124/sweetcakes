<?php

require_once __DIR__ . '/IvaHelper.php';
require_once __DIR__ . '/LucroCalculator.php';

/**
 * Faturação emitida/recebida e resumo de IVA (informativo — validar com contabilista).
 */
class FaturacaoService
{
    public const TAXAS_IVA = [23.0, 13.0, 6.0, 0.0];

    public static function tabelasOk(PDO $db): bool
    {
        return self::tableExists($db, 'faturas_emitidas')
            && self::tableExists($db, 'fatura_linhas')
            && self::tableExists($db, 'faturas_recebidas');
    }

    public static function listarEmitidas(PDO $db, string $de, string $ate, ?string $estado = null): array
    {
        $sql = 'SELECT fatura_id, serie, numero, encomenda_id, cliente_nome, cliente_nif,
                       data_emissao, estado, total_base, total_iva, total_com_iva, criado_em
                FROM faturas_emitidas
                WHERE data_emissao BETWEEN ? AND ?';
        $params = [$de, $ate];
        if ($estado !== null && $estado !== '') {
            $sql .= ' AND estado = ?';
            $params[] = $estado;
        }
        $sql .= ' ORDER BY data_emissao DESC, numero DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function obterEmitida(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM faturas_emitidas WHERE fatura_id = ? LIMIT 1');
        $stmt->execute([$id]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return null;
        }
        $stmtL = $db->prepare('SELECT * FROM fatura_linhas WHERE fatura_id = ? ORDER BY linha_id');
        $stmtL->execute([$id]);
        $f['linhas'] = $stmtL->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $f['empresa'] = self::getConfig($db);

        return $f;
    }

    public static function faturaPorEncomenda(PDO $db, int $encomendaId): ?array
    {
        $stmt = $db->prepare(
            'SELECT fatura_id, serie, numero, estado, data_emissao, total_com_iva
             FROM faturas_emitidas WHERE encomenda_id = ? LIMIT 1'
        );
        $stmt->execute([$encomendaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function previewEncomenda(PDO $db, int $encomendaId, float $taxaPadrao = IvaHelper::TAXA_PADRAO): array
    {
        $stmt = $db->prepare('SELECT * FROM encomendas WHERE encomenda_id = ? LIMIT 1');
        $stmt->execute([$encomendaId]);
        $enc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enc) {
            return ['error' => 'Encomenda não encontrada'];
        }
        if (strtolower((string) ($enc['estado'] ?? '')) !== 'entregue') {
            return ['error' => 'Só pode faturar encomendas entregues'];
        }
        if (self::faturaPorEncomenda($db, $encomendaId)) {
            return ['error' => 'Esta encomenda já tem fatura emitida'];
        }

        $cliente = self::dadosCliente($db, (int) $enc['cliente_id'], $enc);
        $linhas = self::linhasFromEncomenda($db, $encomendaId, $taxaPadrao);
        $desconto = (float) ($enc['desconto'] ?? 0);
        if ($desconto > 0) {
            $linhas[] = self::linhaDesconto($desconto, $taxaPadrao);
        }
        $totais = IvaHelper::totaisLinhas($linhas);

        return [
            'encomenda_id' => $encomendaId,
            'cliente' => $cliente,
            'linhas' => $linhas,
            'totais' => $totais,
            'encomenda_total' => (float) ($enc['total'] ?? 0),
            'avisos' => self::avisosTotais($totais['total_com_iva'], (float) ($enc['total'] ?? 0)),
            'precos_com_iva' => true,
            'quer_fatura_contribuinte' => self::encomendaQuerFatura($enc),
            'fatura_nif' => self::encomendaFaturaNif($enc),
        ];
    }

    public static function emitir(PDO $db, array $data): array
    {
        if (!self::tabelasOk($db)) {
            return ['error' => 'Execute a migração 009: /api/migrate_009_faturacao.php', 'code' => 503];
        }

        $taxaPadrao = self::taxaFromInput($data['taxa_iva_pct'] ?? null);
        $encomendaId = isset($data['encomenda_id']) ? (int) $data['encomenda_id'] : null;
        $serie = trim((string) ($data['serie'] ?? 'FT')) ?: 'FT';
        $dataEmissao = LucroCalculator::parseData($data['data_emissao'] ?? null, date('Y-m-d'));
        $notas = isset($data['notas']) ? trim((string) $data['notas']) : null;

        if ($encomendaId > 0) {
            $preview = self::previewEncomenda($db, $encomendaId, $taxaPadrao);
            if (!empty($preview['error'])) {
                return ['error' => $preview['error'], 'code' => 400];
            }
            $cliente = $preview['cliente'];
            $linhas = $preview['linhas'];
        } else {
            $cliente = [
                'nome' => trim((string) ($data['cliente_nome'] ?? '')),
                'nif' => self::normalizarNif($data['cliente_nif'] ?? null),
                'morada' => trim((string) ($data['cliente_morada'] ?? '')) ?: null,
                'email' => trim((string) ($data['cliente_email'] ?? '')) ?: null,
            ];
            if ($cliente['nome'] === '') {
                return ['error' => 'cliente_nome é obrigatório', 'code' => 400];
            }
            $linhas = self::parseLinhasManuais($data['linhas'] ?? [], $taxaPadrao);
            if (!$linhas) {
                return ['error' => 'linhas da fatura são obrigatórias', 'code' => 400];
            }
            $encomendaId = null;
        }

        $totais = IvaHelper::totaisLinhas($linhas);
        if ($totais['total_com_iva'] <= 0) {
            return ['error' => 'Total da fatura deve ser > 0', 'code' => 400];
        }

        try {
            $db->beginTransaction();
            $numero = self::reservarNumeroSerie($db, $serie);
            $stmt = $db->prepare(
                'INSERT INTO faturas_emitidas
                (serie, numero, encomenda_id, cliente_nome, cliente_nif, cliente_morada, cliente_email,
                 data_emissao, estado, total_base, total_iva, total_com_iva, notas)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $serie,
                $numero,
                $encomendaId,
                $cliente['nome'],
                $cliente['nif'],
                $cliente['morada'],
                $cliente['email'],
                $dataEmissao,
                'emitida',
                $totais['total_base'],
                $totais['total_iva'],
                $totais['total_com_iva'],
                $notas,
            ]);
            $faturaId = (int) $db->lastInsertId();
            self::inserirLinhas($db, $faturaId, $linhas);
            $db->commit();

            return [
                'message' => 'Fatura emitida',
                'fatura_id' => $faturaId,
                'documento' => $serie . ' ' . $numero . '/' . date('Y', strtotime($dataEmissao)),
                'serie' => $serie,
                'numero' => $numero,
                'totais' => $totais,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (strpos($e->getMessage(), 'uk_encomenda') !== false) {
                return ['error' => 'Encomenda já faturada', 'code' => 409];
            }

            return ['error' => $e->getMessage(), 'code' => 500];
        }
    }

    public static function anular(PDO $db, int $faturaId): array
    {
        $stmt = $db->prepare('SELECT estado FROM faturas_emitidas WHERE fatura_id = ? LIMIT 1');
        $stmt->execute([$faturaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['error' => 'Fatura não encontrada', 'code' => 404];
        }
        if ($row['estado'] === 'anulada') {
            return ['error' => 'Fatura já anulada', 'code' => 400];
        }
        $upd = $db->prepare('UPDATE faturas_emitidas SET estado = ? WHERE fatura_id = ?');
        $upd->execute(['anulada', $faturaId]);

        return ['message' => 'Fatura anulada', 'fatura_id' => $faturaId];
    }

    public static function listarRecebidas(PDO $db, string $de, string $ate): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM faturas_recebidas
             WHERE data_documento BETWEEN ? AND ?
             ORDER BY data_documento DESC, recebida_id DESC'
        );
        $stmt->execute([$de, $ate]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function criarRecebida(PDO $db, array $data): array
    {
        if (!self::tableExists($db, 'faturas_recebidas')) {
            return ['error' => 'Execute a migração 009', 'code' => 503];
        }
        $tipo = trim((string) ($data['tipo'] ?? 'outro'));
        if (!in_array($tipo, ['fornecedor', 'despesa', 'outro'], true)) {
            return ['error' => 'tipo inválido', 'code' => 400];
        }
        $nome = trim((string) ($data['entidade_nome'] ?? ''));
        if ($nome === '') {
            return ['error' => 'entidade_nome é obrigatório', 'code' => 400];
        }
        $dataDoc = LucroCalculator::parseData($data['data_documento'] ?? null, date('Y-m-d'));
        $taxa = self::taxaFromInput($data['taxa_iva_pct'] ?? null);
        $modo = trim((string) ($data['modo_valor'] ?? 'com_iva'));
        $valorInput = (float) ($data['valor'] ?? $data['total_com_iva'] ?? 0);
        if ($valorInput <= 0) {
            return ['error' => 'valor deve ser > 0', 'code' => 400];
        }
        if ($modo === 'sem_iva' || $modo === 'base') {
            $split = IvaHelper::linhaFromPrecoSemIva(1, $valorInput, $taxa);
            $base = $split['base_linha'];
            $iva = $split['iva_linha'];
            $total = $split['total_linha'];
        } else {
            $split = IvaHelper::splitTotalComIva($valorInput, $taxa);
            $base = $split['base'];
            $iva = $split['iva'];
            $total = $split['total'];
        }

        $stmt = $db->prepare(
            'INSERT INTO faturas_recebidas
            (tipo, pedido_id, despesa_id, numero, data_documento, entidade_nome, entidade_nif,
             total_base, taxa_iva_pct, total_iva, total_com_iva, notas)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $tipo,
            isset($data['pedido_id']) ? (int) $data['pedido_id'] : null,
            isset($data['despesa_id']) ? (int) $data['despesa_id'] : null,
            trim((string) ($data['numero'] ?? '')) ?: null,
            $dataDoc,
            $nome,
            self::normalizarNif($data['entidade_nif'] ?? null),
            $base,
            $taxa,
            $iva,
            $total,
            isset($data['notas']) ? trim((string) $data['notas']) : null,
        ]);

        return [
            'message' => 'Documento registado',
            'recebida_id' => (int) $db->lastInsertId(),
            'total_base' => $base,
            'total_iva' => $iva,
            'total_com_iva' => $total,
        ];
    }

    public static function apagarRecebida(PDO $db, int $id): bool
    {
        $stmt = $db->prepare('DELETE FROM faturas_recebidas WHERE recebida_id = ?');

        return $stmt->execute([$id]);
    }

    public static function resumoIva(PDO $db, string $de, string $ate): array
    {
        $emitido = ['base' => 0.0, 'iva' => 0.0, 'total' => 0.0, 'por_taxa' => []];
        $recebido = ['base' => 0.0, 'iva' => 0.0, 'total' => 0.0, 'por_taxa' => []];

        if (self::tableExists($db, 'faturas_emitidas')) {
            $stmt = $db->prepare(
                "SELECT fl.taxa_iva_pct,
                        SUM(fl.base_linha) AS base,
                        SUM(fl.iva_linha) AS iva
                 FROM fatura_linhas fl
                 INNER JOIN faturas_emitidas fe ON fe.fatura_id = fl.fatura_id
                 WHERE fe.estado = 'emitida' AND fe.data_emissao BETWEEN ? AND ?
                 GROUP BY fl.taxa_iva_pct"
            );
            $stmt->execute([$de, $ate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $taxa = (float) $r['taxa_iva_pct'];
                $base = round((float) $r['base'], 2);
                $iva = round((float) $r['iva'], 2);
                $emitido['por_taxa'][(string) $taxa] = ['base' => $base, 'iva' => $iva];
                $emitido['base'] += $base;
                $emitido['iva'] += $iva;
            }
            $emitido['base'] = round($emitido['base'], 2);
            $emitido['iva'] = round($emitido['iva'], 2);
            $emitido['total'] = round($emitido['base'] + $emitido['iva'], 2);
        }

        if (self::tableExists($db, 'faturas_recebidas')) {
            $stmt = $db->prepare(
                'SELECT taxa_iva_pct,
                        SUM(total_base) AS base,
                        SUM(total_iva) AS iva
                 FROM faturas_recebidas
                 WHERE data_documento BETWEEN ? AND ?
                 GROUP BY taxa_iva_pct'
            );
            $stmt->execute([$de, $ate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $taxa = (float) $r['taxa_iva_pct'];
                $base = round((float) $r['base'], 2);
                $iva = round((float) $r['iva'], 2);
                $recebido['por_taxa'][(string) $taxa] = ['base' => $base, 'iva' => $iva];
                $recebido['base'] += $base;
                $recebido['iva'] += $iva;
            }
            $recebido['base'] = round($recebido['base'], 2);
            $recebido['iva'] = round($recebido['iva'], 2);
            $recebido['total'] = round($recebido['base'] + $recebido['iva'], 2);
        }

        $ivaLiquidar = round($emitido['iva'] - $recebido['iva'], 2);

        return [
            'periodo' => ['de' => $de, 'ate' => $ate],
            'iva_debito' => $emitido,
            'iva_credito' => $recebido,
            'iva_liquidar_estimado' => $ivaLiquidar,
            'nota' => 'Resumo informativo. A entrega à AT (e-Fatura/SAF-T) requer certificação de software — use o exportação CSV para o contabilista.',
            'empresa' => self::getConfig($db),
        ];
    }

    public static function exportAtCsv(PDO $db, string $de, string $ate): string
    {
        $cfg = self::getConfig($db);
        $nifEmpresa = $cfg['nif'] ?? '';
        $lines = [];
        $lines[] = 'Sweet Cakes — Exportação IVA (informativo);Período;' . $de . ' a ' . $ate;
        $lines[] = 'NIF emitente;' . $nifEmpresa;
        $lines[] = '';
        $lines[] = '=== Faturas emitidas (IVA debitado) ===';
        $lines[] = 'Serie;Numero;Data;Estado;Cliente;NIF;Base;IVA;Total;Encomenda';

        if (self::tableExists($db, 'faturas_emitidas')) {
            $stmt = $db->prepare(
                'SELECT serie, numero, data_emissao, estado, cliente_nome, cliente_nif,
                        total_base, total_iva, total_com_iva, encomenda_id
                 FROM faturas_emitidas
                 WHERE data_emissao BETWEEN ? AND ?
                 ORDER BY data_emissao, numero'
            );
            $stmt->execute([$de, $ate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lines[] = implode(';', [
                    $r['serie'],
                    $r['numero'],
                    $r['data_emissao'],
                    $r['estado'],
                    str_replace(';', ',', (string) $r['cliente_nome']),
                    $r['cliente_nif'] ?? '',
                    $r['total_base'],
                    $r['total_iva'],
                    $r['total_com_iva'],
                    $r['encomenda_id'] ?? '',
                ]);
            }
        }

        $lines[] = '';
        $lines[] = '=== Faturas/documentos recebidos (IVA dedutível) ===';
        $lines[] = 'Tipo;Numero;Data;Entidade;NIF;Taxa%;Base;IVA;Total';

        if (self::tableExists($db, 'faturas_recebidas')) {
            $stmt = $db->prepare(
                'SELECT tipo, numero, data_documento, entidade_nome, entidade_nif,
                        taxa_iva_pct, total_base, total_iva, total_com_iva
                 FROM faturas_recebidas
                 WHERE data_documento BETWEEN ? AND ?
                 ORDER BY data_documento'
            );
            $stmt->execute([$de, $ate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lines[] = implode(';', [
                    $r['tipo'],
                    $r['numero'] ?? '',
                    $r['data_documento'],
                    str_replace(';', ',', (string) $r['entidade_nome']),
                    $r['entidade_nif'] ?? '',
                    $r['taxa_iva_pct'],
                    $r['total_base'],
                    $r['total_iva'],
                    $r['total_com_iva'],
                ]);
            }
        }

        $resumo = self::resumoIva($db, $de, $ate);
        $lines[] = '';
        $lines[] = '=== Resumo por taxa ===';
        $lines[] = 'Taxa%;Base debito;IVA debito;Base credito;IVA credito';
        $taxas = array_unique(array_merge(
            array_keys($resumo['iva_debito']['por_taxa']),
            array_keys($resumo['iva_credito']['por_taxa'])
        ));
        sort($taxas);
        foreach ($taxas as $t) {
            $d = $resumo['iva_debito']['por_taxa'][$t] ?? ['base' => 0, 'iva' => 0];
            $c = $resumo['iva_credito']['por_taxa'][$t] ?? ['base' => 0, 'iva' => 0];
            $lines[] = $t . ';' . $d['base'] . ';' . $d['iva'] . ';' . $c['base'] . ';' . $c['iva'];
        }
        $lines[] = '';
        $lines[] = 'IVA a liquidar (estimado);' . $resumo['iva_liquidar_estimado'];

        return implode("\n", $lines);
    }

    public static function getConfig(PDO $db): array
    {
        $defaults = [
            'nome' => 'Sweet Cakes',
            'nif' => '',
            'morada' => '',
            'email' => '',
            'taxa_iva_padrao' => (string) IvaHelper::TAXA_PADRAO,
        ];
        if (!self::tableExists($db, 'faturacao_config')) {
            return $defaults;
        }
        $stmt = $db->query('SELECT config_key, config_value FROM faturacao_config');
        $out = $defaults;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['config_key']] = $r['config_value'];
        }
        $out['taxa_iva_padrao'] = (string) self::taxaFromInput($out['taxa_iva_padrao'] ?? null);

        return $out;
    }

    public static function saveConfig(PDO $db, array $data): void
    {
        self::ensureConfigTable($db);
        $allowed = ['nome', 'nif', 'morada', 'email', 'taxa_iva_padrao'];
        $stmt = $db->prepare(
            'INSERT INTO faturacao_config (config_key, config_value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $val = trim((string) $data[$key]);
            if ($key === 'taxa_iva_padrao') {
                $val = (string) self::taxaFromInput($val);
            }
            if ($key === 'nif') {
                $val = self::normalizarNif($val) ?? '';
            }
            $stmt->execute([$key, $val]);
        }
    }

    public static function ensureConfigTable(PDO $db): void
    {
        if (self::tableExists($db, 'faturacao_config')) {
            return;
        }
        $db->exec(
            'CREATE TABLE faturacao_config (
                config_key VARCHAR(50) NOT NULL PRIMARY KEY,
                config_value VARCHAR(500) NULL
            )'
        );
        $ins = $db->prepare('INSERT IGNORE INTO faturacao_config (config_key, config_value) VALUES (?,?)');
        foreach (
            [
                ['nome', 'Sweet Cakes'],
                ['nif', ''],
                ['morada', ''],
                ['email', ''],
                ['taxa_iva_padrao', '23'],
            ] as $row
        ) {
            $ins->execute($row);
        }
    }

    private static function linhasFromEncomenda(PDO $db, int $encomendaId, float $taxaPadrao): array
    {
        $temPreco = LucroCalculator::columnExists($db, 'encomenda_detalhes', 'preco_unitario');
        $sql = 'SELECT ed.produto_id, ed.quantidade, ed.especifico, p.nome, p.preco AS preco_cat';
        if ($temPreco) {
            $sql .= ', ed.preco_unitario';
        }
        $sql .= ' FROM encomenda_detalhes ed
                  INNER JOIN produtos p ON p.produto_id = ed.produto_id
                  WHERE ed.encomenda_id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute([$encomendaId]);
        $linhas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qtd = max(0.0001, (float) ($r['quantidade'] ?? 1));
            $preco = ($temPreco && $r['preco_unitario'] !== null)
                ? (float) $r['preco_unitario']
                : (float) ($r['preco_cat'] ?? 0);
            $desc = trim((string) ($r['nome'] ?? 'Produto'));
            if (!empty($r['especifico'])) {
                $desc .= ' — ' . trim((string) $r['especifico']);
            }
            $calc = IvaHelper::linhaFromPrecoComIva($qtd, $preco, $taxaPadrao);
            $linhas[] = array_merge($calc, [
                'produto_id' => (int) $r['produto_id'],
                'descricao' => $desc,
            ]);
        }

        return $linhas;
    }

    private static function linhaDesconto(float $desconto, float $taxa): array
    {
        $calc = IvaHelper::linhaDescontoComIva($desconto, $taxa);

        return array_merge($calc, [
            'produto_id' => null,
            'descricao' => 'Desconto (promoção)',
        ]);
    }

    private static function parseLinhasManuais(array $raw, float $taxaPadrao): array
    {
        $linhas = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc = trim((string) ($item['descricao'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $qtd = max(0.0001, (float) ($item['quantidade'] ?? 1));
            $taxa = self::taxaFromInput($item['taxa_iva_pct'] ?? $taxaPadrao);
            $preco = (float) ($item['preco_unitario_com_iva'] ?? $item['preco'] ?? 0);
            $calc = IvaHelper::linhaFromPrecoComIva($qtd, $preco, $taxa);
            $linhas[] = array_merge($calc, [
                'produto_id' => isset($item['produto_id']) ? (int) $item['produto_id'] : null,
                'descricao' => $desc,
            ]);
        }

        return $linhas;
    }

    private static function inserirLinhas(PDO $db, int $faturaId, array $linhas): void
    {
        $stmt = $db->prepare(
            'INSERT INTO fatura_linhas
            (fatura_id, produto_id, descricao, quantidade, preco_unitario_sem_iva, taxa_iva_pct,
             base_linha, iva_linha, total_linha)
            VALUES (?,?,?,?,?,?,?,?,?)'
        );
        foreach ($linhas as $l) {
            $stmt->execute([
                $faturaId,
                $l['produto_id'] ?? null,
                $l['descricao'],
                $l['quantidade'],
                $l['preco_unitario_sem_iva'],
                $l['taxa_iva_pct'],
                $l['base_linha'],
                $l['iva_linha'],
                $l['total_linha'],
            ]);
        }
    }

    private static function reservarNumeroSerie(PDO $db, string $serie): int
    {
        $stmt = $db->prepare('SELECT proximo_numero FROM fatura_series WHERE codigo = ? AND activa = 1 FOR UPDATE');
        $stmt->execute([$serie]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $db->prepare('INSERT INTO fatura_series (codigo, descricao, proximo_numero, activa) VALUES (?, ?, 1, 1)')
                ->execute([$serie, $serie]);
            $numero = 1;
        } else {
            $numero = (int) $row['proximo_numero'];
        }
        $db->prepare('UPDATE fatura_series SET proximo_numero = ? WHERE codigo = ?')->execute([$numero + 1, $serie]);

        return $numero;
    }

    private static function dadosCliente(PDO $db, int $pessoaId, ?array $encomenda = null): array
    {
        $stmt = $db->prepare('SELECT nome, email, telemovel, morada' .
            (self::columnExists($db, 'pessoas', 'nif') ? ', nif' : '') .
            ' FROM pessoas WHERE pessoa_id = ? LIMIT 1');
        $stmt->execute([$pessoaId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $nif = self::normalizarNif($p['nif'] ?? null);
        if ($encomenda && self::encomendaQuerFatura($encomenda)) {
            $nifEnc = self::encomendaFaturaNif($encomenda);
            if ($nifEnc) {
                $nif = $nifEnc;
            }
        }

        return [
            'nome' => trim((string) ($p['nome'] ?? 'Cliente')) ?: 'Cliente',
            'nif' => $nif,
            'morada' => trim((string) ($p['morada'] ?? '')) ?: null,
            'email' => trim((string) ($p['email'] ?? '')) ?: null,
        ];
    }

    public static function encomendaQuerFatura(?array $encomenda): bool
    {
        if (!$encomenda) {
            return false;
        }

        return !empty($encomenda['quer_fatura_contribuinte'])
            || !empty($encomenda['fatura_com_contribuinte']);
    }

    public static function encomendaFaturaNif(?array $encomenda): ?string
    {
        if (!$encomenda) {
            return null;
        }

        return self::normalizarNif(
            $encomenda['fatura_nif'] ?? $encomenda['nif_faturacao'] ?? null
        );
    }

    /**
     * Valida e opcionalmente grava NIF na ficha do cliente.
     *
     * @return array{error?: string, nif?: ?string}
     */
    public static function resolverNifEncomenda(PDO $db, int $clienteId, array $data): array
    {
        $quer = !empty($data['quer_fatura_contribuinte'])
            || !empty($data['fatura_com_contribuinte']);
        if (!$quer) {
            return ['nif' => null];
        }

        $nif = self::normalizarNif($data['fatura_nif'] ?? $data['nif_faturacao'] ?? $data['nif'] ?? null);
        if (!$nif && self::columnExists($db, 'pessoas', 'nif')) {
            $stmt = $db->prepare('SELECT nif FROM pessoas WHERE pessoa_id = ? LIMIT 1');
            $stmt->execute([$clienteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $nif = self::normalizarNif($row['nif'] ?? null);
        }
        if (!$nif) {
            return ['error' => 'NIF é obrigatório para fatura com contribuinte'];
        }

        if (self::columnExists($db, 'pessoas', 'nif')) {
            $upd = $db->prepare('UPDATE pessoas SET nif = :nif WHERE pessoa_id = :id');
            $upd->execute([':nif' => $nif, ':id' => $clienteId]);
        }

        return ['nif' => $nif];
    }

    private static function avisosTotais(float $faturaTotal, float $encomendaTotal): array
    {
        $avisos = [];
        if ($encomendaTotal > 0 && abs($faturaTotal - $encomendaTotal) > 0.05) {
            $avisos[] = 'O total da fatura (€' . number_format($faturaTotal, 2) .
                ') difere ligeiramente do total da encomenda (€' . number_format($encomendaTotal, 2) . ').';
        }

        return $avisos;
    }

    private static function taxaFromInput($v): float
    {
        $t = (float) ($v ?? IvaHelper::TAXA_PADRAO);

        return max(0, min(100, $t));
    }

    public static function normalizarNif(?string $nif): ?string
    {
        if ($nif === null) {
            return null;
        }
        $n = preg_replace('/\s+/', '', trim($nif));

        return $n === '' ? null : strtoupper($n);
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute([':t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
