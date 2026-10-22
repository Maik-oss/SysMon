<?php
/**
 * Envio de e-mail sem bibliotecas externas: cliente SMTP mínimo
 * (SSL/STARTTLS + AUTH LOGIN) com fallback para o mail() do PHP.
 * Configuração em includes/mail_config.php.
 */

/** Descreve como o envio está configurado (para mostrar no painel). */
function mail_driver(): string {
    $cfg = require __DIR__ . '/mail_config.php';
    return $cfg['host'] !== '' ? 'smtp' : 'mail';
}

/**
 * Envia um e-mail (HTML + texto puro).
 * @return array [bool $ok, ?string $erro]
 */
function send_email(string $to, string $subject, string $html, string $text = ''): array {
    $cfg = require __DIR__ . '/mail_config.php';

    // Anti "header injection": nada de quebra de linha em destinatário/assunto.
    $to      = trim(str_replace(["\r", "\n"], '', $to));
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Destinatário inválido.'];
    }
    if ($text === '') {
        $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html))));
    }

    $fromEmail = $cfg['from_email'] !== '' ? $cfg['from_email'] : $cfg['user'];
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $host = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
        $fromEmail = 'sysmon@' . ($host ?: 'localhost');
    }
    $fromName = str_replace(["\r", "\n", '"'], '', $cfg['from_name']);

    try {
        if ($cfg['host'] !== '') {
            smtp_send($cfg, $fromEmail, $fromName, $to, $subject, $html, $text);
            return [true, null];
        }
        $ok = php_mail_send($fromEmail, $fromName, $to, $subject, $html, $text);
        return $ok ? [true, null] : [false, 'A função mail() do PHP recusou o envio (servidor sem e-mail configurado?).'];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// ── Montagem da mensagem MIME ───────────────────────────────────────

function mime_encode_header(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Devolve [headers (string), corpo (string)] da mensagem multipart/alternative. */
function build_mime_parts(string $fromEmail, string $fromName, string $text, string $html): array {
    $boundary = 'sysmon_' . bin2hex(random_bytes(8));
    $from = ($fromName !== '' ? mime_encode_header($fromName) . ' ' : '') . "<{$fromEmail}>";

    $headers = "From: {$from}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text), 76, "\r\n")
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html), 76, "\r\n")
          . "--{$boundary}--\r\n";

    return [$headers, $body];
}

function php_mail_send(string $fromEmail, string $fromName, string $to, string $subject, string $html, string $text): bool {
    [$headers, $body] = build_mime_parts($fromEmail, $fromName, $text, $html);
    return @mail($to, mime_encode_header($subject), $body, $headers);
}

// ── Cliente SMTP ────────────────────────────────────────────────────

function smtp_read($fp): array {
    $data = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $data .= $line;
        // Resposta multilinha: "250-..." continua; "250 ..." encerra.
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    if ($data === '') {
        throw new RuntimeException('SMTP: o servidor não respondeu (tempo esgotado).');
    }
    return [(int) substr($data, 0, 3), trim($data)];
}

function smtp_cmd($fp, string $cmd, array $okCodes, ?string $logLabel = null): string {
    fwrite($fp, $cmd . "\r\n");
    [$code, $data] = smtp_read($fp);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException('SMTP: "' . ($logLabel ?? $cmd) . '" recusado — ' . $data);
    }
    return $data;
}

function smtp_send(array $cfg, string $fromEmail, string $fromName, string $to, string $subject, string $html, string $text): void {
    $secure = strtolower($cfg['secure']);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int) $cfg['port'];

    $fp = @stream_socket_client($remote, $errno, $errstr, 10);
    if (!$fp) {
        throw new RuntimeException("SMTP: não foi possível conectar em {$cfg['host']}:{$cfg['port']} ({$errstr})");
    }
    stream_set_timeout($fp, 10);

    try {
        [$code, $data] = smtp_read($fp);
        if ($code !== 220) {
            throw new RuntimeException('SMTP: saudação inesperada — ' . $data);
        }

        $ehloName = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
        smtp_cmd($fp, "EHLO {$ehloName}", [250]);

        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP: falha ao iniciar a criptografia TLS.');
            }
            smtp_cmd($fp, "EHLO {$ehloName}", [250]);
        }

        if ($cfg['user'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode($cfg['user']), [334], 'usuário SMTP');
            smtp_cmd($fp, base64_encode($cfg['password']), [235], 'senha SMTP (autenticação)');
        }

        smtp_cmd($fp, "MAIL FROM:<{$fromEmail}>", [250]);
        smtp_cmd($fp, "RCPT TO:<{$to}>", [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);

        [$headers, $body] = build_mime_parts($fromEmail, $fromName, $text, $html);
        $message = $headers . "\r\n"
                 . 'To: <' . $to . ">\r\n"
                 . 'Subject: ' . mime_encode_header($subject) . "\r\n"
                 . 'Date: ' . date('r') . "\r\n"
                 . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $ehloName . ">\r\n"
                 . "\r\n" . $body;
        // "Dot-stuffing": linha começando com "." precisa virar "..".
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($fp, $message . "\r\n.\r\n");
        [$code, $data] = smtp_read($fp);
        if ($code !== 250) {
            throw new RuntimeException('SMTP: mensagem recusada — ' . $data);
        }
        @smtp_cmd($fp, 'QUIT', [221]);
    } finally {
        @fclose($fp);
    }
}
