<?php
return [
    'host'     => getenv('SYSMON_DB_HOST')     ?: 'piprojeto2.mysql.dbaas.com.br',
    'port'     => getenv('SYSMON_DB_PORT')     ?: 3306,
    'dbname'   => getenv('SYSMON_DB_NAME')     ?: 'piprojeto2',
    'user'     => getenv('SYSMON_DB_USER')     ?: 'piprojeto2',
    'password' => getenv('SYSMON_DB_PASSWORD') ?: 'Ifrn@c0p42k6##',
];
