<?php
require_once '../includes/data.php';
require_auth();

sync_alert_events();

$code = $_GET['code'] ?? null;
$q    = strtoupper($_GET['q'] ?? '');

if ($code !== null) {
    $code = strtoupper($code);
    $info = fetch_computer($code);
    if ($info === null) {
        json_response(['error' => 'Computador não encontrado.'], 404);
    }
    json_response(array_merge(['code' => $code], $info));
}

$computers = fetch_computers();
$results = [];
foreach ($computers as $c => $info) {
    $texto = strtoupper($info['label'] . ' ' . $info['patrimonio'] . ' ' . $info['sala'] . ' ' . $info['modelo']);
    if ($q === '' || strpos($c, $q) !== false || strpos($texto, $q) !== false) {
        $results[] = array_merge(['code' => $c], $info);
    }
}
json_response($results);
