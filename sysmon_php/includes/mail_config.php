<?php
$envSecure = getenv('SYSMON_SMTP_SECURE');
return [
    'host'       => getenv('SYSMON_SMTP_HOST')     ?: 'smtp.gmail.com',              'port'       => (int) (getenv('SYSMON_SMTP_PORT') ?: 587),
    'secure'     => $envSecure === false ? 'tls' : ($envSecure === 'none' ? '' : $envSecure),     'user'       => getenv('SYSMON_SMTP_USER')     ?: 'sysmon.alertas@gmail.com',
    'password'   => getenv('SYSMON_SMTP_PASSWORD') ?: 'fesajylqbbpbrhfk',
    'from_email' => getenv('SYSMON_MAIL_FROM')     ?: 'sysmon.alertas@gmail.com',              'from_name'  => getenv('SYSMON_MAIL_FROM_NAME') ?: 'SysMon',

        'cron_token' => getenv('SYSMON_CRON_TOKEN')    ?: '',
];
