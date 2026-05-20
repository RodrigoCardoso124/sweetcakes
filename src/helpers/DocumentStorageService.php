<?php

/**
 * Armazenamento privado de PDFs/documentos fiscais (fora do acesso web directo).
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

    public static function tabelasOk(PDO $db): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['documento_ficheiros']);

        return (int) $stmt->fetchColumn() > 0;
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
            return ['error' => 'Execute /api/migrate_012_documentos.php', 'code' => 503];
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
        $nomeOriginal = self::sanitizarNome((string) ($file['name'] ?? 'documento.pdf'));
        $mime = self::detectarMime($tmp, (string) ($file['type'] ?? ''));
        $ext = self::MIME_PERMITIDOS[$mime] ?? 'bin';
        $sha = hash_file('sha256', $tmp);
        $tamanho = (int) filesize($tmp);

        $relDir = date('Y') . '/' . date('m');
        $absDir = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir)) {
            mkdir($absDir, 0750, true);
        }

        $baseName = $tipoDocumento . '_' . $documentoId . '_' . $origem . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $relPath = $relDir . '/' . $baseName;
        $absPath = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

        if (!move_uploaded_file($tmp, $absPath) && !rename($tmp, $absPath)) {
            return ['error' => 'Não foi possível guardar o ficheiro', 'code' => 500];
        }

        try {
            $db->beginTransaction();
            self::removerPorDocumento($db, $tipoDocumento, $documentoId, $origem, false);
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
                $relPath,
                $sha,
                $mime,
                $tamanho,
                $origem,
                $criadoPor,
            ]);
            $ficheiroId = (int) $db->lastInsertId();
            self::ligarDocumento($db, $tipoDocumento, $documentoId, $ficheiroId);
            $db->commit();

            return [
                'ficheiro_id' => $ficheiroId,
                'nome_original' => $nomeOriginal,
                'mime_type' => $mime,
                'tamanho_bytes' => $tamanho,
                'sha256' => $sha,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($absPath);

            return ['error' => $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * Guarda conteúdo gerado (ex.: PDF/HTML da fatura emitida).
     *
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
            return ['error' => 'Execute /api/migrate_012_documentos.php', 'code' => 503];
        }
        if (!in_array($mime, array_keys(self::MIME_PERMITIDOS), true)) {
            $mime = 'application/pdf';
        }
        if (strlen($conteudo) > self::MAX_BYTES) {
            return ['error' => 'Ficheiro demasiado grande', 'code' => 400];
        }

        $ext = self::MIME_PERMITIDOS[$mime];
        $relDir = date('Y') . '/' . date('m');
        $absDir = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir)) {
            mkdir($absDir, 0750, true);
        }
        $baseName = $tipoDocumento . '_' . $documentoId . '_' . $origem . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $relPath = $relDir . '/' . $baseName;
        $absPath = self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        if (file_put_contents($absPath, $conteudo) === false) {
            return ['error' => 'Erro ao gravar ficheiro', 'code' => 500];
        }

        $sha = hash('sha256', $conteudo);
        $tamanho = strlen($conteudo);
        $nomeOriginal = self::sanitizarNome($nomeOriginal);

        try {
            $db->beginTransaction();
            self::removerPorDocumento($db, $tipoDocumento, $documentoId, $origem, false);
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
                $relPath,
                $sha,
                $mime,
                $tamanho,
                $origem,
                $criadoPor,
            ]);
            $ficheiroId = (int) $db->lastInsertId();
            self::ligarDocumento($db, $tipoDocumento, $documentoId, $ficheiroId);
            $db->commit();

            return [
                'ficheiro_id' => $ficheiroId,
                'nome_original' => $nomeOriginal,
                'mime_type' => $mime,
                'tamanho_bytes' => $tamanho,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($absPath);

            return ['error' => $e->getMessage(), 'code' => 500];
        }
    }

    public static function obter(PDO $db, int $ficheiroId): ?array
    {
        if (!self::tabelasOk($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM documento_ficheiros WHERE ficheiro_id = ? LIMIT 1');
        $stmt->execute([$ficheiroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function caminhoAbsoluto(array $row): string
    {
        $rel = str_replace(['..', '\\'], ['', '/'], (string) ($row['caminho_relativo'] ?? ''));

        return self::storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    public static function enviarDownload(PDO $db, int $ficheiroId, bool $inline = false): void
    {
        $row = self::obter($db, $ficheiroId);
        if (!$row) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['message' => 'Ficheiro não encontrado']);

            return;
        }
        $path = self::caminhoAbsoluto($row);
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['message' => 'Ficheiro em falta no disco']);

            return;
        }

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
            "SELECT documento_id, ficheiro_id, nome_original, mime_type, tamanho_bytes, origem, criado_em
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
            $path = self::storageRoot() . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, (string) $old['caminho_relativo']);
            if (is_file($path)) {
                @unlink($path);
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
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            if (!empty($file['tmp_name']) && is_file($file['tmp_name'])) {
                // POST multipart via API
            } else {
                return 'Ficheiro inválido ou em falta';
            }
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
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'application/pdf';
        }

        return 'application/octet-stream';
    }

    private static function sanitizarNome(string $name): string
    {
        $name = preg_replace('/[^\pL\pN\s._-]/u', '_', $name) ?? 'documento.pdf';
        $name = trim($name);
        if ($name === '') {
            return 'documento.pdf';
        }
        if (!preg_match('/\.pdf$/i', $name) && !preg_match('/\.html$/i', $name)) {
            $name .= '.pdf';
        }

        return mb_substr($name, 0, 200);
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
