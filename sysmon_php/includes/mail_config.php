<?php
/**
 * Configuração de envio de e-mail (notificações do SysMon).
 *
 * DUAS FORMAS DE ENVIAR:
 *
 *  1) SMTP (recomendado) — preencha 'host', 'user', 'password' e
 *     'from_email'. Exemplos:
 *       Gmail / Google Workspace:  host smtp.gmail.com, porta 587, secure 'tls'
 *                                  (use uma "senha de app", não a senha normal)
 *       Outlook / Microsoft 365:    host smtp.office365.com, porta 587, secure 'tls'
 *       Provedor da hospedagem:     veja host/porta no painel (geralmente 587 ou 465)
 *
 *  2) mail() do PHP — deixe 'host' vazio. Funciona só se o servidor tiver
 *     envio de e-mail configurado (comum em hospedagem compartilhada);
 *     os e-mails podem cair no spam.
 *
 * Em produção prefira definir as variáveis de ambiente (SYSMON_SMTP_HOST,
 * SYSMON_SMTP_USER, SYSMON_SMTP_PASSWORD, ...) em vez de gravar a senha
 * aqui no código.
 *
 * Depois de configurar, use o botão "Enviar e-mail de teste" em
 * Configurações para conferir se está funcionando.
 */
$envSecure = getenv('SYSMON_SMTP_SECURE');   // vazio/"none" = sem criptografia

return [
    'host'       => getenv('SYSMON_SMTP_HOST')     ?: 'smtp.gmail.com',          // ex.: 'smtp.gmail.com'
    'port'       => (int) (getenv('SYSMON_SMTP_PORT') ?: 587),
    'secure'     => $envSecure === false ? 'tls' : ($envSecure === 'none' ? '' : $envSecure), // 'tls' (STARTTLS, porta 587) | 'ssl' (porta 465) | '' (sem criptografia)
    'user'       => getenv('SYSMON_SMTP_USER')     ?: 'sysmon.alertas@gmail.com',
    'password'   => getenv('SYSMON_SMTP_PASSWORD') ?: 'fesajylqbbpbrhfk',
    'from_email' => getenv('SYSMON_MAIL_FROM')     ?: 'sysmon.alertas@gmail.com',          // ex.: 'sysmon@seudominio.com.br' (se vazio, usa 'user')
    'from_name'  => getenv('SYSMON_MAIL_FROM_NAME') ?: 'SysMon',

    // Token para chamar cron/sync_alertas.php por URL (opcional — veja README).
    'cron_token' => getenv('SYSMON_CRON_TOKEN')    ?: '',
];
