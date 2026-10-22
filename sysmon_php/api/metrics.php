<?php
require_once '../includes/data.php';
require_auth();

$code = strtoupper($_GET['code'] ?? '');

$info = fetch_computer($code);
if ($info === null) {
    json_response(['error' => 'Não encontrado.'], 404);
}
if ($info['status'] === 'offline') {
    json_response(['offline' => true]);
}

$m = fetch_latest_metric($code);
if ($m === null) {
    json_response(['offline' => true]);
}

$cores = json_decode($m['cpu_cores'] ?? '[]', true);
if (!is_array($cores)) {
    $cores = [];
}

$ramPct  = ((float)$m['ram_total']  > 0) ? round((float)$m['ram_used']  / (float)$m['ram_total']  * 100, 1) : 0.0;
$diskPct = ((float)$m['disk_total'] > 0) ? round((float)$m['disk_used'] / (float)$m['disk_total'] * 100, 1) : 0.0;
$psuPct  = ((float)$m['psu_max']    > 0) ? round((float)$m['psu_watts'] / (float)$m['psu_max']    * 100, 1) : 0.0;

json_response([
    'cpu' => [
        'usage' => (float)$m['cpu_usage'],
        'temp'  => $m['cpu_temp']  !== null ? (float)$m['cpu_temp']  : null,
        'cores' => $cores,
        'model' => $m['cpu_model'],
        'freq'  => $m['cpu_freq']  !== null ? (float)$m['cpu_freq']  : null,
    ],
    'gpu' => [
        'usage'    => $m['gpu_usage']    !== null ? (float)$m['gpu_usage']    : null,
        'temp'     => $m['gpu_temp']     !== null ? (float)$m['gpu_temp']     : null,
        'vram_pct' => $m['gpu_vram_pct'] !== null ? (float)$m['gpu_vram_pct'] : null,
        'model'    => $m['gpu_model'],
    ],
    'ram' => [
        'used'  => (float)$m['ram_used'],
        'total' => (float)$m['ram_total'],
        'pct'   => $ramPct,
    ],
    'disk' => [
        'used'  => (float)$m['disk_used'],
        'total' => (float)$m['disk_total'],
        'pct'   => $diskPct,
    ],
    'psu' => [
        'watts' => $m['psu_watts'] !== null ? (float)$m['psu_watts'] : null,
        'max'   => $m['psu_max']   !== null ? (float)$m['psu_max']   : null,
        'pct'   => $psuPct,
        'temp'  => $m['psu_temp']  !== null ? (float)$m['psu_temp']  : null,
    ],
    'net' => [
        'down' => (float)$m['net_down'],
        'up'   => (float)$m['net_up'],
    ],
    'uptime_h'  => (int)$m['uptime_h'],
    'timestamp' => strtotime($m['criado_em']),
]);
