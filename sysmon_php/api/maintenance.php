<?php
// Histórico de manutenção de um computador: quando foi feita e por quem
// foi registrada. Qualquer usuário logado pode consultar e registrar;
// só admins podem excluir um registro.
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = strtoupper(trim($_GET['code'] ?? ''));
    if ($code === '' || fetch_computer($code) === null) {
        json_response(['ok' => false, 'error' => 'Computador não encontrado.'], 404);
    }

    json_response([
        'ok'     => true,
        'tipos'  => MAINTENANCE_TYPES,
        'admin'  => is_admin(),
        'itens'  => array_map(function ($m) {
            return [
                'id'             => (int) $m['id'],
                'tipo'           => $m['tipo'],
                'tipo_label'     => MAINTENANCE_TYPES[$m['tipo']] ?? $m['tipo'],
                'descricao'      => $m['descricao'],
                'realizado_em'   => $m['realizado_em'],
                'registrado_por' => $m['registrado_por'],
                'criado_em'      => $m['criado_em'],
            ];
        }, fetch_maintenance($code)),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $acao = $body['acao'] ?? '';

    if ($acao === 'criar') {
        $codigo    = strtoupper(trim($body['codigo'] ?? ''));
        $tipo      = $body['tipo'] ?? '';
        $descricao = trim($body['descricao'] ?? '');
        $quando    = trim($body['realizado_em'] ?? '');

        if ($codigo === '' || fetch_computer($codigo) === null) {
            json_response(['ok' => false, 'error' => 'Computador não encontrado.'], 404);
        }
        if (!isset(MAINTENANCE_TYPES[$tipo])) {
            json_response(['ok' => false, 'error' => 'Escolha o tipo de manutenção.'], 400);
        }
        if (mb_strlen($descricao) < 3) {
            json_response(['ok' => false, 'error' => 'Descreva o que foi feito (mínimo 3 caracteres).'], 400);
        }
        if (mb_strlen($descricao) > 1000) {
            json_response(['ok' => false, 'error' => 'A descrição pode ter no máximo 1000 caracteres.'], 400);
        }

        // Aceita "2026-10-21T14:30" (campo datetime-local) ou "2026-10-21 14:30[:00]".
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $quando)
           ?: DateTime::createFromFormat('Y-m-d H:i:s', $quando)
           ?: DateTime::createFromFormat('Y-m-d H:i', $quando);
        if (!$dt) {
            json_response(['ok' => false, 'error' => 'Informe a data e a hora da manutenção.'], 400);
        }
        // Tolera pequenas diferenças de relógio, mas não aceita data no futuro.
        if ($dt->getTimestamp() > time() + 3600) {
            json_response(['ok' => false, 'error' => 'A data da manutenção não pode estar no futuro.'], 400);
        }

        $id = add_maintenance($codigo, $tipo, $descricao, $dt->format('Y-m-d H:i:s'), $_SESSION['user']);
        json_response(['ok' => true, 'id' => $id]);
    }

    if ($acao === 'excluir') {
        require_admin();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0 || !delete_maintenance($id)) {
            json_response(['ok' => false, 'error' => 'Registro não encontrado.'], 404);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Ação inválida.'], 400);
}

json_response(['error' => 'Método não permitido.'], 405);
