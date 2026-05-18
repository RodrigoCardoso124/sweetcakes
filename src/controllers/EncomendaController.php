<?php
include_once __DIR__ . '/../models/Encomenda.php';
include_once __DIR__ . '/../models/Pessoa.php';
include_once __DIR__ . '/../models/Funcionario.php';
include_once __DIR__ . '/../models/Promocao.php';
include_once __DIR__ . '/../models/FidelidadePontos.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/AuditHelper.php';
require_once __DIR__ . '/../helpers/PromocaoHelper.php';
require_once __DIR__ . '/../helpers/EncomendaStockHelper.php';
require_once __DIR__ . '/../helpers/FaturacaoService.php';
require_once __DIR__ . '/../helpers/LucroCalculator.php';

class EncomendaController
{
    private $db;
    private $encomenda;
    private $pessoa;
    private $funcionario;

    public function __construct($db)
    {
        $this->db = $db;
        $this->encomenda = new Encomenda($db);
        $this->pessoa = new Pessoa($db);
        $this->funcionario = new Funcionario($db);
    }

    public function index()
    {
        try {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;
            $wantsPaged = isset($_GET['page']) || isset($_GET['per_page']) || isset($_GET['paged']);

            if (Auth::isAdmin() || Auth::isFuncionario()) {
                $total = $this->encomenda->countRows(null);
                $stmt = $this->encomenda->getPaged($perPage, $offset, null);
            } else {
                $pid = Auth::pessoaId();
                if ($pid === null) {
                    if (ob_get_level() > 0) {
                        ob_clean();
                    }
                    http_response_code(403);
                    echo json_encode(['message' => 'Sessão inválida. Volta a iniciar sessão no painel.']);

                    return;
                }
                $total = $this->encomenda->countRows($pid);
                $stmt = $this->encomenda->getPaged($perPage, $offset, $pid);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

            $flags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            // A app mobile espera lista directa. O painel admin pede com ?page=
            // e nesse caso devolvemos o payload paginado completo.
            if ($wantsPaged) {
                $payload = [
                    'data' => $rows,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                ];
                $json = json_encode($payload, $flags);
            } else {
                $json = json_encode($rows, $flags);
            }

            if ($json === false) {
                throw new RuntimeException('json_encode falhou: '.json_last_error_msg());
            }

            echo $json;
        } catch (PDOException $e) {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            error_log('[EncomendaController::index] '.$e->getMessage());
            http_response_code(500);
            $out = [
                'error' => 'Erro na base de dados ao listar encomendas',
                'message' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Verifica se a tabela encomendas existe e se database.local.php no servidor está correto.',
            ];
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $out['code'] = $e->getCode();
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
        }
    }

    public function show($id)
    {
        $this->encomenda->encomenda_id = $id;
        $stmt = $this->encomenda->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            http_response_code(404);
            echo json_encode(['message' => 'Encomenda não encontrada']);

            return;
        }

        if (!Auth::isAdmin() && !Auth::isFuncionario() && (int) $data['cliente_id'] !== Auth::pessoaId()) {
            http_response_code(403);
            echo json_encode(['message' => 'Sem permissão para ver esta encomenda']);

            return;
        }

        echo json_encode($data);
    }

