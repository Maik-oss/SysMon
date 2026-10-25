<?php
// Plantas do campus com as bolinhas de status por sala.
//   GET  → plantas, marcadores (com cor e contagens ao vivo) e salas sem posição.
//          Qualquer usuário logado pode consultar.
//   POST → só administradores editam as bolinhas:
//          {acao:"salvar", id?, planta, nome, x, y, salas:[...]}  cria/atualiza
//          {acao:"mover",  id, x, y}                              arrastar a bolinha
//          {acao:"excluir", id}                                   remove a bolinha
require_once '../includes/plantas.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Mantém os alertas em dia antes de contar (igual ao Monitoramento Geral).
    sync_alert_events();
    json_response(['ok' => true, 'admin' => is_admin()] + plantas_payload());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $acao = $body['acao'] ?? '';

    if ($acao === 'salvar') {
        $r = plantas_salvar_marcador($body);
        $status = $r['status'] ?? 200;
        unset($r['status']);
        json_response($r, $status);
    }

    if ($acao === 'mover') {
        if (!plantas_mover_marcador((int) ($body['id'] ?? 0), (float) ($body['x'] ?? -1), (float) ($body['y'] ?? -1))) {
            json_response(['ok' => false, 'error' => 'Marcador não encontrado ou posição inválida.'], 400);
        }
        json_response(['ok' => true]);
    }

    if ($acao === 'excluir') {
        if (!plantas_excluir_marcador((int) ($body['id'] ?? 0))) {
            json_response(['ok' => false, 'error' => 'Marcador não encontrado.'], 404);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Ação inválida.'], 400);
}

json_response(['error' => 'Método não permitido.'], 405);
