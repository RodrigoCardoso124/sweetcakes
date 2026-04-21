<?php
include_once __DIR__ . '/../models/EncomendaDetalhes.php';
include_once __DIR__ . '/../models/Encomenda.php';
include_once __DIR__ . '/../models/Produto.php';
require_once __DIR__ . '/../helpers/Auth.php';

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
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Encomenda não existe']);

            return;
        }

        $this->produto->produto_id = $data['produto_id'];
        $stmt = $this->produto->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Produto não existe']);

            return;
        }

        $this->detalhe->encomenda_id = $data['encomenda_id'];
        $this->detalhe->produto_id = $data['produto_id'];
        $this->detalhe->quantidade = $data['quantidade'];
        $this->detalhe->especifico = $data['especifico'];

        if ($this->detalhe->create()) {
            http_response_code(201);
            echo json_encode(['message' => 'Detalhe criado com sucesso']);
        } else {
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

        $this->detalhe->encomenda_id = $data['encomenda_id'] ?? null;
        $this->detalhe->produto_id = $data['produto_id'] ?? null;
        $this->detalhe->quantidade = $data['quantidade'] ?? null;
        $this->detalhe->especifico = $data['especifico'] ?? null;

        if ($this->detalhe->update()) {
            echo json_encode(['message' => 'Detalhe atualizado com sucesso']);
        } else {
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

        if ($this->detalhe->delete()) {
            echo json_encode(['message' => 'Detalhe removido com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover detalhe']);
        }
    }
}
