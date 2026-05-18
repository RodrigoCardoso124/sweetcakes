<?php

require_once __DIR__ . '/../models/Despesa.php';

class DespesaController
{
    private $db;
    private $despesa;

    public function __construct($db)
    {
        $this->db = $db;
        $this->despesa = new Despesa($db);
    }

    public function index()
    {
        $de = isset($_GET['de']) ? trim((string) $_GET['de']) : null;
        $ate = isset($_GET['ate']) ? trim((string) $_GET['ate']) : null;
        if ($de === '') {
            $de = null;
        }
        if ($ate === '') {
            $ate = null;
        }
        echo json_encode($this->despesa->listAll($de, $ate), JSON_UNESCAPED_UNICODE);
    }

    public function show($id)
    {
        $row = $this->despesa->getById((int) $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['message' => 'Despesa não encontrada']);
            return;
        }
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    public function store($data)
    {
        if (!is_array($data)) {
            $data = [];
        }
        $parsed = $this->parsePayload($data);
        if ($parsed['error']) {
            http_response_code(400);
            echo json_encode(['message' => $parsed['error']]);
            return;
        }
        $id = $this->despesa->create($parsed['row']);
        http_response_code(201);
        echo json_encode(['message' => 'Despesa registada', 'despesa_id' => $id], JSON_UNESCAPED_UNICODE);
    }

    public function update($id, $data)
    {
        if (!$this->despesa->getById((int) $id)) {
            http_response_code(404);
            echo json_encode(['message' => 'Despesa não encontrada']);
            return;
        }
        $parsed = $this->parsePayload($data);
        if ($parsed['error']) {
            http_response_code(400);
            echo json_encode(['message' => $parsed['error']]);
            return;
        }
        $this->despesa->update((int) $id, $parsed['row']);
        echo json_encode(['message' => 'Despesa actualizada', 'despesa_id' => (int) $id], JSON_UNESCAPED_UNICODE);
    }

    public function destroy($id)
    {
        if (!$this->despesa->getById((int) $id)) {
            http_response_code(404);
            echo json_encode(['message' => 'Despesa não encontrada']);
            return;
        }
        $this->despesa->delete((int) $id);
        echo json_encode(['message' => 'Despesa removida'], JSON_UNESCAPED_UNICODE);
    }

    private function parsePayload(array $data): array
    {
        $tipos = ['material', 'embalagem', 'equipamento', 'servicos', 'outro'];
        $tipo = trim((string) ($data['tipo'] ?? 'outro'));
        if (!in_array($tipo, $tipos, true)) {
            return ['error' => 'tipo inválido', 'row' => []];
        }
        $desc = trim((string) ($data['descricao'] ?? ''));
        if ($desc === '') {
            return ['error' => 'descricao é obrigatória', 'row' => []];
        }
        $valor = (float) ($data['valor'] ?? 0);
        if ($valor <= 0) {
            return ['error' => 'valor deve ser > 0', 'row' => []];
        }
        $dataDesp = LucroCalculator::parseData($data['data_despesa'] ?? null, date('Y-m-d'));
        $ingId = isset($data['ingrediente_id']) ? (int) $data['ingrediente_id'] : null;
        if ($ingId !== null && $ingId <= 0) {
            $ingId = null;
        }
        $fornId = isset($data['fornecedor_id']) ? (int) $data['fornecedor_id'] : null;
        if ($fornId !== null && $fornId <= 0) {
            $fornId = null;
        }
        return [
            'error' => null,
            'row' => [
                'tipo' => $tipo,
                'descricao' => $desc,
                'valor' => round($valor, 2),
                'data_despesa' => $dataDesp,
                'ingrediente_id' => $ingId,
                'fornecedor_id' => $fornId,
                'notas' => isset($data['notas']) ? trim((string) $data['notas']) : null,
            ],
        ];
    }
}