    private function resolverFuncionarioIdParaApp(array $data): ?int
    {
        if (isset($data['funcionario_id']) && (int) $data['funcionario_id'] > 0) {
            return (int) $data['funcionario_id'];
        }
        if (Auth::isAdmin() || Auth::isFuncionario()) {
            return null;
        }
        $stmt = $this->db->query('SELECT funcionario_id FROM funcionarios ORDER BY funcionario_id ASC LIMIT 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return $row ? (int) $row['funcionario_id'] : null;
    }

    public function store($data)
    {
        if (!isset($data['cliente_id'], $data['estado'], $data['total'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Campos obrigatórios: cliente_id, estado, total']);

            return;
        }

        if (!Auth::isAdmin()) {
            $data['cliente_id'] = Auth::pessoaId();
        }

        $funcId = $this->resolverFuncionarioIdParaApp($data);
        if ($funcId === null) {
            http_response_code(400);
            echo json_encode(['message' => 'funcionario_id é obrigatório ou não existe funcionário na loja']);

            return;
        }
        $data['funcionario_id'] = $funcId;

        $this->pessoa->pessoa_id = $data['cliente_id'];
        $stmt = $this->pessoa->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Cliente não existe']);

            return;
        }

        $this->funcionario->funcionario_id = $data['funcionario_id'];
        $stmt = $this->funcionario->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['message' => 'Funcionário não existe']);

            return;
        }

        // Promoção opcional: validar e calcular desconto antes de gravar.
        $promocaoId = isset($data['promocao_id']) && $data['promocao_id'] !== '' ? (int)$data['promocao_id'] : null;
        $desconto = 0.0;
        $promoRepo = null;
        $promoRow = null;
        if ($promocaoId !== null && $promocaoId > 0) {
            $promoRepo = new Promocao($this->db);
            $promoRow = $promoRepo->getById($promocaoId);
            if (!$promoRow) {
                http_response_code(400);
                echo json_encode(['message' => 'Promoção inválida']);
                return;
            }
            $err = PromocaoHelper::validarParaPessoa($promoRow, (int)$data['cliente_id'], $promoRepo);
            if ($err !== null) {
                http_response_code(400);
                echo json_encode(['message' => $err]);
                return;
            }
            $subtotalCliente = isset($data['subtotal'])
                ? (float)$data['subtotal']
                : ((float)$data['total'] + (float)($data['desconto'] ?? 0));
            $itens = is_array($data['itens'] ?? null) ? $data['itens'] : [];
            $desconto = PromocaoHelper::calcularDesconto($promoRow, $subtotalCliente, $itens);
            if ($desconto < 0) $desconto = 0.0;
            $esperado = max(0.0, $subtotalCliente - $desconto);
            if (abs((float)$data['total'] - $esperado) > 0.05) {
                $data['total'] = round($esperado, 2);
            }
        }

        $clienteId = (int) $data['cliente_id'];
        $nifFatura = null;
        if (LucroCalculator::columnExists($this->db, 'encomendas', 'quer_fatura_contribuinte')) {
            $nifRes = FaturacaoService::resolverNifEncomenda($this->db, $clienteId, $data);
            if (!empty($nifRes['error'])) {
                http_response_code(400);
                echo json_encode(['message' => $nifRes['error']]);

                return;
            }
            $nifFatura = $nifRes['nif'] ?? null;
        }

        $this->encomenda->cliente_id = $clienteId;
        $this->encomenda->funcionario_id = $data['funcionario_id'];
        $this->encomenda->estado = $data['estado'];
        $this->encomenda->total = $data['total'];

        if ($this->encomenda->create()) {
            $encomendaId = (int) $this->db->lastInsertId();
            $this->aplicarFaturaEncomenda($encomendaId, $data, $nifFatura);
            if ($promocaoId !== null && $promocaoId > 0 && $encomendaId) {
                try {
                    $upd = $this->db->prepare('UPDATE encomendas SET promocao_id = :p, desconto = :d WHERE encomenda_id = :id');
                    $upd->bindValue(':p', $promocaoId, PDO::PARAM_INT);
                    $upd->bindValue(':d', $desconto);
                    $upd->bindValue(':id', $encomendaId, PDO::PARAM_INT);
                    $upd->execute();
                    if ($promoRepo !== null) {
                        $promoRepo->registarUso($promocaoId, (int)$data['cliente_id'], (int)$encomendaId, $desconto);
                    }
                } catch (Throwable $e) {
                    error_log('Falha a guardar promoção na encomenda: ' . $e->getMessage());
                }
            }

            // Atribuição de pontos de fidelidade: 1 ponto por € (truncado).
            try {
                $fp = new FidelidadePontos($this->db);
                $fp->ensureSchema();
                $pontosGanhar = (int) floor((float) $data['total']);
                $fp->addPoints((int) $data['cliente_id'], $pontosGanhar);
            } catch (Throwable $e) {
                error_log('Fidelidade após encomenda: ' . $e->getMessage());
            }

            http_response_code(201);
            $resp = [
                'message' => 'Encomenda criada com sucesso',
                'encomenda_id' => $encomendaId,
                'total' => (float) $data['total'],
                'desconto' => $desconto,
                'promocao_id' => $promocaoId,
            ];
            if (LucroCalculator::columnExists($this->db, 'encomendas', 'quer_fatura_contribuinte')) {
                $resp['quer_fatura_contribuinte'] = !empty($data['quer_fatura_contribuinte'])
                    || !empty($data['fatura_com_contribuinte']);
                $resp['fatura_nif'] = $nifFatura;
            }
            echo json_encode($resp);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar encomenda']);
        }
    }

