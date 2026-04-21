<?php
include_once __DIR__ . '/../models/Encomenda.php';
include_once __DIR__ . '/../models/Pessoa.php';
include_once __DIR__ . '/../models/Funcionario.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/AuditHelper.php';

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

            $payload = [
                'data' => $rows,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ];

            $flags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $json = json_encode($payload, $flags);
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

    public function store($data)
    {
        if (!isset($data['cliente_id'], $data['funcionario_id'], $data['estado'], $data['total'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Campos obrigatórios: cliente_id, funcionario_id, estado, total']);

            return;
        }

        if (!Auth::isAdmin()) {
            $data['cliente_id'] = Auth::pessoaId();
        }

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

        $this->encomenda->cliente_id = $data['cliente_id'];
        $this->encomenda->funcionario_id = $data['funcionario_id'];
        $this->encomenda->estado = $data['estado'];
        $this->encomenda->total = $data['total'];

        if ($this->encomenda->create()) {
            $encomendaId = $this->db->lastInsertId();
            http_response_code(201);
            echo json_encode([
                'message' => 'Encomenda criada com sucesso',
                'encomenda_id' => $encomendaId,
            ]);
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
                $this->encomenda->estado = $novoEstado;
                $result = $this->encomenda->updateEstado();
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

        if ($this->encomenda->delete()) {
            echo json_encode(['message' => 'Encomenda removida com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover encomenda']);
        }
    }
}
