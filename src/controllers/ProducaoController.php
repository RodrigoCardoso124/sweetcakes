<?php

require_once __DIR__ . '/../models/Produto.php';
require_once __DIR__ . '/../models/Receita.php';
require_once __DIR__ . '/../models/Ingredientes.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/stock_alert_mail.php';

class ProducaoController
{
    private $db;
    private $produto;
    private $receita;
    private $ingrediente;

    public function __construct($db)
    {
        $this->db = $db;
        $this->produto = new Produto($db);
        $this->receita = new Receita($db);
        $this->ingrediente = new Ingredientes($db);
    }

    private function requireFuncionario(): bool
    {
        if (!Auth::isFuncionario()) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas funcionários acedem à produção.']);

            return false;
        }

        return true;
    }

    /**
     * GET /producao — resumo para o painel de produção.
     */
    public function index()
    {
        if (!$this->requireFuncionario()) {
            return;
        }

        $stmt = $this->produto->getAll();
        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alertasProduto = [];
        foreach ($produtos as $p) {
            $sa = (int) ($p['stock_atual'] ?? 0);
            $sm = (int) ($p['stock_minimo'] ?? 0);
            if ($sm > 0 && $sa <= $sm) {
                $alertasProduto[] = [
                    'produto_id' => (int) $p['produto_id'],
                    'nome' => $p['nome'],
                    'stock_atual' => $sa,
                    'stock_minimo' => $sm,
                ];
            }
        }

        $alertasIngrediente = [];
        if (Auth::isElevatedAdmin($this->db)) {
            $stmtIng = $this->ingrediente->getAll();
            $ings = $stmtIng->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ings as $i) {
                $qa = (float) ($i['quantidade_atual'] ?? 0);
                $qm = (float) ($i['quantidade_minima'] ?? 0);
                if ($qm > 0 && $qa <= $qm) {
                    $alertasIngrediente[] = [
                        'ingrediente_id' => (int) $i['ingrediente_id'],
                        'nome' => $i['nome'],
                        'quantidade_atual' => $qa,
                        'quantidade_minima' => $qm,
                        'unidade' => $i['unidade'] ?? '',
                    ];
                }
            }
        }

        $receitas = $this->receita->listActive();
        foreach ($receitas as &$r) {
            $r['ingredientes'] = $this->receita->getLinhas((int) $r['receita_id']);
        }

        echo json_encode([
            'produtos' => $produtos,
            'alertas_produto' => $alertasProduto,
            'alertas_ingrediente' => $alertasIngrediente,
            'receitas' => $receitas,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function show($id)
    {
        $this->index();
    }

    /**
     * POST /producao — body: { "action": "incrementar_produto", "produto_id", "quantidade" }
     * ou { "action": "executar_receita", "receita_id", "vezes": 1 }
     */
    public function store($data)
    {
        if (!$this->requireFuncionario()) {
            return;
        }
        if (!is_array($data)) {
            $data = [];
        }
        $action = trim((string) ($data['action'] ?? ''));
        if ($action === 'incrementar_produto') {
            if (!Auth::isElevatedAdmin($this->db)) {
                http_response_code(403);
                echo json_encode(['message' => 'Só administradores podem alterar o stock de produtos manualmente. Usa «Executar receita» para produção.']);

                return;
            }
            $this->incrementarProduto($data);
            return;
        }
        if ($action === 'executar_receita') {
            $this->executarReceita($data);
            return;
        }
        http_response_code(400);
        echo json_encode(['message' => 'action inválida (incrementar_produto | executar_receita)']);
    }

    public function update($id, $data)
    {
        http_response_code(405);
        echo json_encode(['message' => 'Método não permitido']);
    }

    public function destroy($id)
    {
        http_response_code(405);
        echo json_encode(['message' => 'Método não permitido']);
    }

    private function incrementarProduto(array $data): void
    {
        $pid = (int) ($data['produto_id'] ?? 0);
        $q = (int) ($data['quantidade'] ?? 0);
        if ($pid <= 0 || $q <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'produto_id e quantidade (>0) obrigatórios']);
            return;
        }
        $this->produto->produto_id = $pid;
        $stmt = $this->produto->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['message' => 'Produto não encontrado']);
            return;
        }
        try {
            $this->db->beginTransaction();
            $this->produto->incrementStock($pid, $q);
            $this->logProducao('manual_produto', null, $pid, $q, null);
            $this->db->commit();
            echo json_encode(['message' => 'Stock atualizado', 'produto_id' => $pid, 'quantidade' => $q]);
        } catch (Throwable $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar stock', 'error_detail' => $e->getMessage()]);
        }
    }

    private function executarReceita(array $data): void
    {
        $rid = (int) ($data['receita_id'] ?? 0);
        $vezes = max(1, (int) ($data['vezes'] ?? 1));
        if ($vezes > 500) {
            http_response_code(400);
            echo json_encode(['message' => 'Número de execuções demasiado alto']);
            return;
        }
        if ($rid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'receita_id obrigatório']);
            return;
        }
        $rec = $this->receita->getById($rid);
        if (!$rec || (int) ($rec['ativo'] ?? 0) !== 1) {
            http_response_code(404);
            echo json_encode(['message' => 'Receita não encontrada ou inativa']);
            return;
        }
        $linhas = $this->receita->getLinhas($rid);
        if (count($linhas) === 0) {
            http_response_code(400);
            echo json_encode(['message' => 'Receita sem ingredientes configurados']);
            return;
        }

        $ingBeforeQty = [];
        foreach ($linhas as $ln) {
            $iid = (int) $ln['ingrediente_id'];
            $need = (float) $ln['quantidade'] * $vezes;
            $this->ingrediente->ingrediente_id = $iid;
            $st = $this->ingrediente->getById();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(400);
                echo json_encode(['message' => 'Ingrediente inválido na receita', 'ingrediente_id' => $iid]);
                return;
            }
            $atual = (float) ($row['quantidade_atual'] ?? 0);
            $ingBeforeQty[$iid] = $atual;
            if ($atual + 1e-9 < $need) {
                http_response_code(400);
                echo json_encode([
                    'message' => 'Stock de ingredientes insuficiente para esta produção',
                    'ingrediente_id' => $iid,
                    'ingrediente_nome' => $row['nome'] ?? '',
                    'necessario' => $need,
                    'disponivel' => $atual,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $rend = (int) ($rec['rendimento'] ?? 1);
        $prodOut = (int) ($rec['produto_id'] ?? 0);
        $unidades = $rend * $vezes;

        try {
            $this->db->beginTransaction();
            foreach ($linhas as $ln) {
                $iid = (int) $ln['ingrediente_id'];
                $need = (float) $ln['quantidade'] * $vezes;
                $this->ingrediente->adjustQuantidade($iid, -$need);
            }
            $this->produto->incrementStock($prodOut, $unidades);
            $this->logProducao('receita', $rid, $prodOut, $unidades, $vezes);
            $this->db->commit();

            // Alertas de matéria (só admins): após consumo, se algum ingrediente entrou em stock baixo.
            $seenIng = [];
            foreach ($linhas as $ln) {
                $iid = (int) $ln['ingrediente_id'];
                if (isset($seenIng[$iid])) {
                    continue;
                }
                $seenIng[$iid] = true;
                $before = (float) ($ingBeforeQty[$iid] ?? 0);
                $this->ingrediente->ingrediente_id = $iid;
                $rowAfter = $this->ingrediente->getById()->fetch(PDO::FETCH_ASSOC);
                if (!$rowAfter) {
                    continue;
                }
                $after = (float) ($rowAfter['quantidade_atual'] ?? 0);
                $min = (float) ($rowAfter['quantidade_minima'] ?? 0);
                if (sc_ingredient_entered_low($before, $after, $min)) {
                    sc_stock_mail_notify_admins_ingredient_low(
                        $this->db,
                        (string) ($rowAfter['nome'] ?? ''),
                        $after,
                        $min,
                        (string) ($rowAfter['unidade'] ?? '')
                    );
                }
            }

            echo json_encode([
                'message' => 'Receita executada',
                'receita_id' => $rid,
                'vezes' => $vezes,
                'produto_id' => $prodOut,
                'unidades_produzidas' => $unidades,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao executar receita', 'error_detail' => $e->getMessage()]);
        }
    }

    private function logProducao(string $tipo, ?int $receitaId, ?int $produtoId, ?int $qtyProd, ?int $vezesReceita): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO producao_log (tipo, receita_id, produto_id, quantidade_produto, vezes_receita, funcionario_id, pessoa_id)
             VALUES (:tipo, :rid, :pid, :qp, :vr, :fid, :peid)'
        );
        $stmt->bindValue(':tipo', $tipo);
        $stmt->bindValue(':rid', $receitaId, $receitaId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':pid', $produtoId, $produtoId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':qp', $qtyProd, $qtyProd !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':vr', $vezesReceita, $vezesReceita !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $fid = Auth::funcionarioId();
        $stmt->bindValue(':fid', $fid, $fid ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':peid', (int) Auth::pessoaId(), PDO::PARAM_INT);
        $stmt->execute();
    }
}