    public function update($id, $data)
    {
        try {
            if ($data === null) {
                $data = [];
            }

            $this->encomenda->encomenda_id = $id;

            $stmt = $this->encomenda->getById();
            $encomendaAtual = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$encomendaAtual) {
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                http_response_code(404);
                echo json_encode(['message' => 'Encomenda não encontrada']);

                return;
            }

            if (isset($data['estado']) && $data['estado'] !== null && trim((string) $data['estado']) !== '') {
                $novoEstado = trim((string) $data['estado']);
                $oldE = strtolower(trim((string) ($encomendaAtual['estado'] ?? '')));
                $newE = strtolower($novoEstado);

                $this->db->beginTransaction();
                try {
                    $this->encomenda->estado = $novoEstado;
                    $result = $this->encomenda->updateEstado();
                    if ($result !== true) {
                        $this->db->rollBack();
                    } else {
                        if ($newE === 'cancelada' && $oldE !== 'cancelada') {
                            EncomendaStockHelper::reporTodasLinhasEncomenda($this->db, (int) $id);
                        } elseif ($oldE === 'cancelada' && $newE !== 'cancelada') {
                            $errStock = EncomendaStockHelper::descontarTodasLinhasEncomenda($this->db, (int) $id);
                            if ($errStock !== null) {
                                $this->db->rollBack();
                                if (ob_get_level() > 0) {
                                    ob_clean();
                                }
                                http_response_code(409);
                                echo json_encode(['message' => $errStock]);

                                return;
                            }
                        }
                        $this->db->commit();
                    }
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            } else {
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                echo json_encode([
                    'message' => 'Nenhuma alteração realizada',
                    'encomenda_id' => $id,
                    'estado_atual' => $encomendaAtual['estado'],
                ]);

                return;
            }

            if ($result === true) {
                $stmt = $this->encomenda->getById();
                $encomendaAtualizada = $stmt->fetch(PDO::FETCH_ASSOC);

                $notificacaoEmail = null;
                $clienteId = $encomendaAtualizada['cliente_id'] ?? null;
                if ($clienteId && $encomendaAtual['estado'] !== $encomendaAtualizada['estado']) {
                    require_once __DIR__ . '/../helpers/email_helper.php';
                    $this->pessoa->pessoa_id = $clienteId;
                    $stmtPessoa = $this->pessoa->getById();
                    $pessoa = $stmtPessoa->fetch(PDO::FETCH_ASSOC);
                    if ($pessoa && !empty($pessoa['email'])) {
                        $mailRes = enviar_email_estado_encomenda(
                            $pessoa['email'],
                            (int) $id,
                            $encomendaAtual['estado'],
                            $encomendaAtualizada['estado']
                        );
                        $notificacaoEmail = [
                            'tentou_enviar' => true,
                            'enviado' => $mailRes['ok'],
                            'motivo' => $mailRes['motivo'],
                        ];
                        if (!empty($mailRes['erro_detalhe'])) {
                            $notificacaoEmail['erro_detalhe'] = $mailRes['erro_detalhe'];
                        }
                        error_log(
                            '[ENCOMENDA] Email estado encomenda #'.$id.': '
                            .($mailRes['ok'] ? 'OK' : 'falhou ('.$mailRes['motivo'].')')
                        );
                    } else {
                        $notificacaoEmail = [
                            'tentou_enviar' => false,
                            'enviado' => false,
                            'motivo' => 'cliente_sem_email',
                        ];
                        sc_email_audit_log('cliente_sem_email', [
                            'encomenda_id' => (int) $id,
                            'cliente_id' => (int) $clienteId,
                        ]);
                        error_log('[ENCOMENDA] Email não enviado #'.$id.' — cliente sem email na tabela pessoas');
                    }
                    AuditHelper::log($this->db, 'encomenda_estado', 'encomenda', (string) $id, [
                        'de' => $encomendaAtual['estado'],
                        'para' => $encomendaAtualizada['estado'],
                    ]);
                }

                if (ob_get_level() > 0) {
                    ob_clean();
                }

                $payload = [
                    'message' => 'Encomenda atualizada com sucesso',
                    'encomenda_id' => $id,
                    'estado_anterior' => $encomendaAtual['estado'],
                    'estado_novo' => $encomendaAtualizada['estado'],
                ];
                if ($notificacaoEmail !== null) {
                    $payload['notificacao_email'] = $notificacaoEmail;
                }

                echo json_encode($payload);
            } else {
                $errorInfo = $this->db->errorInfo();
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                http_response_code(500);
                $out = ['message' => 'Erro ao atualizar encomenda'];
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    $out['error'] = $errorInfo[2] ?? 'Erro desconhecido';
                    $out['error_code'] = $errorInfo[0] ?? null;
                }
                echo json_encode($out);
            }
        } catch (Exception $e) {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            error_log('[EncomendaController] '.$e->getMessage());
            http_response_code(500);
            $out = ['message' => 'Erro ao atualizar encomenda'];
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $out['error'] = $e->getMessage();
                $out['file'] = $e->getFile();
                $out['line'] = $e->getLine();
            }
            echo json_encode($out);
        } catch (Error $e) {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            error_log('[EncomendaController] '.$e->getMessage());
            http_response_code(500);
            $out = ['message' => 'Erro fatal ao atualizar encomenda'];
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $out['error'] = $e->getMessage();
                $out['file'] = $e->getFile();
                $out['line'] = $e->getLine();
            }
            echo json_encode($out);
        }
    }

    public function destroy($id)
    {
        $this->encomenda->encomenda_id = $id;
        $stmt = $this->encomenda->getById();
        $rowEnc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rowEnc) {
            http_response_code(404);
            echo json_encode(['message' => 'Encomenda não encontrada']);

            return;
        }

        $jaCancelada = strtolower(trim((string) ($rowEnc['estado'] ?? ''))) === 'cancelada';

        try {
            $this->db->beginTransaction();
            if (!$jaCancelada) {
                EncomendaStockHelper::reporTodasLinhasEncomenda($this->db, (int) $id);
            }
            if (!$this->encomenda->delete()) {
                $this->db->rollBack();
                http_response_code(500);
                echo json_encode(['message' => 'Erro ao remover encomenda']);

                return;
            }
            $this->db->commit();
            echo json_encode(['message' => 'Encomenda removida com sucesso']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[EncomendaController::destroy] '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover encomenda']);
        }
    }

    private function aplicarFaturaEncomenda(int $encomendaId, array $data, ?string $nifResolvido): void
    {
        if (!LucroCalculator::columnExists($this->db, 'encomendas', 'quer_fatura_contribuinte')) {
            return;
        }
        $quer = !empty($data['quer_fatura_contribuinte']) || !empty($data['fatura_com_contribuinte']);
        $stmt = $this->db->prepare(
            'UPDATE encomendas SET quer_fatura_contribuinte = ?, fatura_nif = ? WHERE encomenda_id = ?'
        );
        $stmt->execute([$quer ? 1 : 0, $quer ? $nifResolvido : null, $encomendaId]);
    }
}
