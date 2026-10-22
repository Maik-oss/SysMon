<?php
// Notificações / histórico de problemas. Qualquer usuário logado pode
// ver (não só admin) — cada um marca como lida pra si mesmo.
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Sempre confere o estado atual antes de responder, assim a lista
    // (e o contador do sininho) fica em dia mesmo sem cron configurado.
    sync_alert_events();

    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $events = fetch_events($limit);

    json_response([
        'ok'       => true,
        'unread'   => count_unread_events(),
        'critico'  => fetch_critical_summary(),
        'events'   => array_map(function ($e) {
            return [
                'id'        => (int) $e['id'],
                'codigo'    => $e['codigo'],
                'label'     => $e['computador_label'],
                'tipo'      => $e['tipo'],
                'nivel'     => $e['nivel'],
                'mensagem'  => $e['mensagem'],
                'ativo'     => (bool) $e['ativo'],
                'lida'      => (bool) $e['visualizado'],
                'criado_em' => $e['criado_em'],
                'timestamp' => strtotime($e['criado_em']),
            ];
        }, $events),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $acao = $body['acao'] ?? '';

    if ($acao === 'marcar_lida') {
        $id = isset($body['id']) ? (int) $body['id'] : null;
        if ($id === null) {
            json_response(['ok' => false, 'error' => 'Informe o id.'], 400);
        }
        mark_events_read($id, $_SESSION['user']);
        json_response(['ok' => true]);
    }

    if ($acao === 'marcar_todas_lidas') {
        mark_events_read(null, $_SESSION['user']);
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Ação inválida.'], 400);
}

json_response(['error' => 'Método não permitido.'], 405);
