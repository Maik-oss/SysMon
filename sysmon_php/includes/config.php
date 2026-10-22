<?php
/**
 * Configuração de conexão com o banco de dados MySQL (DBaaS).
 *
 * IMPORTANTE:
 * - Na maioria dos provedores "*.mysql.dbaas.com.br" (KingHost/Hostgator/UOL Host)
 *   o USUÁRIO do banco é igual ao NOME do banco. Se a conexão falhar com
 *   "Access denied", confira o usuário exato no painel do provedor e ajuste
 *   abaixo.
 * - Em produção, prefira carregar host/usuário/senha de variáveis de ambiente
 *   em vez de deixá-los fixos no código-fonte. Deixei fixo aqui apenas para
 *   simplificar a configuração inicial pedida.
 */
return [
    'host'     => getenv('SYSMON_DB_HOST')     ?: 'piprojeto2.mysql.dbaas.com.br',
    'port'     => getenv('SYSMON_DB_PORT')     ?: 3306,
    'dbname'   => getenv('SYSMON_DB_NAME')     ?: 'piprojeto2',
    'user'     => getenv('SYSMON_DB_USER')     ?: 'piprojeto2',
    'password' => getenv('SYSMON_DB_PASSWORD') ?: 'Ifrn@c0p42k6##',
];
