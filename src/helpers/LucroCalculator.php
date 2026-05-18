<?php



/**

 * Cálculos de custo, margem e lucro (preços sem IVA).

 */

class LucroCalculator

{

    /** Estados que contam como receita realizada. */

    public static function estadosReceitaRealizada(): array

    {

        return ['entregue'];

    }



    public static function parseData(?string $s, string $fallback): string

    {

        $s = trim((string) $s);

        if ($s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {

            return $s;

        }

        return $fallback;

    }



    public static function getPrecoIngrediente(PDO $db, int $ingredienteId, ?string $dataRef = null): float

    {

        $dataRef = $dataRef ?: date('Y-m-d');

        $stmt = $db->prepare(

            'SELECT preco_unitario FROM ingrediente_preco_historico

             WHERE ingrediente_id = :i AND data_vigencia <= :d

             ORDER BY data_vigencia DESC, historico_id DESC LIMIT 1'

        );

        $stmt->execute([':i' => $ingredienteId, ':d' => $dataRef]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['preco_unitario'] !== null) {

            return (float) $row['preco_unitario'];

        }

        $stmt2 = $db->prepare('SELECT preco_unitario FROM ingredientes WHERE ingrediente_id = :i LIMIT 1');

        $stmt2->execute([':i' => $ingredienteId]);

        $ing = $stmt2->fetch(PDO::FETCH_ASSOC);

        return $ing ? (float) ($ing['preco_unitario'] ?? 0) : 0.0;

    }



    public static function registarPrecoIngrediente(

        PDO $db,

        int $ingredienteId,

        float $precoUnitario,

        ?string $dataVigencia = null,

        ?int $pedidoId = null,

        ?string $notas = null

    ): void {

        $dataVigencia = $dataVigencia ?: date('Y-m-d');

        $precoUnitario = max(0, round($precoUnitario, 4));

        $stmt = $db->prepare(

            'INSERT INTO ingrediente_preco_historico (ingrediente_id, preco_unitario, data_vigencia, pedido_id, notas)

             VALUES (:i, :p, :d, :ped, :n)'

        );

        $stmt->bindValue(':i', $ingredienteId, PDO::PARAM_INT);

        $stmt->bindValue(':p', $precoUnitario);

        $stmt->bindValue(':d', $dataVigencia);

        if ($pedidoId) {

            $stmt->bindValue(':ped', $pedidoId, PDO::PARAM_INT);

        } else {

            $stmt->bindValue(':ped', null, PDO::PARAM_NULL);

        }

        if ($notas !== null && $notas !== '') {

            $stmt->bindValue(':n', $notas);

        } else {

            $stmt->bindValue(':n', null, PDO::PARAM_NULL);

        }

        $stmt->execute();



        $up = $db->prepare(

            'UPDATE ingredientes SET preco_unitario = :p, ultima_atualizacao_preco = NOW() WHERE ingrediente_id = :i'

        );

        $up->execute([':p' => $precoUnitario, ':i' => $ingredienteId]);

    }



    /**

     * Custo por 1 unidade de produto via receita activa.

     */

    public static function calcularCustoUnitarioProduto(PDO $db, int $produtoId, ?string $dataRef = null): ?float

    {

        $stmt = $db->prepare(

            'SELECT receita_id, rendimento FROM receitas WHERE produto_id = :p AND ativo = 1 ORDER BY receita_id DESC LIMIT 1'

        );

        $stmt->execute([':p' => $produtoId]);

        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {

            return null;

        }

        $rendimento = max(1, (int) ($rec['rendimento'] ?? 1));

        $linhas = $db->prepare(

            'SELECT ri.ingrediente_id, ri.quantidade

             FROM receita_ingredientes ri WHERE ri.receita_id = :r'

        );

        $linhas->execute([':r' => (int) $rec['receita_id']]);

        $rows = $linhas->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {

            return null;

        }

        $custoLote = 0.0;

        foreach ($rows as $ln) {

            $precoIng = self::getPrecoIngrediente($db, (int) $ln['ingrediente_id'], $dataRef);

            $custoLote += (float) $ln['quantidade'] * $precoIng;

        }

        return round($custoLote / $rendimento, 4);

    }



    public static function atualizarCustoEstimadoProduto(PDO $db, int $produtoId): void

    {

        $custo = self::calcularCustoUnitarioProduto($db, $produtoId);

        $stmt = $db->prepare('UPDATE produtos SET custo_estimado = :c WHERE produto_id = :p');

        if ($custo === null) {

            $stmt->bindValue(':c', null, PDO::PARAM_NULL);

        } else {

            $stmt->bindValue(':c', $custo);

        }

        $stmt->bindValue(':p', $produtoId, PDO::PARAM_INT);

        $stmt->execute();

    }



    public static function recalcularTodosCustosProdutos(PDO $db): void

    {

        $ids = $db->query('SELECT produto_id FROM produtos')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $pid) {

            self::atualizarCustoEstimadoProduto($db, (int) $pid);

        }

    }



