<?php
/**
 * Chave que os agentes usam para enviar dados ao painel (api/agent_ingest.php).
 *
 * - É a MESMA chave que está em agent/config.ini, na linha "chave".
 * - Ela só serve para ENVIAR dados de monitoramento; não dá acesso ao banco
 *   nem ao painel. Os computadores não precisam mais da senha do banco.
 * - Para trocar: gere uma chave nova (30+ caracteres), coloque aqui e em
 *   todos os agent/config.ini. Em produção prefira a variável de ambiente
 *   SYSMON_AGENT_KEY em vez de deixar a chave escrita no arquivo.
 * - Não envie este arquivo para repositórios públicos.
 */
return [
    'key' => getenv('SYSMON_AGENT_KEY') ?: 'wxbZcO4dN1xNlEBee_Rtm7xYz6zeia61jCZe3ac8zOU',
];
