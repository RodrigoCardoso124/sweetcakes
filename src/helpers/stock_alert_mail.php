<?php

/**
 * Emails de alerta de stock (matéria → admins elevados; produto → todos os funcionários)
 * e pedidos de material ao fornecedor.
 *
 * Regra anti-spam: só notificar quando o estado **entra** em “baixo” (antes estava acima
 * do mínimo configurado, depois fica em ou abaixo do mínimo). Excepção: criação de produto
 * com stock já baixo conta como um único evento lógico (notificar na criação).
 */
require_once __DIR__ . '/email_helper.php';

/** @param float|int $atual @param float|int $min */
function sc_stock_is_low($atual, $min): bool
{
    return (float) $min > 0 && (float) $atual <= (float) $min + 1e-9;
}

/**
 * Ingrediente: transição para stock baixo considerando mínimo antes e depois (ex.: alteração do mínimo).
 */
function sc_ingredient_entered_low_state(array $beforeRow, array $afterRow): bool
{
    $bA = (float) ($beforeRow['quantidade_atual'] ?? 0);
    $bM = (float) ($beforeRow['quantidade_minima'] ?? 0);
    $aA = (float) ($afterRow['quantidade_atual'] ?? 0);
    $aM = (float) ($afterRow['quantidade_minima'] ?? 0);

    return !sc_stock_is_low($bA, $bM) && sc_stock_is_low($aA, $aM);
}

/**
 * Ingrediente: transição para stock baixo (mesmo mínimo — ex. consumo na receita).
 */
function sc_ingredient_entered_low(float $beforeAtual, float $afterAtual, float $min): bool
{
    if (!sc_stock_is_low($afterAtual, $min)) {
        return false;
    }

    return !sc_stock_is_low($beforeAtual, $min);
}

/**
 * Produto: transição para stock baixo (ou criação já em baixo — ver $isCreate).
 */
function sc_produto_should_notify_funcionarios(bool $isCreate, int $saPrev, int $smPrev, int $saNew, int $smNew): bool
{
    if (!sc_stock_is_low($saNew, $smNew)) {
        return false;
    }
    if ($isCreate) {
        return true;
    }

    return !sc_stock_is_low($saPrev, $smPrev);
}

/**
 * Replica a lógica de cargo elevado (Auth::isElevatedAdmin) para filtrar emails sem depender de sessão.
 */
function sc_stock_mail_cargo_elevado(?string $cargo, int $funcionarioId): bool
{
    if ($funcionarioId === 13) {
        return true;
    }
    $c = strtolower(trim((string) $cargo));
    $c = str_replace(['á', 'à', 'â', 'ã', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'], ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'], $c);
    if (strpos($c, 'admin') !== false) {
        return true;
    }

    return in_array($c, ['gerente', 'gestor', 'owner', 'dono', 'ceo'], true);
}

/**
 * @return list<array{email: string, nome: string, funcionario_id: int}>
 */