    /** @return array{preco_unitario: float, custo_unitario_estimado: ?float} */

    public static function snapshotParaLinha(PDO $db, int $produtoId): array

    {

        $stmt = $db->prepare('SELECT preco FROM produtos WHERE produto_id = :p LIMIT 1');

        $stmt->execute([':p' => $produtoId]);

        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        $preco = $p ? (float) ($p['preco'] ?? 0) : 0.0;

        $custo = self::calcularCustoUnitarioProduto($db, $produtoId);

        return [

            'preco_unitario' => round($preco, 2),

            'custo_unitario_estimado' => $custo,

        ];

    }



    public static function columnExists(PDO $db, string $table, string $column): bool

    {

        $stmt = $db->prepare(

            'SELECT COUNT(*) FROM information_schema.COLUMNS

             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'

        );

        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() > 0;

    }



    public static function resumo(PDO $db, string $de, string $ate): array

    {

        $estados = self::estadosReceitaRealizada();

        $inEstados = implode(',', array_fill(0, count($estados), '?'));

        $temCriado = self::columnExists($db, 'encomendas', 'criado_em');



        $paramsEnc = $estados;

        $sqlEnc = "SELECT COALESCE(SUM(total), 0) AS s FROM encomendas WHERE estado IN ($inEstados)";

        if ($temCriado) {

            $sqlEnc .= ' AND DATE(criado_em) BETWEEN ? AND ?';

            $paramsEnc[] = $de;

            $paramsEnc[] = $ate;

        }

        $stmt = $db->prepare($sqlEnc);

        $stmt->execute($paramsEnc);

        $receitaEncomendas = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);



        $temDataVenda = self::columnExists($db, 'vendas', 'data_venda');

        if ($temDataVenda) {

            $stmtV = $db->prepare(

                'SELECT COALESCE(SUM(total), 0) AS s FROM vendas WHERE DATE(data_venda) BETWEEN ? AND ?'

            );

            $stmtV->execute([$de, $ate]);

        } else {

            $stmtV = $db->query('SELECT COALESCE(SUM(total), 0) AS s FROM vendas');

        }

