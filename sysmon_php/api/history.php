<?php
// Histórico de problemas (semanal ou mensal), montado a partir das
// notificações (tabela eventos). Qualquer usuário logado pode consultar.
//   GET api/history.php?periodo=semana|mes
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

// Garante que o histórico está em dia antes de resumir.
sync_alert_events();

$periodo = ($_GET['periodo'] ?? 'semana') === 'mes' ? 'mes' : 'semana';

json_response(['ok' => true] + fetch_problem_history($periodo));
