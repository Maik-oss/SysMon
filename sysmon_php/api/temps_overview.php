<?php
require_once '../includes/data.php';
require_auth();

json_response([
    'ok'        => true,
    'threshold' => ALERT_THRESHOLD,
    'pcs'       => fetch_all_latest_temperatures(),
]);
