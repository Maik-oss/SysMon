<?php
require_once '../includes/data.php';
require_auth();

$code = strtoupper($_GET['code'] ?? '');
if ($code === '') {
    json_response(['error' => 'Informe o código do computador.'], 400);
}

$info = fetch_computer($code);
if ($info === null) {
    json_response(['error' => 'Não encontrado.'], 404);
}

$limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
$pontos = fetch_temperature_history($code, $limit);

json_response([
    'ok'        => true,
    'threshold' => ALERT_THRESHOLD,
    'pontos'    => array_map(function ($p) {
        return [
            'cpu_package' => $p['cpu_package'] !== null ? (float) $p['cpu_package'] : null,
            'gpu_die'     => $p['gpu_die']     !== null ? (float) $p['gpu_die']     : null,
            'motherboard' => $p['motherboard'] !== null ? (float) $p['motherboard'] : null,
            'timestamp'   => strtotime($p['criado_em']),
        ];
    }, $pontos),
]);
