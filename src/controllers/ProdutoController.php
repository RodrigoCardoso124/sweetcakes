<?php
include_once __DIR__ . "/../models/Produto.php";
include_once __DIR__ . "/../models/ProdutoIngrediente.php";
include_once __DIR__ . "/../models/Ingredientes.php";

class ProdutoController {
    private $db;
    private $produto;
    private $produtoIngrediente;
    private $ingrediente;
    private $cloudinaryConfig;

    public function __construct($db) {
        $this->db = $db;
        $this->produto = new Produto($db);
        $this->produtoIngrediente = new ProdutoIngrediente($db);
        $this->ingrediente = new Ingredientes($db);
        $this->ensureAlergeniosColumn();
        $this->cloudinaryConfig = $this->loadCloudinaryConfig();
    }

    /** Diretório base das imagens de produtos (relativo à raiz do projeto). */
    private const UPLOAD_DIR = "uploads/produtos";

    private function loadCloudinaryConfig() {
        $env = function($key) {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
            $value = getenv($key);
            return ($value !== false && $value !== '') ? $value : null;
        };

        $config = [
            'enabled' => false,
            'cloud_name' => $env('CLOUDINARY_CLOUD_NAME'),
            'api_key' => $env('CLOUDINARY_API_KEY'),
            'api_secret' => $env('CLOUDINARY_API_SECRET'),
            'upload_preset' => $env('CLOUDINARY_UPLOAD_PRESET'),
            'folder' => $env('CLOUDINARY_FOLDER') ?: 'sweet_cakes/produtos',
        ];

        $localFile = __DIR__ . '/../config/cloudinary_config.php';
        if (file_exists($localFile)) {
            $local = require $localFile;
            if (is_array($local)) {
                $config = array_merge($config, $local);
            }
        }
        $localOverride = __DIR__ . '/../config/cloudinary_config.local.php';
        if (file_exists($localOverride)) {
            $override = require $localOverride;
            if (is_array($override)) {
                $config = array_merge($config, $override);
            }
        }

        $hasPresetFlow = !empty($config['cloud_name']) && !empty($config['upload_preset']);
        $hasSignedFlow = !empty($config['cloud_name']) && !empty($config['api_key']) && !empty($config['api_secret']);
        $allowByFlag = !array_key_exists('enabled', $config) || $config['enabled'] !== false;
        $config['enabled'] = $allowByFlag && ($hasPresetFlow || $hasSignedFlow);
        $config['missing_reason'] = null;
        if (!$config['enabled']) {
            if (empty($config['cloud_name'])) {
                $config['missing_reason'] = 'CLOUDINARY_CLOUD_NAME';
            } elseif (empty($config['upload_preset']) && (empty($config['api_key']) || empty($config['api_secret']))) {
                $config['missing_reason'] = 'CLOUDINARY_UPLOAD_PRESET ou CLOUDINARY_API_KEY + CLOUDINARY_API_SECRET';
            }
        }
        return $config;
    }

    private function ensureAlergeniosColumn() {
        $stmt = $this->db->query("SHOW COLUMNS FROM produtos LIKE 'alergenios'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->db->exec("ALTER TABLE produtos ADD COLUMN alergenios TEXT NULL");
        }
    }

    private function getPublicBaseUrl() {
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!empty($forwardedProto)) {
            $scheme = strtolower(trim(explode(',', $forwardedProto)[0])) === 'https' ? 'https' : 'http';
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
    }

