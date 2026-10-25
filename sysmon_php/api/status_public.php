<?php
/**
 * Endpoint público (sem autenticação) — só devolve a CONTAGEM de
 * computadores por status, nunca nomes/local/detalhes. Usado na tela
 * de login para mostrar números reais em vez dos "3/1/1" fixos do
 * template original.
 */
require_once '../includes/data.php';

$computers = fetch_computers();

$counts = ['online' => 0, 'warning' => 0, 'offline' => 0];
foreach ($computers as $info) {
    if ($info['sem_agente']) { continue; }   // ainda sem agente: não conta como offline
    $counts[$info['status']]++;
}

json_response($counts);
