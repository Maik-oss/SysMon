<?php
require_once '../includes/data.php';

$computers = fetch_computers();

$counts = ['online' => 0, 'warning' => 0, 'offline' => 0];
foreach ($computers as $info) {
    if ($info['sem_agente'] || $info['pausada']) { continue; }       $counts[$info['status']]++;
}

json_response($counts);