        $receitaVendas = (float) ($stmtV->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

        $receitaTotal = $receitaEncomendas + $receitaVendas;



        $comprasMateriais = 0.0;

        if (self::columnExists($db, 'pedidos_ingrediente', 'valor_total')) {

            $sqlP = "SELECT COALESCE(SUM(valor_total), 0) AS s FROM pedidos_ingrediente WHERE estado = 'recebido'";

            $paramsP = [];

            if (self::columnExists($db, 'pedidos_ingrediente', 'data_recebido')) {

                $sqlP .= ' AND data_recebido BETWEEN ? AND ?';

                $paramsP = [$de, $ate];

            } elseif (self::columnExists($db, 'pedidos_ingrediente', 'atualizado_em')) {

                $sqlP .= ' AND DATE(atualizado_em) BETWEEN ? AND ?';

                $paramsP = [$de, $ate];

            }

            $stmtP = $db->prepare($sqlP);

            $stmtP->execute($paramsP);

            $comprasMateriais = (float) ($stmtP->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

        }



        $despesasGerais = 0.0;

        if (self::tableExists($db, 'despesas')) {

            $stmtD = $db->prepare(

                'SELECT COALESCE(SUM(valor), 0) AS s FROM despesas WHERE data_despesa BETWEEN ? AND ?'

            );

            $stmtD->execute([$de, $ate]);

            $despesasGerais = (float) ($stmtD->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

        }



        $margem = self::calcularMargemVendido($db, $de, $ate, $temCriado);



        $lucroBruto = $margem['receita_linhas'] - $margem['custo_linhas'];

        $lucroLiquido = $receitaTotal - $comprasMateriais - $despesasGerais;



        return [

            'periodo' => ['de' => $de, 'ate' => $ate],

            'receita' => [

                'encomendas_entregues' => round($receitaEncomendas, 2),

                'vendas_balcao' => round($receitaVendas, 2),

                'total' => round($receitaTotal, 2),

            ],

            'custos' => [

                'compras_materiais_recebidas' => round($comprasMateriais, 2),

                'despesas_gerais' => round($despesasGerais, 2),

                'custo_estimado_vendido' => round($margem['custo_linhas'], 2),

            ],

            'lucro' => [

                'bruto_margem_vendido' => round($lucroBruto, 2),

                'liquido_caixa' => round($lucroLiquido, 2),

            ],

            'margem_vendido' => $margem,

            'notas' => [

                'precos_sem_iva' => true,

                'encomendas_contam_estado' => $estados,

                'linhas_sem_custo' => $margem['linhas_sem_custo'],

                'linhas_sem_preco' => $margem['linhas_sem_preco'],

            ],

        ];

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



    private static function calcularMargemVendido(PDO $db, string $de, string $ate, bool $temCriado): array

    {

        $estados = self::estadosReceitaRealizada();

        $inEstados = implode(',', array_fill(0, count($estados), '?'));

        $params = $estados;

        $joinDate = '';

        if ($temCriado) {

            $joinDate = ' AND DATE(e.criado_em) BETWEEN ? AND ?';

            $params[] = $de;

            $params[] = $ate;

        }



        $temPrecoLinha = self::columnExists($db, 'encomenda_detalhes', 'preco_unitario');

        $temCustoLinha = self::columnExists($db, 'encomenda_detalhes', 'custo_unitario_estimado');



        $sql = "SELECT ed.produto_id, ed.quantidade, p.preco AS preco_cat";

        if ($temPrecoLinha) {

            $sql .= ', ed.preco_unitario';

        }

        if ($temCustoLinha) {

            $sql .= ', ed.custo_unitario_estimado';

        }

        $sql .= " FROM encomenda_detalhes ed

                  INNER JOIN encomendas e ON e.encomenda_id = ed.encomenda_id

                  INNER JOIN produtos p ON p.produto_id = ed.produto_id

                  WHERE e.estado IN ($inEstados) $joinDate";



        $stmt = $db->prepare($sql);

        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);



        $receitaLinhas = 0.0;

        $custoLinhas = 0.0;

        $semCusto = 0;

        $semPreco = 0;



        foreach ($rows as $r) {

            $q = max(0, (int) ($r['quantidade'] ?? 0));

            if ($q <= 0) {

                continue;

            }

            $precoU = null;

            if ($temPrecoLinha && $r['preco_unitario'] !== null && $r['preco_unitario'] !== '') {

                $precoU = (float) $r['preco_unitario'];

            } else {

                $precoU = (float) ($r['preco_cat'] ?? 0);

                $semPreco++;

            }

            $receitaLinhas += $precoU * $q;



            $custoU = null;

            if ($temCustoLinha && $r['custo_unitario_estimado'] !== null && $r['custo_unitario_estimado'] !== '') {

                $custoU = (float) $r['custo_unitario_estimado'];

            } else {

                $custoU = self::calcularCustoUnitarioProduto($db, (int) $r['produto_id']);

            }

            if ($custoU === null) {

                $semCusto++;

                continue;

            }

            $custoLinhas += $custoU * $q;

        }



        // Vendas balcão

        if (self::tableExists($db, 'produtos_vendidos')) {

            $sqlV = 'SELECT pv.quantidade, pv.preco_unitario, pv.produto_id

                     FROM produtos_vendidos pv

                     INNER JOIN vendas v ON v.venda_id = pv.venda_id';

            $paramsV = [];

            if (self::columnExists($db, 'vendas', 'data_venda')) {

                $sqlV .= ' WHERE DATE(v.data_venda) BETWEEN ? AND ?';

                $paramsV = [$de, $ate];

            }

            $stmtV = $db->prepare($sqlV);

            $stmtV->execute($paramsV);

            foreach ($stmtV->fetchAll(PDO::FETCH_ASSOC) as $pv) {

                $q = max(0, (int) ($pv['quantidade'] ?? 0));

                $receitaLinhas += (float) ($pv['preco_unitario'] ?? 0) * $q;

                $custoU = self::calcularCustoUnitarioProduto($db, (int) $pv['produto_id']);

                if ($custoU === null) {

                    $semCusto++;

                } else {

                    $custoLinhas += $custoU * $q;

                }

            }

        }



        return [

            'receita_linhas' => round($receitaLinhas, 2),

            'custo_linhas' => round($custoLinhas, 2),

            'linhas_sem_custo' => $semCusto,

            'linhas_sem_preco' => $semPreco,

        ];

    }



    public static function porProduto(PDO $db, string $de, string $ate): array

    {

        $temCriado = self::columnExists($db, 'encomendas', 'criado_em');

        $estados = self::estadosReceitaRealizada();

        $inEstados = implode(',', array_fill(0, count($estados), '?'));

        $params = $estados;

        $joinDate = '';

        if ($temCriado) {

            $joinDate = ' AND DATE(e.criado_em) BETWEEN ? AND ?';

            $params[] = $de;

            $params[] = $ate;

        }



        $agg = [];

        $add = function ($pid, $nome, $q, $receita, $custo) use (&$agg) {

            if (!isset($agg[$pid])) {

                $agg[$pid] = [

                    'produto_id' => $pid,

                    'nome' => $nome,

                    'quantidade_vendida' => 0,

                    'receita' => 0.0,

                    'custo_estimado' => 0.0,

                ];

            }

            $agg[$pid]['quantidade_vendida'] += $q;

            $agg[$pid]['receita'] += $receita;

            $agg[$pid]['custo_estimado'] += $custo;

        };



        $temPrecoLinha = self::columnExists($db, 'encomenda_detalhes', 'preco_unitario');

        $temCustoLinha = self::columnExists($db, 'encomenda_detalhes', 'custo_unitario_estimado');



        $sql = "SELECT ed.produto_id, p.nome, ed.quantidade, p.preco AS preco_cat";

        if ($temPrecoLinha) {

            $sql .= ', ed.preco_unitario';

        }

        if ($temCustoLinha) {

            $sql .= ', ed.custo_unitario_estimado';

        }

        $sql .= " FROM encomenda_detalhes ed

                  INNER JOIN encomendas e ON e.encomenda_id = ed.encomenda_id

                  INNER JOIN produtos p ON p.produto_id = ed.produto_id

                  WHERE e.estado IN ($inEstados) $joinDate";

        $stmt = $db->prepare($sql);

        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {

            $pid = (int) $r['produto_id'];

            $q = max(0, (int) ($r['quantidade'] ?? 0));

            $precoU = ($temPrecoLinha && $r['preco_unitario'] !== null)

                ? (float) $r['preco_unitario']

                : (float) ($r['preco_cat'] ?? 0);

            $custoU = ($temCustoLinha && $r['custo_unitario_estimado'] !== null)

                ? (float) $r['custo_unitario_estimado']

                : (self::calcularCustoUnitarioProduto($db, $pid) ?? 0);

            $add($pid, $r['nome'], $q, $precoU * $q, $custoU * $q);

        }



        $out = [];

        foreach ($agg as $row) {

            $rec = round($row['receita'], 2);

            $cus = round($row['custo_estimado'], 2);

            $lucro = $rec - $cus;

            $margemPct = $rec > 0 ? round(100 * $lucro / $rec, 1) : null;

            $stmtP = $db->prepare('SELECT preco, custo_estimado FROM produtos WHERE produto_id = ?');

            $stmtP->execute([$row['produto_id']]);

            $p = $stmtP->fetch(PDO::FETCH_ASSOC);

            $out[] = [

                'produto_id' => $row['produto_id'],

                'nome' => $row['nome'],

                'quantidade_vendida' => $row['quantidade_vendida'],

                'receita' => $rec,

                'custo_estimado' => $cus,

                'lucro_bruto' => round($lucro, 2),

                'margem_percent' => $margemPct,

                'preco_venda_actual' => $p ? (float) $p['preco'] : null,

                'custo_unitario_actual' => $p && $p['custo_estimado'] !== null ? (float) $p['custo_estimado'] : null,

            ];

        }

        usort($out, static fn ($a, $b) => ($b['receita'] <=> $a['receita']));

        return $out;

    }



    public static function fluxoCaixa(PDO $db, string $de, string $ate): array

    {

        $r = self::resumo($db, $de, $ate);

        return [

            'periodo' => $r['periodo'],

            'entradas' => $r['receita']['total'],

            'saidas' => $r['custos']['compras_materiais_recebidas'] + $r['custos']['despesas_gerais'],

            'saldo' => round(

                $r['receita']['total'] - $r['custos']['compras_materiais_recebidas'] - $r['custos']['despesas_gerais'],

                2

            ),

            'detalhe' => $r,

        ];

    }

}

