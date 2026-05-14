<?php

require_once __DIR__ . '/../models/PedidoIngrediente.php';
require_once __DIR__ . '/../models/Ingredientes.php';
require_once __DIR__ . '/../helpers/Auth.php';

class PedidoIngredienteController
{
    private $db;
    private $pedido;
    private $ingrediente;

    public function __construct($db)
    {
        $this->db = $db;
        $this->pedido = new PedidoIngrediente($db);
        $this->ingrediente = new Ingredientes($db);
    }

    public function index()
    {
        $estado = isset($_GET['estado']) ? trim((string) $_GET['estado']) : null;
        if ($estado === '') {
            $estado = null;
        }
        echo json_encode($this->pedido->listAll($estado), JSON_UNESCAPED_UNICODE);
    }

    public function show($id)
    {
        $row = $this->pedido->getById((int) $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['message' => 'Pedido não encontrado']);
            return;
        }
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    public function store($data)
    {
        if (!is_array($data)) {
            $data = [];
        }
        $iid = (int) ($data['ingrediente_id'] ?? 0);
        $q = (float) ($data['quantidade'] ?? 0);
        if ($iid <= 0 || $q <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ingrediente_id e quantidade (>0) são obrigatórios']);
            return;
        }
        $this->ingrediente->ingrediente_id = $iid;
        $stmt = $this->ingrediente->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Ingrediente não encontrado']);
            return;
        }
        $notas = isset($data['notas']) ? trim((string) $data['notas']) : null;
        $pid = $this->pedido->create($iid, $q, $notas);
        http_response_code(201);
        echo json_encode(['message' => 'Pedido registado', 'pedido_id' => $pid]);
    }

    public function update($id, $data)
    {
        if (!is_array($data)) {
            $data = [];
        }
        $estado = trim((string) ($data['estado'] ?? ''));
        if (!in_array($estado, ['pendente', 'recebido', 'cancelado'], true)) {
            http_response_code(400);
            echo json_encode(['message' => 'estado inválido (pendente, recebido, cancelado)']);
            return;
        }
        $row = $this->pedido->getById((int) $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['message' => 'Pedido não encontrado']);
            return;
        }
        $anterior = (string) ($row['estado'] ?? '');

        try {
            $this->db->beginTransaction();

            if ($estado === 'recebido' && $anterior === 'pendente') {
                $this->ingrediente->adjustQuantidade((int) $row['ingrediente_id'], (float) $row['quantidade']);
            }

            $this->pedido->setEstado((int) $id, $estado);
            $this->db->commit();
            echo json_encode(['message' => 'Pedido atualizado', 'pedido_id' => (int) $id, 'estado' => $estado]);
        } catch (Throwable $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar pedido', 'error_detail' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        http_response_code(405);
        echo json_encode(['message' => 'Cancelar pedido: use PUT com estado cancelado']);
    }
}
