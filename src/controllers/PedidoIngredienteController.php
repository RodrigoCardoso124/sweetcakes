<?php

require_once __DIR__ . '/../models/PedidoIngrediente.php';
require_once __DIR__ . '/../models/Ingredientes.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/stock_alert_mail.php';
require_once __DIR__ . '/../helpers/LucroCalculator.php';

class PedidoIngredienteController
{
    private static function anexarFiscalAosPedidos(PDO $db, array $list): array
    {
        try {
            if (!$list || !LucroCalculator::tableExists($db, 'faturas_recebidas')) {
                return $list;
            }
            if (!LucroCalculator::columnExists($db, 'faturas_recebidas', 'pedido_id')) {
                return $list;
            }
            $pids = [];
            foreach ($list as $p) {
                if (($p['estado'] ?? '') === 'recebido') {
                    $pids[] = (int) $p['pedido_id'];
                }
            }
            if (!$pids) {
                return $list;
            }
            $placeholders = implode(',', array_fill(0, count($pids), '?'));
            $stmt = $db->prepare(
                "SELECT * FROM faturas_recebidas WHERE pedido_id IN ($placeholders)"
            );
            $stmt->execute($pids);
            $recs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$recs) {
                return $list;
            }
            if (file_exists(__DIR__ . '/../helpers/DocumentStorageService.php')) {
                require_once __DIR__ . '/../helpers/DocumentStorageService.php';
                if (DocumentStorageService::tabelasOk($db)) {
                    $recs = DocumentStorageService::anexarMetadados($db, 'recebida', $recs);
                }
            }
            $map = [];
            foreach ($recs as $r) {
                $map[(int) $r['pedido_id']] = $r;
            }
            foreach ($list as &$p) {
                $pid = (int) ($p['pedido_id'] ?? 0);
                if (!isset($map[$pid])) {
                    continue;
                }
                $r = $map[$pid];
                $p['recebida_id'] = (int) $r['recebida_id'];
                $p['tem_ficheiro'] = !empty($r['tem_ficheiro']);
                $p['ficheiro_id'] = $r['ficheiro_id'] ?? null;
                if (!empty($r['numero']) && empty($p['num_fatura'])) {
                    $p['num_fatura'] = $r['numero'];
                }
            }
            unset($p);
        } catch (Throwable $e) {
            error_log('anexarFiscalAosPedidos: ' . $e->getMessage());
        }

