<?php
// Situação geral dos computadores (normal / alerta / crítico / offline),
// usada no gráfico de pizza da aba Monitoramento Geral.
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

// Garante que os alertas ativos estão em dia antes de contar.
sync_alert_events();

json_response(['ok' => true] + fetch_fleet_summary());
