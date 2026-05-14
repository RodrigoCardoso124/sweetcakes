<?php
include_once __DIR__ . '/../models/EncomendaDetalhes.php';
include_once __DIR__ . '/../models/Encomenda.php';
include_once __DIR__ . '/../models/Produto.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/EncomendaStockHelper.php';

class EncomendaDetalheController
{
    private $db;
    private $detalhe;
    private $encomenda;
    private $produto;

    public function __construct($db)
    {
        $this->db = $db;
        $this->detalhe = new EncomendaDetalhe($db);
        $this->encomenda = new Encomenda($db);
        $this->produto = new Produto($db);
    }

    private function assertOwnsEncomenda(int $encomendaId): bool
    {
        if (Auth::isAdmin() || Auth::isFuncionario()) {
            return true;
        }
        $this->encomenda->encomenda_id = $encomendaId;
        $row = $this->encomenda->getById()->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        return (int) $row['cliente_id'] === Auth::pessoaId();
    }

    public function index($encomenda_id = null)
    {
        if ($encomenda_id === null && isset($_GET['encomenda_id'])) {
            $encomenda_id = $_GET['encomenda_id'];
        }

        if ($encomenda_id) {
            if (!$this->assertOwnsEncomenda((int) $encomenda_id)) {
                http_response_code(403);
                echo json_encode(['message' => 'Sem permissão para estes detalhes']);

                return;
            }
            $stmt = $this->detalhe->getByEncomenda($encomenda_id);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $query = 'SELECT * FROM encomenda_detalhes';
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    public function show($id)
    {
        $this->detalhe->detalhe_id = $id;
        $stmt = $this->detalhe->getById();
        $detalhe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$detalhe) {
            http_response_code(404);
            echo json_encode(['message' => 'Detalhe da encomenda não encontrado']);

            return;
        }

        if (!$this->assertOwnsEncomenda((int) $detalhe['encomenda_id'])) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão']);

            return;
        }

        echo json_encode($detalhe);
    }

    public function store($data)
    {
        if (!isset($data['encomenda_id'], $data['produto_id'], $data['quantidade'], $data['especifico'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Campos obrigatórios: encomenda_id, produto_id, quantidade, especifico']);

            return;
        }

        if (!$this->assertOwnsEncomenda((int) $data['encomenda_id'])) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão para adicionar linhas a esta encomenda']);

            return;
        }

        $this->encomenda->encomenda_id = $data['encomenda_id'];
        $stmt = $this->encomenda->getById();
        $encRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$encRow) {
            http_response_code(400);
            echo json_encode(['message' => 'Encomenda não existe']);

            return;
        }
        if (strtolower((string) ($encRow['estado'] ?? '')) === 'cancelada') {
            http_response_code(400);
            echo json_encode(['message' => 'Não é possível adicionar linhas a uma encomenda cancelada']);

            return;
        }

        $this->produto->produto_id = $data['produto_id'];
        $stmt = $this->produto->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Produto não existe']);

            return;
        }

        $qtyInt = EncomendaStockHelper::quantidadeParaInt($data['quantidade']);
        $this->detalhe->encomenda_id = $data['encomenda_id'];
        $this->detalhe->produto_id = $data['produto_id'];
        $this->detalhe->quantidade = $data['quantidade'];
        $this->detalhe->especifico = $data['especifico'];

        try {
            $this->db->beginTransaction();
            $chk = EncomendaStockHelper::tentarDescontarStock($this->db, (int) $data['produto_id'], $qtyInt);
            if (!$chk['ok']) {
                $this->db->rollBack();
                http_response_code(409);
                echo json_encode(['message' => $chk['message'] ?? 'Stock insuficiente']);

                return;
            }
            if (!$this->detalhe->create()) {
                $this->db->rollBack();
                http_response_code(500);
                echo json_encode(['message' => 'Erro ao criar detalhe']);

                return;
            }
            $this->db->commit();
            http_response_code(201);
            echo json_encode(['message' => 'Detalhe criado com sucesso']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[EncomendaDetalhe::store] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar detalhe']);
        }
    }

