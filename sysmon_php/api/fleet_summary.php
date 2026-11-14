<?php
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

sync_alert_events();

json_response(['ok' => true] + fetch_fleet_summary());