function sc_stock_mail_collect_elevated_recipients(PDO $db): array
{
    $sql = 'SELECT f.funcionario_id, f.cargo, p.email, p.nome
            FROM funcionarios f
            INNER JOIN pessoas p ON p.pessoa_id = f.pessoas_pessoa_id
            WHERE TRIM(IFNULL(p.email, \'\')) <> \'\'';
    $stmt = $db->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $r) {
        $fid = (int) ($r['funcionario_id'] ?? 0);
        if (!sc_stock_mail_cargo_elevado($r['cargo'] ?? '', $fid)) {
            continue;
        }
        $em = strtolower(trim((string) ($r['email'] ?? '')));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $out[] = [
            'email' => $em,
            'nome' => (string) ($r['nome'] ?? ''),
            'funcionario_id' => $fid,
        ];
    }

    return $out;
}

/**
 * @return list<string> emails únicos
 */
function sc_stock_mail_collect_all_funcionario_emails(PDO $db): array
{
    $sql = 'SELECT DISTINCT LOWER(TRIM(p.email)) AS em
            FROM funcionarios f
            INNER JOIN pessoas p ON p.pessoa_id = f.pessoas_pessoa_id
            WHERE TRIM(IFNULL(p.email, \'\')) <> \'\'';
    $stmt = $db->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $seen = [];
    foreach ($rows as $r) {
        $em = (string) ($r['em'] ?? '');
        if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL) || isset($seen[$em])) {
            continue;
        }
        $seen[$em] = true;
    }

    return array_keys($seen);
}

/**
 * @return array{sent: int, skipped: int, last_result: array}
 */
function sc_stock_mail_notify_admins_ingredient_low(PDO $db, string $nomeMaterial, float $atual, float $min, string $unidade): array
{
    $recips = sc_stock_mail_collect_elevated_recipients($db);
    $sent = 0;
    $skipped = 0;
    $last = ['ok' => true, 'motivo' => 'sem_destinatarios'];
    $nomeH = htmlspecialchars($nomeMaterial, ENT_QUOTES, 'UTF-8');
    $uH = htmlspecialchars($unidade, ENT_QUOTES, 'UTF-8');
    $subj = '[Sweet Cakes] Stock baixo: '.$nomeMaterial;
    $html = '<div style="font-family:Arial,sans-serif;padding:16px;background:#fff8e6;">'
        .'<h2 style="margin:0 0 12px;color:#8a5a00;">Matéria-prima com stock baixo</h2>'
        .'<p><strong>'. $nomeH .'</strong></p>'
        .'<p>Stock actual: <strong>'.htmlspecialchars((string) $atual, ENT_QUOTES, 'UTF-8').' '.$uH
        .'</strong> · Mínimo: <strong>'.htmlspecialchars((string) $min, ENT_QUOTES, 'UTF-8').' '.$uH.'</strong></p>'
        .'<p style="font-size:13px;color:#555;">Mensagem automática do painel Sweet Cakes.</p></div>';
    $plain = "Stock baixo (matéria)\n{$nomeMaterial}\nActual: {$atual} {$unidade}\nMínimo: {$min} {$unidade}\n";

    foreach ($recips as $r) {
        $last = sc_mail_send_html_single($r['email'], $subj, $html, $plain);
        if (!empty($last['ok'])) {
            ++$sent;
        } else {
            ++$skipped;
        }
    }

    return ['sent' => $sent, 'skipped' => $skipped, 'last_result' => $last];
}

/**
 * @return array{sent: int, skipped: int, last_result: array}
 */
function sc_stock_mail_notify_funcionarios_produto_low(PDO $db, string $nomeProduto, int $atual, int $min): array
{
    $emails = sc_stock_mail_collect_all_funcionario_emails($db);
    $sent = 0;
    $skipped = 0;
    $last = ['ok' => true, 'motivo' => 'sem_destinatarios'];
    $nomeH = htmlspecialchars($nomeProduto, ENT_QUOTES, 'UTF-8');
    $subj = '[Sweet Cakes] Stock baixo (produto): '.$nomeProduto;
    $html = '<div style="font-family:Arial,sans-serif;padding:16px;background:#fff3e0;">'
        .'<h2 style="margin:0 0 12px;color:#b45309;">Produto com stock baixo</h2>'
        .'<p><strong>'.$nomeH.'</strong></p>'
        .'<p>Stock actual: <strong>'.(int) $atual.'</strong> · Mínimo (alerta): <strong>'.(int) $min.'</strong></p>'
        .'<p style="font-size:13px;color:#555;">Mensagem automática do painel Sweet Cakes.</p></div>';
    $plain = "Stock baixo (produto)\n{$nomeProduto}\nStock: {$atual}\nMínimo: {$min}\n";

    foreach ($emails as $em) {
        $last = sc_mail_send_html_single($em, $subj, $html, $plain);
        if (!empty($last['ok'])) {
            ++$sent;
        } else {
            ++$skipped;
        }
    }

    return ['sent' => $sent, 'skipped' => $skipped, 'last_result' => $last];
}

/**
 * Email ao fornecedor com detalhes do pedido de material.
 *
 * @return array{ok: bool, motivo: string, erro_detalhe?: string}
 */
function sc_stock_mail_send_pedido_fornecedor(
    string $to,
    int $pedidoId,
    string $materialNome,
    float $quantidade,
    string $unidade,
    ?string $notas
): array {
    $mh = htmlspecialchars($materialNome, ENT_QUOTES, 'UTF-8');
    $uh = htmlspecialchars($unidade, ENT_QUOTES, 'UTF-8');
    $nh = $notas !== null && $notas !== '' ? '<p>Notas: '.htmlspecialchars($notas, ENT_QUOTES, 'UTF-8').'</p>' : '';
    $subj = '[Sweet Cakes] Pedido de material #'.$pedidoId.' — '.$materialNome;
    $html = '<div style="font-family:Arial,sans-serif;padding:16px;">'
        .'<h2>Pedido de material</h2>'
        .'<p><strong>Pedido n.º '.(int) $pedidoId.'</strong></p>'
        .'<p>Material: <strong>'.$mh.'</strong></p>'
        .'<p>Quantidade pedida: <strong>'.htmlspecialchars((string) $quantidade, ENT_QUOTES, 'UTF-8').' '.$uh.'</strong></p>'
        .$nh
        .'<p style="font-size:13px;color:#555;">Mensagem gerada pelo painel Sweet Cakes.</p></div>';
    $plain = "Pedido #{$pedidoId}\nMaterial: {$materialNome}\nQuantidade: {$quantidade} {$unidade}\n"
        .($notas ? "Notas: {$notas}\n" : '');

    return sc_mail_send_html_single($to, $subj, $html, $plain);
}

/**
 * Aviso interno a admins: pedido de material registado.
 *
 * @return array{sent: int, skipped: int, last_result: array}
 */
function sc_stock_mail_notify_admins_pedido_criado(
    PDO $db,
    int $pedidoId,
    string $materialNome,
    float $quantidade,
    string $unidade,
    ?string $emailFornecedor
): array {
    $recips = sc_stock_mail_collect_elevated_recipients($db);
    $sent = 0;
    $skipped = 0;
    $last = ['ok' => true, 'motivo' => 'sem_destinatarios'];
    $mh = htmlspecialchars($materialNome, ENT_QUOTES, 'UTF-8');
    $ef = $emailFornecedor && filter_var($emailFornecedor, FILTER_VALIDATE_EMAIL)
        ? htmlspecialchars($emailFornecedor, ENT_QUOTES, 'UTF-8')
        : '(não indicado)';
    $subj = '[Sweet Cakes] Novo pedido de material #'.$pedidoId;
    $html = '<div style="font-family:Arial,sans-serif;padding:16px;">'
        .'<h2>Pedido registado no painel</h2>'
        .'<p>Pedido n.º <strong>'.(int) $pedidoId.'</strong></p>'
        .'<p>Material: <strong>'.$mh.'</strong> — quantidade: <strong>'
        .htmlspecialchars((string) $quantidade, ENT_QUOTES, 'UTF-8').' '.htmlspecialchars($unidade, ENT_QUOTES, 'UTF-8').'</strong></p>'
        .'<p>Email fornecedor: '.$ef.'</p></div>';
    $plain = "Novo pedido #{$pedidoId}\nMaterial: {$materialNome}\nQtd: {$quantidade} {$unidade}\nFornecedor: ".($emailFornecedor ?: 'n/d')."\n";

    foreach ($recips as $r) {
        $last = sc_mail_send_html_single($r['email'], $subj, $html, $plain);
        if (!empty($last['ok'])) {
            ++$sent;
        } else {
            ++$skipped;
        }
    }

    return ['sent' => $sent, 'skipped' => $skipped, 'last_result' => $last];
}
