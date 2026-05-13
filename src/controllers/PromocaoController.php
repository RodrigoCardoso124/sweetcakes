<?php
require_once __DIR__ . '/../models/Promocao.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/PromocaoHelper.php';

class PromocaoController {
    private $db;
    private $promocao;

    private const TIPOS_VALIDOS = ['percentual', 'valor_fixo', 'oferta', 'leve_pague'];

    public function __construct($db) {
        $this->db = $db;
        $this->promocao = new Promocao($db);
    }

    public function index() {
        $pid = Auth::pessoaId();
        $isFunc = Auth::isFuncionario() || Auth::isAdmin();
        $wantAll = isset($_GET['all']) && $_GET['all'] === '1';

        if ($wantAll && !$isFunc) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas funcionários']);
            return;
        }

        if ($wantAll) {
            echo json_encode($this->promocao->listAll());
            return;
        }

        $rows = $this->promocao->listActive($pid);
        echo json_encode(array_map([$this, 'sanitize'], $rows));
    }

    public function show($id) {
        if ($id === 'active' || $id === 'activas') {
            $pid = Auth::pessoaId();
            $rows = $this->promocao->listActive($pid);
            echo json_encode(array_map([$this, 'sanitize'], $rows));
            return;
        }

        $cid = (int)$id;
        if ($cid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID invalido']);
            return;
        }

        $row = $this->promocao->getById($cid);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['message' => 'Promoção não encontrada']);
            return;
        }

        $isFunc = Auth::isFuncionario() || Auth::isAdmin();
        if (!$isFunc && !PromocaoHelper::isActive($row)) {
            http_response_code(404);
            echo json_encode(['message' => 'Promoção não encontrada']);
            return;
        }
        echo json_encode($isFunc ? $row : $this->sanitize($row));
    }

    public function store($data) {
        if (!Auth::isFuncionario() && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas funcionários']);
            return;
        }
        if (!is_array($data)) $data = [];
        $clean = $this->validate($data, true);
        if ($clean === null) return;

        try {
            $id = $this->promocao->create($clean);
            http_response_code(201);
            echo json_encode(['message' => 'Promoção criada', 'promocao_id' => $id]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar promoção', 'error_detail' => $e->getMessage()]);
        }
    }

    public function update($id, $data) {
        if (!Auth::isFuncionario() && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas funcionários']);
            return;
        }

        $cid = (int)$id;
        if ($cid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID invalido']);
            return;
        }
        $existing = $this->promocao->getById($cid);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['message' => 'Promoção não encontrada']);
            return;
        }

        if (!is_array($data)) $data = [];
        $clean = $this->validate($data, false, $existing);
        if ($clean === null) return;

        try {
            $this->promocao->update($cid, $clean);
            echo json_encode(['message' => 'Promoção atualizada', 'promocao_id' => $cid]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar promoção', 'error_detail' => $e->getMessage()]);
        }
    }

    public function destroy($id) {
        if (!Auth::isFuncionario() && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['message' => 'Apenas funcionários']);
            return;
        }

        $cid = (int)$id;
        if ($cid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID invalido']);
            return;
        }
        try {
            $this->promocao->delete($cid);
            echo json_encode(['message' => 'Promoção removida']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover promoção', 'error_detail' => $e->getMessage()]);
        }
    }

    private function validate(array $data, bool $isCreate, ?array $existing = null): ?array {
        $merged = $existing ? array_merge($existing, $data) : $data;

        $errors = [];
        $titulo = trim((string)($merged['titulo'] ?? ''));
        if (mb_strlen($titulo) < 2 || mb_strlen($titulo) > 100) {
            $errors['titulo'] = 'Título obrigatório (2-100 caracteres)';
        }

        $subtitulo = isset($merged['subtitulo']) && $merged['subtitulo'] !== ''
            ? trim((string)$merged['subtitulo'])
            : null;
        if ($subtitulo !== null && mb_strlen($subtitulo) > 255) {
            $errors['subtitulo'] = 'Subtítulo até 255 caracteres';
        }

        $tipo = (string)($merged['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $errors['tipo'] = 'Tipo inválido';
        }

        $valorPercentual = null;
        $valorFixo = null;
        $leveQtd = null;
        $pagueQtd = null;
        $mensagemOferta = null;

        switch ($tipo) {
            case 'percentual':
                $v = filter_var($merged['valor_percentual'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($v === false || $v <= 0 || $v > 100) {
                    $errors['valor_percentual'] = 'Percentagem entre 0.01 e 100';
                } else {
                    $valorPercentual = round($v, 2);
                }
                break;
            case 'valor_fixo':
                $v = filter_var($merged['valor_fixo'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($v === false || $v <= 0 || $v > 9999) {
                    $errors['valor_fixo'] = 'Valor fixo entre 0.01 e 9999';
                } else {
                    $valorFixo = round($v, 2);
                }
                break;
            case 'oferta':
                $msg = trim((string)($merged['mensagem_oferta'] ?? ''));
                if ($msg === '' || mb_strlen($msg) > 255) {
                    $errors['mensagem_oferta'] = 'Mensagem obrigatória (até 255 caracteres)';
                } else {
                    $mensagemOferta = $msg;
                }
                break;
            case 'leve_pague':
                $l = filter_var($merged['leve_qtd'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 99]]);
                $p = filter_var($merged['pague_qtd'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 98]]);
                if ($l === false) $errors['leve_qtd'] = 'leve_qtd entre 2 e 99';
                if ($p === false) $errors['pague_qtd'] = 'pague_qtd entre 1 e 98';
                if ($l && $p && $p >= $l) $errors['pague_qtd'] = 'pague_qtd tem de ser menor que leve_qtd';
                $leveQtd = $l ?: null;
                $pagueQtd = $p ?: null;
                break;
        }

        $minCompra = isset($merged['min_compra']) && $merged['min_compra'] !== ''
            ? filter_var($merged['min_compra'], FILTER_VALIDATE_FLOAT)
            : 0.0;
        if ($minCompra === false || $minCompra < 0 || $minCompra > 9999) {
            $errors['min_compra'] = 'Valor mínimo inválido';
            $minCompra = 0.0;
        }

        $usoUnico = !empty($merged['uso_unico']) ? 1 : 0;

        $dataInicio = $this->parseDateTime($merged['data_inicio'] ?? null);
        $dataFim    = $this->parseDateTime($merged['data_fim'] ?? null);
        if ($dataInicio === null) $errors['data_inicio'] = 'Data início inválida';
        if ($dataFim === null) $errors['data_fim'] = 'Data fim inválida';
        if ($dataInicio && $dataFim && strtotime($dataFim) <= strtotime($dataInicio)) {
            $errors['data_fim'] = 'data_fim tem de ser depois de data_inicio';
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['message' => 'Dados inválidos', 'fields' => $errors]);
            return null;
        }

        return [
            'titulo' => $titulo,
            'subtitulo' => $subtitulo,
            'tipo' => $tipo,
            'valor_percentual' => $valorPercentual,
            'valor_fixo' => $valorFixo,
            'leve_qtd' => $leveQtd,
            'pague_qtd' => $pagueQtd,
            'mensagem_oferta' => $mensagemOferta,
            'min_compra' => $minCompra,
            'uso_unico' => $usoUnico,
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
        ];
    }

    private function parseDateTime($value): ?string {
        if (!is_string($value) || $value === '') return null;
        $value = trim($value);
        $value = str_replace('T', ' ', $value);
        $value = preg_replace('/Z$/i', '', $value) ?? $value;
        $value = preg_replace('/([+-]\d{2}:?\d{2})$/', '', $value) ?? $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 00:00:00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return null;
        }
        if (strlen($value) === 16) $value .= ':00';
        $ts = strtotime($value);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    private function sanitize(array $row): array {
        $copy = $row;
        unset($copy['criado_em'], $copy['atualizado_em']);
        return $copy;
    }
}
