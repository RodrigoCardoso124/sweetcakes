<?php

/**
 * HTML de fatura para arquivo / conversão PDF.
 */
class FaturaDocumentRenderer
{
    public static function renderEmitida(array $fatura): string
    {
        $emp = $fatura['empresa'] ?? [];
        $linhas = $fatura['linhas'] ?? [];
        $anulada = ($fatura['estado'] ?? '') === 'anulada';
        $doc = htmlspecialchars(
            ($fatura['serie'] ?? 'FT') . ' ' . ($fatura['numero'] ?? '') . '/' . substr((string) ($fatura['data_emissao'] ?? ''), 0, 4),
            ENT_QUOTES,
            'UTF-8'
        );
        $dataEm = htmlspecialchars((string) ($fatura['data_emissao'] ?? ''), ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($linhas as $l) {
            $pu = $l['preco_unitario_com_iva'] ?? null;
            if ($pu === null && !empty($l['quantidade'])) {
                $pu = (float) ($l['total_linha'] ?? 0) / (float) $l['quantidade'];
            }
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars((string) ($l['descricao'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="num">' . htmlspecialchars((string) ($l['quantidade'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="num">' . self::euro($pu) . '</td>'
                . '<td class="num">' . htmlspecialchars((string) ($l['taxa_iva_pct'] ?? ''), ENT_QUOTES, 'UTF-8') . '%</td>'
                . '<td class="num">' . self::euro($l['base_linha'] ?? 0) . '</td>'
                . '<td class="num">' . self::euro($l['iva_linha'] ?? 0) . '</td>'
                . '<td class="num">' . self::euro($l['total_linha'] ?? 0) . '</td>'
                . '</tr>';
        }

        $bannerAnulada = $anulada
            ? '<div class="anulada">DOCUMENTO ANULADO</div>'
            : '';

        return '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
            . '<style>'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;font-size:11pt;color:#2c2622;margin:24px}'
            . 'h1{font-size:16pt;margin:0 0 4px;color:#6b5344}'
            . 'h2{font-size:13pt;margin:20px 0 8px}'
            . '.empresa{font-size:9pt;color:#5c534c;line-height:1.4}'
            . '.cliente{margin:16px 0;padding:12px;background:#faf8f5;border:1px solid #e4ddd4;border-radius:6px}'
            . 'table{width:100%;border-collapse:collapse;margin-top:12px}'
            . 'th,td{border:1px solid #e4ddd4;padding:6px 8px;text-align:left}'
            . 'th{background:#5c4a3a;color:#faf8f5;font-size:9pt}'
            . 'td.num{text-align:right;white-space:nowrap}'
            . '.totais{margin-top:16px;text-align:right;font-size:11pt}'
            . '.totais p{margin:4px 0}'
            . '.total-final{font-size:13pt;font-weight:bold;color:#6b5344}'
            . '.nota{font-size:8pt;color:#5c534c;margin-top:20px}'
            . '.anulada{background:#ffebee;color:#d64545;text-align:center;padding:10px;font-weight:bold;margin-bottom:16px}'
            . '</style></head><body>'
            . $bannerAnulada
            . '<header><h1>' . htmlspecialchars((string) ($emp['nome'] ?? 'Sweet Cakes'), ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p class="empresa">NIF ' . htmlspecialchars((string) ($emp['nif'] ?? '—'), ENT_QUOTES, 'UTF-8')
            . '<br>' . htmlspecialchars((string) ($emp['morada'] ?? ''), ENT_QUOTES, 'UTF-8')
            . ($emp['email'] ?? '' ? '<br>' . htmlspecialchars((string) $emp['email'], ENT_QUOTES, 'UTF-8') : '')
            . '</p></header>'
            . '<h2>Fatura ' . $doc . '</h2>'
            . '<p>Data de emissão: ' . $dataEm . '</p>'
            . '<div class="cliente"><strong>Cliente</strong><br>'
            . htmlspecialchars((string) ($fatura['cliente_nome'] ?? ''), ENT_QUOTES, 'UTF-8')
            . '<br>NIF: ' . htmlspecialchars((string) ($fatura['cliente_nif'] ?? '—'), ENT_QUOTES, 'UTF-8')
            . '<br>' . htmlspecialchars((string) ($fatura['cliente_morada'] ?? ''), ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '<p><em>Preços com IVA incluído — valores de base e IVA calculados para efeitos fiscais.</em></p>'
            . '<table><thead><tr>'
            . '<th>Descrição</th><th>Qtd</th><th>Preço c/ IVA</th><th>IVA%</th><th>Base</th><th>IVA</th><th>Total</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="totais">'
            . '<p>Base tributável: ' . self::euro($fatura['total_base'] ?? 0) . '</p>'
            . '<p>IVA: ' . self::euro($fatura['total_iva'] ?? 0) . '</p>'
            . '<p class="total-final">Total: ' . self::euro($fatura['total_com_iva'] ?? 0) . '</p>'
            . '</div>'
            . '<p class="nota">Documento gerado pelo sistema Sweet Cakes. Valide com o seu contabilista antes de submeter à AT.</p>'
            . '</body></html>';
    }

    private static function euro($n): string
    {
        return '€' . number_format((float) $n, 2, ',', ' ');
    }
}