    private function parseAlergenios($raw) {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = array_map('trim', explode(',', (string) $raw));
            }
        }

        $clean = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value !== '') $clean[] = $value;
        }
        return array_values(array_unique($clean));
    }

    private function normalizarAlergeniosParaGuardar($raw) {
        $parsed = $this->parseAlergenios($raw);
        return empty($parsed) ? null : json_encode($parsed, JSON_UNESCAPED_UNICODE);
    }

    private function guardarImagemUpload($file, &$errorMessage = null) {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = "Upload de imagem inválido.";
            return null;
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errorMessage = "Ficheiro temporário inválido.";
            return null;
        }

        $isVercel = !empty($_SERVER['VERCEL']) || getenv('VERCEL');
        if ($isVercel && empty($this->cloudinaryConfig['enabled'])) {
            $reason = $this->cloudinaryConfig['missing_reason'] ?? 'configuração Cloudinary em falta';
            $errorMessage = "Cloudinary não ativo em produção (falta {$reason}).";
            return null;
        }

        if (!empty($this->cloudinaryConfig['enabled'])) {
            return $this->uploadImagemCloudinary($file, $errorMessage);
        }

        $targetDir = __DIR__ . "/../../" . self::UPLOAD_DIR . "/";
        if (!file_exists($targetDir) && !mkdir($targetDir, 0777, true)) {
            $errorMessage = "Não foi possível criar a pasta de uploads.";
            return null;
        }

        $originalName = $file['name'] ?? 'imagem';
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
        $nomeImagem = uniqid("produto_") . "_" . $safeBase;
        $targetFile = $targetDir . $nomeImagem;

        if (!move_uploaded_file($file["tmp_name"], $targetFile)) {
            $errorMessage = "Falha ao guardar a imagem no servidor.";
            return null;
        }

        if (!file_exists($targetFile) || filesize($targetFile) <= 0) {
            @unlink($targetFile);
            $errorMessage = "Imagem guardada de forma inválida.";
            return null;
        }

        return $nomeImagem;
    }

    private function uploadImagemCloudinary($file, &$errorMessage = null) {
        $cfg = $this->cloudinaryConfig;
        if (!function_exists('curl_init')) {
            $errorMessage = "cURL não está ativo no PHP para upload Cloudinary.";
            return null;
        }
        $cloudName = $cfg['cloud_name'] ?? null;
        if (empty($cloudName)) {
            $errorMessage = "Cloudinary sem cloud_name.";
            return null;
        }

        $folder = $cfg['folder'] ?? 'sweet_cakes/produtos';
        $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";
        $postFields = [
            'file' => new CURLFile($file['tmp_name'], $file['type'] ?? 'application/octet-stream', $file['name'] ?? 'upload'),
            'folder' => $folder,
        ];

        if (!empty($cfg['upload_preset'])) {
            $postFields['upload_preset'] = $cfg['upload_preset'];
        } else {
            $apiKey = $cfg['api_key'] ?? null;
            $apiSecret = $cfg['api_secret'] ?? null;
            if (empty($apiKey) || empty($apiSecret)) {
                $errorMessage = "Cloudinary sem upload_preset ou credenciais API.";
                return null;
            }
            $timestamp = time();
            $signature = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");
            $postFields['api_key'] = $apiKey;
            $postFields['timestamp'] = $timestamp;
            $postFields['signature'] = $signature;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError) {
            $errorMessage = "Falha ao contactar Cloudinary: " . ($curlError ?: 'erro desconhecido');
            return null;
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || empty($decoded['secure_url'])) {
            $cloudMsg = $decoded['error']['message'] ?? 'upload recusado';
            $errorMessage = "Cloudinary erro: {$cloudMsg}";
            return null;
        }

        return $decoded['secure_url'];
    }

    /**
     * Converte o valor da BD (nome ou caminho) para o caminho completo para a API.
     * Na BD guardamos apenas o nome do ficheiro; na resposta enviamos o caminho completo.
     */
    private function imagemParaResposta($imagem) {
        if (empty($imagem)) return null;
        if (preg_match('/^https?:\/\//i', $imagem)) return $imagem;
        // CORREÇÃO: se for apenas nome do ficheiro, acrescenta o diretório
        return (strpos($imagem, '/') !== false) ? $imagem : self::UPLOAD_DIR . "/" . $imagem;
    }

    private function imagemUrlPublica($imagemPath) {
        if (empty($imagemPath)) return null;
        if (preg_match('/^https?:\/\//i', $imagemPath)) return $imagemPath;
        return $this->getPublicBaseUrl() . "/image.php?path=" . rawurlencode($imagemPath);
    }

    /**
     * Devolve o caminho absoluto no disco para apagar ou verificar o ficheiro.
     * Aceita tanto nome do ficheiro como caminho antigo na BD.
     */
    private function imagemParaFicheiro($imagem) {
        if (empty($imagem)) return null;
        if (preg_match('/^https?:\/\//i', $imagem)) return null;
        $base = __DIR__ . "/../../";
        return (strpos($imagem, '/') !== false) ? $base . $imagem : $base . self::UPLOAD_DIR . "/" . $imagem;
    }

    // ------------------------------------------------------------
    // LISTAR TODOS
    // ------------------------------------------------------------
    public function index() {
        $stmt = $this->produto->getAll();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (isset($row['imagem'])) {
                $row['imagem'] = $this->imagemParaResposta($row['imagem']);
                $row['imagem_url'] = $this->imagemUrlPublica($row['imagem']);
            }
            $row['alergenios'] = $this->parseAlergenios($row['alergenios'] ?? null);
        }
        echo json_encode($rows);
    }

    // ------------------------------------------------------------
    // MOSTRAR POR ID
    // ------------------------------------------------------------
    public function show($id) {
        $this->produto->produto_id = $id;
        $stmt = $this->produto->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            if (isset($data['imagem'])) {
                $data['imagem'] = $this->imagemParaResposta($data['imagem']);
                $data['imagem_url'] = $this->imagemUrlPublica($data['imagem']);
            }
            $data['alergenios'] = $this->parseAlergenios($data['alergenios'] ?? null);
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Produto não encontrado"]);
        }
    }

    // ------------------------------------------------------------
    // CRIAR PRODUTO
    // Com imagem: guarda o FICHEIRO em uploads/produtos/ e só o NOME na BD.
    // O index() devolve depois uploads/produtos/nome para o frontend ir buscar.
    // ------------------------------------------------------------
    public function store($data, $files = null) {
        if (!isset($data['nome'], $data['descricao'], $data['preco'])) {
            http_response_code(400);
            echo json_encode(["message" => "Campos obrigatórios: nome, descricao, preco"]);
            return;
        }

        $nomeImagem = null;
        if ($files && isset($files['imagem']) && $files['imagem']['error'] === 0) {
            $uploadError = null;
            $nomeImagem = $this->guardarImagemUpload($files['imagem'], $uploadError);
            if ($nomeImagem === null) {
                http_response_code(422);
                echo json_encode(["message" => $uploadError ?? "Erro no upload da imagem"]);
                return;
            }
        }

        $this->produto->nome       = $data['nome'];
        $this->produto->descricao  = $data['descricao'];
        $this->produto->preco      = $data['preco'];
        $this->produto->disponivel = 1;
        $this->produto->imagem     = $nomeImagem;
        $this->produto->alergenios = $this->normalizarAlergeniosParaGuardar($data['alergenios'] ?? null);

        if ($this->produto->create()) {
            http_response_code(201);
            echo json_encode(["message" => "Produto criado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar produto"]);
        }
    }

    // ------------------------------------------------------------
    // ATUALIZAR PRODUTO
    // Se enviar nova imagem: guarda o FICHEIRO em uploads/produtos/ e só o NOME na BD.
    // O index() devolve o caminho para o frontend ir buscar.
    // ------------------------------------------------------------
    public function update($id, $data, $files = null) {
        $this->produto->produto_id = $id;

        $stmt = $this->produto->getById();
        $produtoAtual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produtoAtual) {
            http_response_code(404);
            echo json_encode(["message" => "Produto não encontrado"]);
            return;
        }

        $imagemValor = $produtoAtual['imagem'];

        if ($files && isset($files['imagem']) && $files['imagem']['error'] === 0) {
            $uploadError = null;
            $nomeImagem = $this->guardarImagemUpload($files['imagem'], $uploadError);
            if ($nomeImagem === null) {
                http_response_code(422);
                echo json_encode(["message" => $uploadError ?? "Erro no upload da imagem"]);
                return;
            }
            $imagemValor = $nomeImagem;
        }

        $this->produto->nome       = $data['nome']       ?? $produtoAtual['nome'];
        $this->produto->descricao  = $data['descricao']  ?? $produtoAtual['descricao'];
        $this->produto->preco      = $data['preco']      ?? $produtoAtual['preco'];
        $this->produto->disponivel = $produtoAtual['disponivel'];
        $this->produto->imagem     = $imagemValor;
        $this->produto->alergenios = array_key_exists('alergenios', $data)
            ? $this->normalizarAlergeniosParaGuardar($data['alergenios'])
            : ($produtoAtual['alergenios'] ?? null);

        if ($this->produto->update()) {
            echo json_encode(["message" => "Produto atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar produto"]);
        }
    }

    // ------------------------------------------------------------
    // APAGAR PRODUTO
    // ------------------------------------------------------------
    public function destroy($id) {
        $this->produto->produto_id = $id;

        $stmt = $this->produto->getById();
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            http_response_code(404);
            echo json_encode(["message" => "Produto não encontrado"]);
            return;
        }

        if (!empty($prod['imagem'])) {
            $path = $this->imagemParaFicheiro($prod['imagem']);
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }

        if ($this->produto->delete()) {
            echo json_encode(["message" => "Produto removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover produto"]);
        }
    }
}
?>
