<?php
require_once '../includes/data.php';
require_auth();

// Confere status/métricas/temperaturas de todo mundo e abre/fecha
// notificações antes de responder — mantém o histórico em dia.
sync_alert_events();

// Extrai código da URL: /api/computers.php?code=CM000001 ou /api/computers.php?search=1&q=...
$code = $_GET['code'] ?? null;
$q    = strtoupper($_GET['q'] ?? '');

// GET /api/computers.php?code=CM000001
if ($code !== null) {
    $code = strtoupper($code);
    $info = fetch_computer($code);
    if ($info === null) {
        json_response(['error' => 'Computador não encontrado.'], 404);
    }
    json_response(array_merge(['code' => $code], $info));
}

// GET /api/computers.php?search=1&q=...
$computers = fetch_computers();
$results = [];
foreach ($computers as $c => $info) {
    $texto = strtoupper($info['label'] . ' ' . $info['patrimonio'] . ' ' . $info['sala'] . ' ' . $info['modelo']);
    if ($q === '' || strpos($c, $q) !== false || strpos($texto, $q) !== false) {
        $results[] = array_merge(['code' => $c], $info);
    }
}
json_response($results);
