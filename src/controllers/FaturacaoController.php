<?php

require_once __DIR__ . '/../helpers/FaturacaoService.php';
require_once __DIR__ . '/../helpers/LucroCalculator.php';
require_once __DIR__ . '/../helpers/DocumentStorageService.php';
require_once __DIR__ . '/../helpers/Auth.php';

class FaturacaoController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function index()
    {
        if (!FaturacaoService::tabelasOk($this->db)) {
            http_response_code(503);
            echo json_encode([
                'message' => 'Módulo de faturação não instalado na base de dados.',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $de = LucroCalculator::parseData($_GET['de'] ?? null, date('Y-m-01'));
        $ate = LucroCalculator::parseData($_GET['ate'] ?? null, date('Y-m-d'));
        $view = trim((string) ($_GET['view'] ?? 'emitidas'));

        if ($de > $ate) {
            http_response_code(400);
            echo json_encode(['message' => 'Data inicial posterior à final']);

            return;
        }

        if ($view === 'download') {
            $fid = (int) ($_GET['ficheiro_id'] ?? 0);
            if ($fid <= 0) {
                http_response_code(400);
                echo json_encode(['message' => 'ficheiro_id obrigatório']);

                return;
            }
            $inline = !empty($_GET['inline']);
            $jsonMeta = !empty($_GET['meta']);
            DocumentStorageService::enviarDownload($this->db, $fid, $inline, $jsonMeta);

            return;
        }

        switch ($view) {
            case 'arquivo':
                $tipo = isset($_GET['tipo']) ? trim((string) $_GET['tipo']) : null;
                $q = isset($_GET['q']) ? trim((string) $_GET['q']) : null;
                $cf = null;
                if (isset($_GET['com_ficheiro']) && $_GET['com_ficheiro'] !== '') {
                    $cf = $_GET['com_ficheiro'] === '1' || $_GET['com_ficheiro'] === 'true';
                }
                echo json_encode([
                    'periodo' => ['de' => $de, 'ate' => $ate],
                    'documentos' => FaturacaoService::listarArquivo($this->db, $de, $ate, $tipo, $q, $cf),
                    'pdf_disponivel' => file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php'),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'encomendas-pendentes':
                echo json_encode([
                    'encomendas' => FaturacaoService::listarEncomendasParaFaturar($this->db),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'recebidas':
                echo json_encode([
                    'periodo' => ['de' => $de, 'ate' => $ate],
                    'recebidas' => FaturacaoService::listarRecebidas($this->db, $de, $ate),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'resumo-iva':
                echo json_encode(FaturacaoService::resumoIva($this->db, $de, $ate), JSON_UNESCAPED_UNICODE);
                break;

            case 'config':
                echo json_encode([
                    'config' => FaturacaoService::getConfig($this->db),
                    'pdf_disponivel' => file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php'),
                    'documentos_na_bd' => true,
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'export-at':
                $csv = FaturacaoService::exportAtCsv($this->db, $de, $ate);
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="iva-export-' . $de . '_' . $ate . '.csv"');
                echo "\xEF\xBB\xBF" . $csv;
                exit();

            case 'preview':
                $encId = (int) ($_GET['encomenda_id'] ?? 0);
                if ($encId <= 0) {
                    http_response_code(400);
                    echo json_encode(['message' => 'encomenda_id obrigatório']);

                    return;
                }
                $cfg = FaturacaoService::getConfig($this->db);
                $taxa = (float) ($cfg['taxa_iva_padrao'] ?? 23);
                if (isset($_GET['taxa_iva_pct'])) {
                    $taxa = (float) $_GET['taxa_iva_pct'];
                }
                $preview = FaturacaoService::previewEncomenda($this->db, $encId, $taxa);
                if (!empty($preview['error'])) {
                    http_response_code(400);
                }
                echo json_encode($preview, JSON_UNESCAPED_UNICODE);
                break;

            case 'emitidas':
            default:
                $estado = isset($_GET['estado']) ? trim((string) $_GET['estado']) : null;
                echo json_encode([
                    'periodo' => ['de' => $de, 'ate' => $ate],
                    'emitidas' => FaturacaoService::listarEmitidas($this->db, $de, $ate, $estado),
                ], JSON_UNESCAPED_UNICODE);
                break;
        }
    }

    public function show($id)
    {
        if ($id === 'config') {
            echo json_encode(['config' => FaturacaoService::getConfig($this->db)], JSON_UNESCAPED_UNICODE);

            return;
        }

        $fid = (int) $id;
        if ($fid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID inválido']);

            return;
        }

        $f = FaturacaoService::obterEmitida($this->db, $fid);
        if (!$f) {
            http_response_code(404);
            echo json_encode(['message' => 'Fatura não encontrada']);

            return;
        }
        echo json_encode($f, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array|null $files $_FILES quando multipart
     */
    public function store($data, $files = null)
    {
        if (!is_array($data)) {
            $data = [];
        }
        if (!empty($_POST) && empty($data['action'])) {
            $data = array_merge($data, $_POST);
        }

        $action = trim((string) ($data['action'] ?? 'emitir'));

        if ($action === 'config') {
            FaturacaoService::saveConfig($this->db, $data);
            echo json_encode([
                'message' => 'Dados da empresa guardados',
                'config' => FaturacaoService::getConfig($this->db),
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        if ($action === 'upload') {
            $this->handleUpload($data, $files);

            return;
        }

        if ($action === 'recebida') {
            $res = FaturacaoService::criarRecebida($this->db, $data);
            if (!empty($res['error'])) {
                http_response_code($res['code'] ?? 400);
                echo json_encode(['message' => $res['error']], JSON_UNESCAPED_UNICODE);

                return;
            }
            $arq = $this->anexarFicheiroSeEnviado('recebida', (int) $res['recebida_id'], $files, $data);
            if (!empty($arq['error'])) {
                http_response_code($arq['code'] ?? 500);
                echo json_encode(['message' => $arq['error'], 'recebida_id' => $res['recebida_id']], JSON_UNESCAPED_UNICODE);

                return;
            }
            http_response_code(201);
            echo json_encode(array_merge($res, $arq), JSON_UNESCAPED_UNICODE);

            return;
        }

        $res = FaturacaoService::emitir($this->db, $data);
        if (!empty($res['error'])) {
            http_response_code($res['code'] ?? 400);
            echo json_encode(['message' => $res['error']], JSON_UNESCAPED_UNICODE);

            return;
        }
        http_response_code(201);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    public function update($id, $data)
    {
        $sub = trim((string) ($data['action'] ?? ''));
        if ($sub === 'anular' || (isset($_GET['action']) && $_GET['action'] === 'anular')) {
            $res = FaturacaoService::anular($this->db, (int) $id);
            if (!empty($res['error'])) {
                http_response_code($res['code'] ?? 400);
                echo json_encode(['message' => $res['error']], JSON_UNESCAPED_UNICODE);

                return;
            }
            echo json_encode($res, JSON_UNESCAPED_UNICODE);

            return;
        }

        if ($id === 'config' || $sub === 'config') {
            FaturacaoService::saveConfig($this->db, $data);
            echo json_encode([
                'message' => 'Dados da empresa guardados',
                'config' => FaturacaoService::getConfig($this->db),
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        http_response_code(405);
        echo json_encode(['message' => 'Use PUT com action=anular']);
    }

    public function destroy($id)
    {
        $rid = (int) $id;
        if ($rid <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'ID inválido']);

            return;
        }
        if (!FaturacaoService::apagarRecebida($this->db, $rid)) {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao apagar']);

            return;
        }
        echo json_encode(['message' => 'Documento removido'], JSON_UNESCAPED_UNICODE);
    }

    private function handleUpload(?array $data, $files): void
    {
        $data = is_array($data) ? $data : [];
        $tipo = trim((string) ($data['tipo_documento'] ?? ''));
        $docId = (int) ($data['documento_id'] ?? 0);
        if (!in_array($tipo, ['emitida', 'recebida'], true) || $docId <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'tipo_documento e documento_id são obrigatórios']);

            return;
        }
        $file = $this->extrairFicheiro($files);
        if (!$file) {
            http_response_code(400);
            echo json_encode(['message' => 'Envie o ficheiro no campo documento (PDF)']);

            return;
        }
        $pessoaId = Auth::pessoaId();
        $res = DocumentStorageService::guardarUpload(
            $this->db,
            $tipo,
            $docId,
            $file,
            'upload',
            $pessoaId
        );
        if (!empty($res['error'])) {
            http_response_code($res['code'] ?? 400);
            echo json_encode(['message' => $res['error']], JSON_UNESCAPED_UNICODE);

            return;
        }
        echo json_encode(array_merge(['message' => 'Ficheiro arquivado'], $res), JSON_UNESCAPED_UNICODE);
    }

    private function anexarFicheiroSeEnviado(string $tipo, int $docId, $files, array $data): array
    {
        $file = $this->extrairFicheiro($files);
        if (!$file) {
            return [];
        }

        return DocumentStorageService::guardarUpload(
            $this->db,
            $tipo,
            $docId,
            $file,
            'upload',
            Auth::pessoaId()
        );
    }

    private function extrairFicheiro($files): ?array
    {
        if (!is_array($files)) {
            return null;
        }
        if (!empty($files['documento']) && is_array($files['documento'])) {
            return $files['documento'];
        }
        if (!empty($files['documento_pdf'])) {
            return $files['documento_pdf'];
        }

        return null;
    }
}
