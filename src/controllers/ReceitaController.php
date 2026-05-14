<?php

require_once __DIR__ . '/../models/Receita.php';
require_once __DIR__ . '/../helpers/Auth.php';

class ReceitaController
{
    private $db;
    private $receita;

    public function __construct($db)
    {
        $this->db = $db;
        $this->receita = new Receita($db);
    }

    private function requireFuncionario(): bool
    {
        if (!Auth::isFuncionario()) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas funcionários podem aceder a receitas.']);

            return false;
        }

        return true;
    }

    private function requireElevated(): bool
    {
        if (!Auth::isElevatedAdmin($this->db)) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas administradores ou gestores podem gerir receitas.']);

            return false;
        }

        return true;
    }

    public function index()
    {
        if (!$this->requireFuncionario()) {
            return;
        }
        $rows = $this->receita->listAll();
        foreach ($rows as &$r) {
            $r['ingredientes'] = $this->receita->getLinhas((int) $r['receita_id']);
        }
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    public function show($id)
    {
        if (!$this->requireFuncionario()) {
            return;
        }
        $cid = (int) $id;
        if ($cid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID inválido']);
            return;
        }
        $row = $this->receita->getById($cid);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['message' => 'Receita não encontrada']);
            return;
        }
        $row['ingredientes'] = $this->receita->getLinhas($cid);
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    public function store($data)
    {
        if (!$this->requireElevated()) {
            return;
        }
        if (!is_array($data)) {
            $data = [];
        }
        $clean = $this->validatePayload($data, true);
        if ($clean === null) {
            return;
        }
        try {
            $this->db->beginTransaction();
            $id = $this->receita->create($clean);
            $this->receita->replaceLinhas($id, $clean['linhas']);
            $this->db->commit();
            http_response_code(201);
            echo json_encode(['message' => 'Receita criada', 'receita_id' => $id]);
        } catch (Throwable $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar receita', 'error_detail' => $e->getMessage()]);
        }
    }

    public function update($id, $data)
    {
        if (!$this->requireElevated()) {
            return;
        }
        $cid = (int) $id;
        if ($cid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID inválido']);
            return;
        }
        if (!$this->receita->getById($cid)) {
            http_response_code(404);
            echo json_encode(['message' => 'Receita não encontrada']);
            return;
        }
        if (!is_array($data)) {
            $data = [];
        }
        $clean = $this->validatePayload($data, false);
        if ($clean === null) {
            return;
        }
        try {
            $this->db->beginTransaction();
            $this->receita->update($cid, $clean);
            $this->receita->replaceLinhas($cid, $clean['linhas']);
            $this->db->commit();
            echo json_encode(['message' => 'Receita atualizada', 'receita_id' => $cid]);
        } catch (Throwable $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar receita', 'error_detail' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        if (!$this->requireElevated()) {
            return;
        }
        $cid = (int) $id;
        if ($cid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID inválido']);
            return;
        }
        if ($this->receita->delete($cid)) {
            echo json_encode(['message' => 'Receita removida']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover receita']);
        }
    }

    private function validatePayload(array $data, bool $isCreate): ?array
    {
        $nome = trim((string) ($data['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 150) {
            http_response_code(422);
            echo json_encode(['message' => 'Nome inválido (1–150 caracteres)']);
            return null;
        }
        $pid = (int) ($data['produto_id'] ?? 0);
        if ($pid <= 0) {
            http_response_code(422);
            echo json_encode(['message' => 'produto_id obrigatório']);
            return null;
        }
        $rend = (int) ($data['rendimento'] ?? 1);
        if ($rend < 1) {
            $rend = 1;
        }
        if ($rend > 9999) {
            http_response_code(422);
            echo json_encode(['message' => 'rendimento inválido']);
            return null;
        }
        $linhas = $data['ingredientes'] ?? $data['linhas'] ?? [];
        if (!is_array($linhas) || count($linhas) === 0) {
            http_response_code(422);
            echo json_encode(['message' => 'Indique pelo menos um ingrediente e quantidade']);
            return null;
        }
        $norm = [];
        foreach ($linhas as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $iid = (int) ($ln['ingrediente_id'] ?? 0);
            $q = (float) ($ln['quantidade'] ?? 0);
            if ($iid > 0 && $q > 0) {
                $norm[] = ['ingrediente_id' => $iid, 'quantidade' => $q];
            }
        }
        if (count($norm) === 0) {
            http_response_code(422);
            echo json_encode(['message' => 'Linhas de ingredientes inválidas']);
            return null;
        }

        return [
            'nome' => $nome,
            'produto_id' => $pid,
            'rendimento' => $rend,
            'ativo' => array_key_exists('ativo', $data) ? (!empty($data['ativo']) ? 1 : 0) : 1,
            'notas' => isset($data['notas']) ? trim((string) $data['notas']) : null,
            'linhas' => $norm,
        ];
    }
}
