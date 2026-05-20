<?php

require_once __DIR__ . '/FaturaDocumentRenderer.php';
require_once __DIR__ . '/DocumentStorageService.php';
require_once __DIR__ . '/FaturacaoService.php';

class FaturaPdfService
{
    public static function dompdfDisponivel(): bool
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        return file_exists($autoload);
    }

    /**
     * Gera e arquiva PDF (ou HTML se Dompdf não estiver instalado).
     *
     * @return array{ficheiro_id: int, mime_type: string}|array{error: string}
     */
    public static function arquivarEmitida(PDO $db, int $faturaId, ?int $criadoPor = null): array
    {
        $fatura = FaturacaoService::obterEmitida($db, $faturaId);
        if (!$fatura) {
            return ['error' => 'Fatura não encontrada'];
        }

        $html = FaturaDocumentRenderer::renderEmitida($fatura);
        $serie = $fatura['serie'] ?? 'FT';
        $num = $fatura['numero'] ?? $faturaId;
        $nome = 'Fatura_' . $serie . '_' . $num . '.pdf';

        $bin = self::htmlParaPdf($html);
        $mime = 'application/pdf';
        if ($bin === null) {
            $bin = $html;
            $mime = 'text/html';
            $nome = 'Fatura_' . $serie . '_' . $num . '.html';
        }

        return DocumentStorageService::guardarConteudo(
            $db,
            'emitida',
            $faturaId,
            $bin,
            $nome,
            $mime,
            'gerado',
            $criadoPor
        );
    }

    /**
     * @return string|null bytes PDF ou null se só HTML disponível sem dompdf
     */
    public static function htmlParaPdf(string $html): ?string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            return null;
        }
        require_once $autoload;
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return null;
        }

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