        return $list;
    }

    private function extrairPdfRececao($files): ?array
    {
        if (!is_array($files)) {
            return null;
        }
        if (!empty($files['documento']) && is_array($files['documento'])) {
            return $files['documento'];
        }
        if (!empty($files['documento_pdf']) && is_array($files['documento_pdf'])) {
            return $files['documento_pdf'];
        }
        if (!empty($files['fatura_pdf']) && is_array($files['fatura_pdf'])) {
            return $files['fatura_pdf'];
        }

        return null;
    }

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
        $list = $this->pedido->listAll($estado);
        echo json_encode(self::anexarFiscalAosPedidos($this->db, $list), JSON_UNESCAPED_UNICODE);
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
        $ingRow = $this->ingrediente->getById()->fetch(PDO::FETCH_ASSOC);
        if (!$ingRow) {
            http_response_code(400);
            echo json_encode(['message' => 'Ingrediente não encontrado']);
            return;
        }
        $notas = isset($data['notas']) ? trim((string) $data['notas']) : null;
        $emailFornecedor = isset($data['email_fornecedor']) ? trim((string) $data['email_fornecedor']) : null;
        if ($emailFornecedor === '') {
            $emailFornecedor = null;
        }

        $nomeMat = (string) ($ingRow['nome'] ?? 'Material');
        $unidade = (string) ($ingRow['unidade'] ?? '');

        $pid = $this->pedido->create($iid, $q, $notas, $emailFornecedor);

        $emailPedido = [
            'fornecedor' => ['ok' => false, 'motivo' => 'omitido'],
            'admins' => ['sent' => 0, 'skipped' => 0, 'last_result' => ['ok' => true, 'motivo' => 'sem_destinatarios']],
        ];

        if ($emailFornecedor !== null && filter_var($emailFornecedor, FILTER_VALIDATE_EMAIL)) {
            $emailPedido['fornecedor'] = sc_stock_mail_send_pedido_fornecedor(
                $emailFornecedor,
                $pid,
                $nomeMat,
                $q,
                $unidade,
                $notas
            );
        } elseif ($emailFornecedor !== null) {
            $emailPedido['fornecedor'] = ['ok' => false, 'motivo' => 'email_destino_invalido'];
        }

        $emailPedido['admins'] = sc_stock_mail_notify_admins_pedido_criado(
            $this->db,
            $pid,
            $nomeMat,
            $q,
            $unidade,
            $emailFornecedor
        );

        http_response_code(201);
        echo json_encode([
            'message' => 'Pedido registado',
            'pedido_id' => $pid,
            'email_pedido' => $emailPedido,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function update($id, $data, $files = null)
    {
        if (!is_array($data)) {
            $data = [];
        }
        if (!empty($_POST)) {
            $data = array_merge($data, $_POST);
        }
        unset($data['_method'], $data['action']);
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
                $iid = (int) $row['ingrediente_id'];
                $qtd = (float) $row['quantidade'];
                $this->ingrediente->adjustQuantidade($iid, $qtd);

                $vt = isset($data['valor_total']) ? (float) $data['valor_total'] : null;
                if ($vt === null || $vt <= 0) {
                    $this->db->rollBack();
                    http_response_code(400);
                    echo json_encode([
                        'message' => 'Ao marcar como recebido, indique o valor_total pago (€).',
                    ]);
                    return;
                }
                $nf = isset($data['num_fatura']) ? trim((string) $data['num_fatura']) : null;
                $dr = isset($data['data_recebido']) ? LucroCalculator::parseData($data['data_recebido'], date('Y-m-d')) : date('Y-m-d');
                $this->pedido->marcarRecebido((int) $id, $vt, $nf, $dr);
                $fiscal = ['skipped' => true];
                if (file_exists(__DIR__ . '/../helpers/FaturacaoIntegracaoService.php')) {
                    require_once __DIR__ . '/../helpers/FaturacaoIntegracaoService.php';
                    $fiscal = FaturacaoIntegracaoService::sincronizarPedidoRecebido($this->db, (int) $id);
                    if (!empty($fiscal['error'])) {
                        $this->db->rollBack();
                        http_response_code(400);
                        echo json_encode([
                            'message' => $fiscal['error'],
                            'hint' => 'Módulo de faturação em falta na base de dados.',
                        ], JSON_UNESCAPED_UNICODE);

                        return;
                    }
                }
                $this->db->commit();

                $pdf = $this->extrairPdfRececao($files);
                $arquivo = [];
                if ($pdf) {
                    if (empty($fiscal['recebida_id'])) {
                        echo json_encode([
                            'message' => 'Pedido recebido, mas o PDF não foi arquivado (módulo fiscal em falta).',
                            'pedido_id' => (int) $id,
                            'estado' => $estado,
                            'fiscal' => $fiscal,
                            'hint' => 'Módulo de arquivo de PDFs em falta na base de dados.',
                        ], JSON_UNESCAPED_UNICODE);

                        return;
                    }
                    require_once __DIR__ . '/../helpers/DocumentStorageService.php';
                    $arquivo = DocumentStorageService::guardarUpload(
                        $this->db,
                        'recebida',
                        (int) $fiscal['recebida_id'],
                        $pdf,
                        'upload',
                        Auth::pessoaId()
                    );
                    if (!empty($arquivo['error'])) {
                        http_response_code($arquivo['code'] ?? 500);
                        echo json_encode([
                            'message' => $arquivo['error'],
                            'hint' => 'Não foi possível guardar o PDF. Tente outra vez.',
                            'fiscal' => $fiscal,
                        ], JSON_UNESCAPED_UNICODE);

                        return;
                    }
                }
                echo json_encode(array_merge([
                    'message' => 'Pedido recebido — stock, despesa e compra fiscal registados',
                    'pedido_id' => (int) $id,
                    'estado' => $estado,
                    'fiscal' => $fiscal,
                ], $arquivo), JSON_UNESCAPED_UNICODE);

                return;
            } else {
                $this->pedido->setEstado((int) $id, $estado);
            }
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
