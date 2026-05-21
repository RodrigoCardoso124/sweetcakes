<?php

require_once __DIR__ . '/CloudinaryUploadHelper.php';

/**
 * PDFs fiscais: base de dados (Vercel/produção) ou pasta local (XAMPP).
 * Cloudinary desactivado para documentos — só imagens de produtos usam Cloudinary.
 */
class DocumentStorageService
{
    public const TIPOS_DOC = ['emitida', 'recebida'];
    public const MIME_PERMITIDOS = [
        'application/pdf' => 'pdf',
        'application/x-pdf' => 'pdf',
        'text/html' => 'html',
    ];
    public const MAX_BYTES = 15728640; // 15 MB
    public const CAMINHO_BD = 'db:inline';

    public static function tabelasOk(PDO $db): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['documento_ficheiros']);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function colunaConteudoOk(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['documento_ficheiros', 'conteudo']);
        $cache = (int) $stmt->fetchColumn() > 0;

        return $cache;
    }

    public static function storageRoot(): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'faturacao';
        if (!is_dir($root)) {
            mkdir($root, 0750, true);
            @file_put_contents($root . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
        }

        return realpath($root) ?: $root;
    }

    /** Produção Vercel ou migração 013 → guardar PDF na BD. */
    private static function usarArmazenamentoBd(PDO $db): bool
    {
        if (!empty(getenv('VERCEL')) || !empty(getenv('VERCEL_ENV'))) {
            return true;
        }
        $flag = getenv('DOCUMENTOS_NA_BD');
        if ($flag === '1' || $flag === 'true') {
            return true;
        }

        return self::colunaConteudoOk($db) && !self::discoPersistenteUtilizavel();
    }

    private static function discoPersistenteUtilizavel(): bool
    {
        if (!empty(getenv('VERCEL')) || !empty(getenv('VERCEL_ENV'))) {
            return false;
        }
        $root = self::storageRoot();

        return is_dir($root) && is_writable($root);
    }

    /**
     * @return array{ficheiro_id: int}|array{error: string, code?: int}
     */
    public static function guardarUpload(
        PDO $db,
        string $tipoDocumento,
        int $documentoId,
        array $file,
        string $origem = 'upload',
        ?int $criadoPor = null
    ): array {
        if (!self::tabelasOk($db)) {
            return ['error' => 'Arquivo de documentos não instalado na base de dados.', 'code' => 503];
        }
        if (!in_array($tipoDocumento, self::TIPOS_DOC, true)) {
            return ['error' => 'tipo_documento inválido', 'code' => 400];
        }
        if ($documentoId <= 0) {
            return ['error' => 'documento_id inválido', 'code' => 400];
        }

        $err = self::validarFicheiroUpload($file);
        if ($err !== null) {
            return ['error' => $err, 'code' => 400];
        }

        $tmp = $file['tmp_name'];
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            return ['error' => 'Não foi possível ler o ficheiro', 'code' => 500];
        }

        $nomeOriginal = self::sanitizarNome((string) ($file['name'] ?? 'documento.pdf'));
        $mime = self::detectarMime($tmp, (string) ($file['type'] ?? ''));
        $sha = hash('sha256', $bytes);
        $tamanho = strlen($bytes);

        return self::registarNaBd(
            $db,
            $tipoDocumento,
            $documentoId,
            $nomeOriginal,
            $bytes,
            $sha,
            $mime,
            $tamanho,
            $origem,
            $criadoPor
        );
    }

    /**
     * @return array{ficheiro_id: int}|array{error: string, code?: int}
     */
    public static function guardarConteudo(
        PDO $db,
        string $tipoDocumento,
        int $documentoId,
        string $conteudo,
        string $nomeOriginal,
        string $mime,
        string $origem = 'gerado',
        ?int $criadoPor = null
    ): array {
        if (!self::tabelasOk($db)) {
            return ['error' => 'Arquivo de documentos não instalado na base de dados.', 'code' => 503];
        }
        if (!in_array($mime, array_keys(self::MIME_PERMITIDOS), true)) {
            $mime = 'application/pdf';
        }
        if (strlen($conteudo) > self::MAX_BYTES) {
            return ['error' => 'Ficheiro demasiado grande', 'code' => 400];
        }

        return self::registarNaBd(
            $db,
            $tipoDocumento,
            $documentoId,
            self::sanitizarNome($nomeOriginal),
            $conteudo,
            hash('sha256', $conteudo),
            $mime,
            strlen($conteudo),
            $origem,
            $criadoPor
        );
    }

    /**
     * @return array{ficheiro_id: int, nome_original: string, mime_type: string, tamanho_bytes: int}|array{error: string, code?: int}
     */
    private static function registarNaBd(
        PDO $db,
        string $tipoDocumento,
        int $documentoId,
        string $nomeOriginal,
        string $bytes,
        string $sha,
        string $mime,
        int $tamanho,
        string $origem,
        ?int $criadoPor
    ): array {
        if (self::usarArmazenamentoBd($db)) {
            if (!self::colunaConteudoOk($db)) {
                return [
                    'error' => 'Arquivo de documentos na BD não configurado (migração 013).',
                    'code' => 503,
                ];
            }
            $caminho = self::CAMINHO_BD;
        } else {
            $disk = self::guardarBytesEmDisco($bytes, $mime, $tipoDocumento, $documentoId, $origem);
            if (!empty($disk['error'])) {
                return $disk;
            }
            $caminho = $disk['path'];
            $bytes = '';
        }

        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) {
                $db->beginTransaction();
            }
            self::removerPorDocumento($db, $tipoDocumento, $documentoId, $origem, false);

            if ($caminho === self::CAMINHO_BD) {
                $stmt = $db->prepare(
                    'INSERT INTO documento_ficheiros
                    (tipo_documento, documento_id, nome_original, caminho_relativo, conteudo, sha256, mime_type,
                     tamanho_bytes, origem, criado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->bindValue(1, $tipoDocumento);
                $stmt->bindValue(2, $documentoId, PDO::PARAM_INT);
                $stmt->bindValue(3, $nomeOriginal);
                $stmt->bindValue(4, $caminho);
                $stmt->bindValue(5, $bytes, PDO::PARAM_LOB);
                $stmt->bindValue(6, $sha);
                $stmt->bindValue(7, $mime);
                $stmt->bindValue(8, $tamanho, PDO::PARAM_INT);
                $stmt->bindValue(9, $origem);
                $stmt->bindValue(10, $criadoPor, $criadoPor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO documento_ficheiros
                    (tipo_documento, documento_id, nome_original, caminho_relativo, sha256, mime_type,
                     tamanho_bytes, origem, criado_por)
                    VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $tipoDocumento,
                    $documentoId,
                    $nomeOriginal,
                    $caminho,
                    $sha,
                    $mime,
                    $tamanho,
                    $origem,
                    $criadoPor,
                ]);
            }

            $ficheiroId = (int) $db->lastInsertId();
            self::ligarDocumento($db, $tipoDocumento, $documentoId, $ficheiroId);
            if ($ownTx) {
                $db->commit();
            }

            return [
                'ficheiro_id' => $ficheiroId,
                'nome_original' => $nomeOriginal,
                'mime_type' => $mime,
                'tamanho_bytes' => $tamanho,
                'sha256' => $sha,
                'armazenamento' => $caminho === self::CAMINHO_BD ? 'base_dados' : 'disco',
            ];
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }

            return ['error' => $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * @return array{path: string}|array{error: string, code?: int}
     */
    private static function guardarBytesEmDisco(
        string $bytes,
        string $mime,
        string $tipoDocumento,
        int $documentoId,
        string $origem
    ): array {
        $ext = self::MIME_PERMITIDOS[$mime] ?? 'bin';
        $relDir = date('Y') . '/' . date('m');
        $absDir = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0750, true)) {
            return ['error' => 'Não foi possível criar pasta de arquivo', 'code' => 500];
        }
        $baseName = $tipoDocumento . '_' . $documentoId . '_' . $origem . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $relPath = $relDir . '/' . $baseName;
        $absPath = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        if (@file_put_contents($absPath, $bytes) === false) {
            return ['error' => 'Erro ao gravar ficheiro no servidor', 'code' => 500];
        }

        return ['path' => $relPath];
    }

    public static function obter(PDO $db, int $ficheiroId): ?array
    {
        if (!self::tabelasOk($db)) {
            return null;
        }
        $cols = 'ficheiro_id, tipo_documento, documento_id, nome_original, caminho_relativo, sha256, mime_type, tamanho_bytes, origem, criado_por, criado_em';
        if (self::colunaConteudoOk($db)) {
            $cols .= ', conteudo';
        }
        $stmt = $db->prepare("SELECT {$cols} FROM documento_ficheiros WHERE ficheiro_id = ? LIMIT 1");
        $stmt->execute([$ficheiroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function obterConteudo(array $row): ?string
    {
        if (!isset($row['conteudo']) || $row['conteudo'] === null || $row['conteudo'] === '') {
            return null;
        }
        $c = $row['conteudo'];
        if (is_resource($c)) {
            $data = stream_get_contents($c);

            return $data === false ? null : $data;
        }

        return is_string($c) ? $c : null;
    }

    public static function caminhoAbsoluto(array $row): string
    {
        $rel = str_replace(['..', '\\'], ['', '/'], (string) ($row['caminho_relativo'] ?? ''));
        if ($rel === self::CAMINHO_BD || CloudinaryUploadHelper::isUrlArmazenamento($rel)) {
            return $rel;
        }

        return self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    private static function limparBuffersResposta(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Content-Type');
    }

    public static function enviarDownload(PDO $db, int $ficheiroId, bool $inline = false, bool $jsonMeta = false): void
    {
        self::limparBuffersResposta();
        $row = self::obter($db, $ficheiroId);
        if (!$row) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['message' => 'Ficheiro não encontrado'], JSON_UNESCAPED_UNICODE);

            return;
        }

        if ($jsonMeta) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ficheiro_id' => $ficheiroId,
                'nome' => $row['nome_original'] ?? 'documento.pdf',
                'externo' => false,
                'usar_proxy' => false,
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $body = self::obterConteudo($row);
        if ($body !== null && strlen($body) > 0) {
            self::enviarPdfBytes($body, $row, $inline);

            return;
        }

        $caminho = (string) ($row['caminho_relativo'] ?? '');
        if (CloudinaryUploadHelper::isUrlArmazenamento($caminho)) {
            self::enviarConteudoRemotoLegado($caminho, $row, $inline);

            return;
        }

        $path = self::caminhoAbsoluto($row);
        if ($path !== self::CAMINHO_BD && is_file($path) && is_readable($path)) {
            $nome = (string) ($row['nome_original'] ?? 'documento.pdf');
            $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
            $disp = $inline ? 'inline' : 'attachment';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($nome) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            readfile($path);
            exit;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'message' => 'PDF em falta. Volte a enviar o documento (Receber pedido ou Faturação).',
            'hint' => 'Volte a enviar o PDF. Se persistir, contacte o administrador.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function enviarPdfBytes(string $body, array $row, bool $inline): void
    {
        $nome = (string) ($row['nome_original'] ?? 'documento.pdf');
        $mime = (string) ($row['mime_type'] ?? 'application/pdf');
        $disp = $inline ? 'inline' : 'attachment';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) strlen($body));
        header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($nome) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $body;
        exit;
    }

    /** PDFs antigos só com URL Cloudinary — tentativa opcional. */
    private static function enviarConteudoRemotoLegado(string $url, array $row, bool $inline): void
    {
        $err = null;
        $body = CloudinaryUploadHelper::fetchUrlBytes($url, $err);
        if ($body !== null) {
            self::enviarPdfBytes($body, $row, $inline);

            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'message' => 'PDF antigo no Cloudinary indisponível. Envie o ficheiro outra vez.',
            'detalhe' => $err,
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function anexarMetadados(PDO $db, string $tipo, array $rows): array
    {
        if (!$rows || !self::tabelasOk($db)) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) ($r[$tipo === 'emitida' ? 'fatura_id' : 'recebida_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return $rows;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT documento_id, ficheiro_id, nome_original, mime_type, tamanho_bytes, origem, criado_em, caminho_relativo
             FROM documento_ficheiros
             WHERE tipo_documento = ? AND documento_id IN ($placeholders)"
        );
        $params = array_merge([$tipo], $ids);
        $stmt->execute($params);
        $map = [];
        while ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $did = (int) $f['documento_id'];
            if (!isset($map[$did])) {
                $map[$did] = $f;
            }
        }
        $pk = $tipo === 'emitida' ? 'fatura_id' : 'recebida_id';
        foreach ($rows as &$r) {
            $did = (int) ($r[$pk] ?? 0);
            $r['tem_ficheiro'] = isset($map[$did]);
            $r['ficheiro'] = $map[$did] ?? null;
            if (isset($map[$did])) {
                $r['ficheiro_id'] = (int) $map[$did]['ficheiro_id'];
            }
        }
        unset($r);

        return $rows;
    }

    private static function ligarDocumento(PDO $db, string $tipo, int $documentoId, int $ficheiroId): void
    {
        $tabela = $tipo === 'emitida' ? 'faturas_emitidas' : 'faturas_recebidas';
        $pk = $tipo === 'emitida' ? 'fatura_id' : 'recebida_id';
        if (!self::columnExists($db, $tabela, 'ficheiro_id')) {
            return;
        }
        $upd = $db->prepare("UPDATE {$tabela} SET ficheiro_id = ? WHERE {$pk} = ?");
        $upd->execute([$ficheiroId, $documentoId]);
    }

    private static function removerPorDocumento(
        PDO $db,
        string $tipo,
        int $documentoId,
        string $origem,
        bool $apagarLigacao
    ): void {
        $stmt = $db->prepare(
            'SELECT ficheiro_id, caminho_relativo FROM documento_ficheiros
             WHERE tipo_documento = ? AND documento_id = ? AND origem = ?'
        );
        $stmt->execute([$tipo, $documentoId, $origem]);
        while ($old = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rel = (string) ($old['caminho_relativo'] ?? '');
            if ($rel !== self::CAMINHO_BD && !CloudinaryUploadHelper::isUrlArmazenamento($rel)) {
                $path = self::storageRoot() . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $del = $db->prepare('DELETE FROM documento_ficheiros WHERE ficheiro_id = ?');
            $del->execute([(int) $old['ficheiro_id']]);
        }
        if ($apagarLigacao) {
            $tabela = $tipo === 'emitida' ? 'faturas_emitidas' : 'faturas_recebidas';
            $pk = $tipo === 'emitida' ? 'fatura_id' : 'recebida_id';
            if (self::columnExists($db, $tabela, 'ficheiro_id')) {
                $db->prepare("UPDATE {$tabela} SET ficheiro_id = NULL WHERE {$pk} = ?")->execute([$documentoId]);
            }
        }
    }

    private static function validarFicheiroUpload(array $file): ?string
    {
        if (empty($file['tmp_name'])) {
            return 'Ficheiro inválido ou em falta';
        }
        if (!is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
            return 'Ficheiro inválido ou em falta';
        }
        if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return 'Erro no upload (código ' . (int) $file['error'] . ')';
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'Ficheiro demasiado grande (máx. 15 MB)';
        }
        if ((int) ($file['size'] ?? 0) <= 0) {
            return 'Ficheiro vazio';
        }
        $mime = self::detectarMime((string) $file['tmp_name'], (string) ($file['type'] ?? ''));
        if (!isset(self::MIME_PERMITIDOS[$mime])) {
            return 'Tipo de ficheiro não permitido (use PDF)';
        }

        return null;
    }

    private static function detectarMime(string $path, string $declared): string
    {
        $declared = strtolower(trim($declared));
        if (isset(self::MIME_PERMITIDOS[$declared])) {
            return $declared;
        }
        if (function_exists('finfo_open') && is_file($path)) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $det = finfo_file($fi, $path);
                finfo_close($fi);
                if ($det && isset(self::MIME_PERMITIDOS[$det])) {
                    return $det;
                }
            }
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
            return 'application/pdf';
        }

        return 'application/octet-stream';
    }

    private static function sanitizarNome(string $name): string
    {
        $name = preg_replace('/[^\pL\pN\s._-]/u', '_', $name) ?? 'documento.pdf';
        $name = trim($name);

        return $name === '' ? 'documento.pdf' : mb_substr($name, 0, 200);
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