    public function update($id, $data)
    {
        $this->detalhe->detalhe_id = $id;

        $stmt = $this->detalhe->getById();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['message' => 'Detalhe não encontrado']);

            return;
        }

        if (!$this->assertOwnsEncomenda((int) $existing['encomenda_id'])) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão']);

            return;
        }

        if (isset($data['produto_id'])) {
            $this->produto->produto_id = $data['produto_id'];
            $stmt = $this->produto->getById();
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(400);
                echo json_encode(['message' => 'Produto não existe']);

                return;
            }
        }

        $oldPid = (int) ($existing['produto_id'] ?? 0);
        $oldQ = EncomendaStockHelper::quantidadeParaInt($existing['quantidade'] ?? 0);
        $newPid = isset($data['produto_id']) ? (int) $data['produto_id'] : $oldPid;
        $newQ = isset($data['quantidade'])
            ? EncomendaStockHelper::quantidadeParaInt($data['quantidade'])
            : $oldQ;

        $this->detalhe->encomenda_id = $data['encomenda_id'] ?? $existing['encomenda_id'];
        $this->detalhe->produto_id = $data['produto_id'] ?? $existing['produto_id'];
        $this->detalhe->quantidade = $data['quantidade'] ?? $existing['quantidade'];
        $this->detalhe->especifico = $data['especifico'] ?? $existing['especifico'];

        $eid = (int) ($existing['encomenda_id'] ?? 0);
        $jaCancelada = EncomendaStockHelper::encomendaEstaCancelada($this->db, $eid);
        $stockMuda = !$jaCancelada && ($newPid !== $oldPid || $newQ !== $oldQ);

        try {
            if ($stockMuda) {
                $this->db->beginTransaction();
                EncomendaStockHelper::reporStock($this->db, $oldPid, $oldQ);
                $chk = EncomendaStockHelper::tentarDescontarStock($this->db, $newPid, $newQ);
                if (!$chk['ok']) {
                    $this->db->rollBack();
                    http_response_code(409);
                    echo json_encode(['message' => $chk['message'] ?? 'Stock insuficiente']);

                    return;
                }
            }
            if (!$this->detalhe->update()) {
                if ($stockMuda && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                http_response_code(500);
                echo json_encode(['message' => 'Erro ao atualizar detalhe']);

                return;
            }
            if ($stockMuda && $this->db->inTransaction()) {
                $this->db->commit();
            }
            echo json_encode(['message' => 'Detalhe atualizado com sucesso']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[EncomendaDetalhe::update] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar detalhe']);
        }
    }

    public function destroy($id)
    {
        $this->detalhe->detalhe_id = $id;
        $stmt = $this->detalhe->getById();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['message' => 'Detalhe não encontrado']);

            return;
        }
        if (!$this->assertOwnsEncomenda((int) $existing['encomenda_id'])) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão']);

            return;
        }

        $pid = (int) ($existing['produto_id'] ?? 0);
        $qty = EncomendaStockHelper::quantidadeParaInt($existing['quantidade'] ?? 0);
        $eid = (int) ($existing['encomenda_id'] ?? 0);
        $jaCancelada = EncomendaStockHelper::encomendaEstaCancelada($this->db, $eid);

        try {
            $this->db->beginTransaction();
            if (!$jaCancelada) {
                EncomendaStockHelper::reporStock($this->db, $pid, $qty);
            }
            if (!$this->detalhe->delete()) {
                $this->db->rollBack();
                http_response_code(500);
                echo json_encode(['message' => 'Erro ao remover detalhe']);

                return;
            }
            $this->db->commit();
            echo json_encode(['message' => 'Detalhe removido com sucesso']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[EncomendaDetalhe::destroy] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover detalhe']);
        }
    }
}
