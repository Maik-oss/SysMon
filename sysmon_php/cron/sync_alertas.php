<?php
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
