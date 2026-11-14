<?php
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

sync_alert_events();

$periodo = ($_GET['periodo'] ?? 'semana') === 'mes' ? 'mes' : 'semana';

json_response(['ok' => true] + fetch_problem_history($periodo));
