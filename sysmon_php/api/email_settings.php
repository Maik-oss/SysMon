<?php
// E-mail do próprio usuário logado: cadastrar/alterar, ligar/desligar as
// notificações por e-mail e mandar um e-mail de teste.
require_once '../includes/data.php';
require_once '../includes/mailer.php';
require_auth();

$pdo     = get_pdo();
$usuario = $_SESSION['user'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    seed_admin_emails($pdo);
    $stmt = $pdo->prepare('SELECT email, notificar_email FROM usuarios WHERE usuario = ?');
    $stmt->execute([$usuario]);
    $row = $stmt->fetch() ?: ['email' => null, 'notificar_email' => 1];

    json_response([
        'ok'        => true,
        'email'     => $row['email'] ?: '',
        'notificar' => (bool) $row['notificar_email'],
        'driver'    => mail_driver(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $acao = $body['acao'] ?? '';

    if ($acao === 'salvar') {
        $email = normalize_email($body['email'] ?? '');
        if ($email === null) {
            json_response(['ok' => false, 'error' => 'Informe um e-mail válido.'], 400);
        }
        $notificar = !empty($body['notificar']) ? 1 : 0;

        $upd = $pdo->prepare('UPDATE usuarios SET email = ?, notificar_email = ? WHERE usuario = ?');
        $upd->execute([$email, $notificar, $usuario]);
        json_response(['ok' => true, 'email' => $email, 'notificar' => (bool) $notificar]);
    }

    if ($acao === 'testar') {
        $stmt = $pdo->prepare('SELECT email FROM usuarios WHERE usuario = ?');
        $stmt->execute([$usuario]);
        $email = (string) $stmt->fetchColumn();
        if ($email === '') {
            json_response(['ok' => false, 'error' => 'Cadastre e salve seu e-mail primeiro.'], 400);
        }
        [$ok, $err] = send_email(
            $email,
            '[SysMon] E-mail de teste',
            '<p style="font-family:Arial,sans-serif">Este é um e-mail de teste do <b>SysMon</b>. '
            . 'Se você recebeu, as notificações por e-mail estão funcionando.</p>'
        );
        if (!$ok) {
            error_log('[sysmon] E-mail de teste falhou: ' . $err);
            json_response(['ok' => false, 'error' => 'Não foi possível enviar: ' . $err], 502);
        }
        json_response(['ok' => true, 'email' => $email]);
    }

    json_response(['ok' => false, 'error' => 'Ação inválida.'], 400);
}

json_response(['error' => 'Método não permitido.'], 405);
