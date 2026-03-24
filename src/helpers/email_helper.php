<?php

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Registo no servidor (FTP / gestor de ficheiros do host), não no teu PC.
 * Ficheiro: <raiz do site>/var/email-audit.log — útil se error_log do PHP não for acessível.
 */
function sc_email_audit_log(string $event, array $data = []): void
{
    $root = dirname(__DIR__, 2);
    $dir = $root.'/var';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir.'/email-audit.log';
    $line = [
        'time' => date('c'),
        'event' => $event,
        'data' => $data,
    ];
    @file_put_contents($path, json_encode($line, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
}

/**
 * Envia email de atualização de estado da encomenda (SMTP via mail_config.local.php no servidor).
 *
 * @return array{ok: bool, motivo: string, erro_detalhe?: string}
 */
function enviar_email_estado_encomenda($email_cliente, $id_encomenda, $estado_anterior, $novo_estado)
{
    $root = dirname(__DIR__, 2);
    $mailConfig = require __DIR__ . '/../config/mail_config.php';

    if (!is_string($email_cliente) || trim($email_cliente) === '') {
        error_log('[EMAIL] Destino vazio — encomenda #'.(int) $id_encomenda);
        sc_email_audit_log('email_destino_invalido', ['encomenda_id' => (int) $id_encomenda]);

        return ['ok' => false, 'motivo' => 'email_destino_invalido'];
    }

    if (empty($mailConfig['enabled']) || empty($mailConfig['smtp_password']) || empty($mailConfig['from_email'])) {
        error_log(
            '[EMAIL] Envio desativado ou config incompleta — no servidor cria src/config/mail_config.local.php '
            .'(vê mail_config.local.example.php) com enabled=true, smtp_password e from_email.'
        );
        sc_email_audit_log('smtp_nao_configurado', [
            'encomenda_id' => (int) $id_encomenda,
            'enabled' => !empty($mailConfig['enabled']),
            'tem_password' => !empty($mailConfig['smtp_password']),
            'tem_from' => !empty($mailConfig['from_email']),
        ]);

        return ['ok' => false, 'motivo' => 'smtp_nao_configurado'];
    }

    require_once $root . '/PHPMailer/src/Exception.php';
    require_once $root . '/PHPMailer/src/PHPMailer.php';
    require_once $root . '/PHPMailer/src/SMTP.php';

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $mailConfig['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailConfig['smtp_username'];
        $mail->Password = $mailConfig['smtp_password'];
        $mail->SMTPSecure = ($mailConfig['smtp_secure'] ?? 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) ($mailConfig['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name'] ?? 'Sweet Cakes');
        $mail->addAddress($email_cliente);

        $mail->isHTML(true);
        $mail->Subject = "Atualização da tua encomenda #{$id_encomenda}";

        $formatStatus = static function ($status) {
            $map = [
                'pendente' => 'Pendente',
                'aceite' => 'Aceite',
                'em_preparacao' => 'Em Preparacao',
                'pronta' => 'Pronta',
                'entregue' => 'Entregue',
                'cancelada' => 'Cancelada',
            ];
            $key = strtolower(trim((string) $status));
            return $map[$key] ?? (string) $status;
        };
        $estadoAnteriorTxt = htmlspecialchars($formatStatus($estado_anterior), ENT_QUOTES, 'UTF-8');
        $novoEstadoTxt = htmlspecialchars($formatStatus($novo_estado), ENT_QUOTES, 'UTF-8');

        $mail->Body = "
        <div style='margin:0;padding:24px;background:#f6f4f1;font-family:Verdana,Arial,sans-serif;color:#2f2a25;'>
          <div style='max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #ece7e2;border-radius:16px;overflow:hidden;'>
            <div style='background:linear-gradient(135deg,#d4a574 0%,#b18456 100%);padding:28px 24px;text-align:center;color:#fff;'>
              <div style='font-size:28px;line-height:1;'>🍰</div>
              <h1 style='margin:10px 0 4px;font-size:24px;'>Sweet Cakes</h1>
              <p style='margin:0;font-size:14px;opacity:.95;'>Atualizacao do estado da encomenda</p>
            </div>

            <div style='padding:28px 24px 22px;'>
              <p style='margin:0 0 12px;font-size:16px;'>O estado da tua encomenda <strong>#{$id_encomenda}</strong> foi atualizado com sucesso.</p>

              <div style='margin:16px 0 20px;border:1px solid #eee7df;border-radius:12px;overflow:hidden;'>
                <table role='presentation' style='width:100%;border-collapse:collapse;'>
                  <tr>
                    <td style='padding:12px 14px;background:#faf7f3;font-size:13px;color:#6d6256;width:45%;'>Estado anterior</td>
                    <td style='padding:12px 14px;font-size:14px;font-weight:600;color:#5f554b;'>{$estadoAnteriorTxt}</td>
                  </tr>
                  <tr>
                    <td style='padding:12px 14px;background:#faf7f3;font-size:13px;color:#6d6256;'>Novo estado</td>
                    <td style='padding:12px 14px;font-size:14px;font-weight:700;color:#1b7f42;'>{$novoEstadoTxt}</td>
                  </tr>
                </table>
              </div>

              <div style='background:#fff9ef;border:1px solid #f2e5cf;border-radius:10px;padding:12px 14px;font-size:13px;color:#6d5e4b;'>
                Se tiveres alguma questao, responde a este email e a nossa equipa ajuda-te rapidamente.
              </div>
            </div>

            <div style='padding:14px 24px 20px;border-top:1px solid #f2ede7;text-align:center;font-size:12px;color:#8a7f73;'>
              Obrigado pela tua preferencia • Sweet Cakes
            </div>
          </div>
        </div>
        ";

        $mail->AltBody = "Sweet Cakes - Encomenda #{$id_encomenda}\nEstado anterior: ".$formatStatus($estado_anterior)."\nNovo estado: ".$formatStatus($novo_estado)."\nObrigado pela tua preferencia.";

        $mail->send();

        sc_email_audit_log('enviado', [
            'encomenda_id' => (int) $id_encomenda,
            'para' => $email_cliente,
            'de' => $mailConfig['from_email'],
        ]);

        return ['ok' => true, 'motivo' => 'enviado'];
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        error_log('[EMAIL_HELPER] Erro: '.$msg);
        sc_email_audit_log('erro_smtp', [
            'encomenda_id' => (int) $id_encomenda,
            'para' => $email_cliente,
            'erro' => $msg,
        ]);

        $curto = preg_replace('/\s+/', ' ', $msg);
        if (function_exists('mb_substr')) {
            $curto = mb_substr($curto, 0, 280, 'UTF-8');
        } else {
            $curto = substr($curto, 0, 280);
        }

        return ['ok' => false, 'motivo' => 'erro_smtp', 'erro_detalhe' => $curto];
    }
}
