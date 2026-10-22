<?php
/**
 * Confere o estado de todos os computadores e dispara as notificações
 * (painel + e-mail), SEM precisar de alguém com o painel aberto.
 *
 * Sem isto, o sistema só percebe um problema (e só manda e-mail) quando
 * alguém abre o painel. Agende para rodar a cada 1–5 minutos:
 *
 *   Linux (crontab -e), a cada 2 minutos — minuto = "asterisco/2":
 *     ver exemplo completo no README ("Notificações por e-mail").
 *
 *   Painel de hospedagem que só chama URL: configure 'cron_token' em
 *   includes/mail_config.php e agende
 *   https://SEU_SITE/cron/sync_alertas.php?token=SEU_TOKEN
 */
require_once __DIR__ . '/../includes/data.php';

if (PHP_SAPI !== 'cli') {
    $cfg   = require __DIR__ . '/../includes/mail_config.php';
    $token = (string) ($_GET['token'] ?? '');
    if ($cfg['cron_token'] === '' || !hash_equals($cfg['cron_token'], $token)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

sync_alert_events();
echo 'ok ' . date('Y-m-d H:i:s') . "\n";
