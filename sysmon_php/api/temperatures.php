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

$t = fetch_latest_temperature($code);
if ($t === null) {
    json_response(['offline' => true]);
}

$cores = json_decode($t['cpu_cores'] ?? '[]', true);
if (!is_array($cores)) {
    $cores = [];
}

json_response([
    'cpu_package' => $t['cpu_package'] !== null ? (float)$t['cpu_package'] : null,
    'cpu_cores'   => $cores,
    'gpu_die'     => $t['gpu_die']     !== null ? (float)$t['gpu_die']     : null,
    'gpu_mem'     => $t['gpu_mem']     !== null ? (float)$t['gpu_mem']     : null,
    'nvme_1'      => $t['nvme_1']      !== null ? (float)$t['nvme_1']      : null,
    'nvme_2'      => $t['nvme_2']      !== null ? (float)$t['nvme_2']      : null,
    'psu'         => $t['psu']         !== null ? (float)$t['psu']         : null,
    'motherboard' => $t['motherboard'] !== null ? (float)$t['motherboard'] : null,
    'ambient'     => $t['ambient']     !== null ? (float)$t['ambient']     : null,
    'fan_cpu_rpm' => $t['fan_cpu_rpm'] !== null ? (int)$t['fan_cpu_rpm']   : null,
    'fan_sys_rpm' => $t['fan_sys_rpm'] !== null ? (int)$t['fan_sys_rpm']   : null,
]);
