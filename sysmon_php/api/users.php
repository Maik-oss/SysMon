<?php
// Cadastro/listagem de usuários do painel. Só os admins (ver
// ADMIN_USERNAMES em includes/data.php) podem acessar este endpoint.
require_once '../includes/data.php';
require_auth();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'users' => fetch_managed_users()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $usuario = trim($body['usuario'] ?? '');
    $senha   = $body['senha'] ?? '';
    $email   = normalize_email($body['email'] ?? '');

    if ($usuario === '' || $senha === '') {
        json_response(['ok' => false, 'error' => 'Preencha usuário e senha.'], 400);
    }
    // E-mail é obrigatório para todo usuário novo (é por ele que a pessoa
    // recebe as notificações do sistema).
    if ($email === null) {
        json_response(['ok' => false, 'error' => 'Informe um e-mail válido para o usuário.'], 400);
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario)) {
        json_response(['ok' => false, 'error' => 'Usuário deve ter entre 3 e 50 caracteres (letras, números, ".", "_" ou "-").'], 400);
    }
    if (strlen($senha) < 6) {
        json_response(['ok' => false, 'error' => 'A senha precisa ter pelo menos 6 caracteres.'], 400);
    }
    if (is_admin_username($usuario)) {
        json_response(['ok' => false, 'error' => 'Esse nome de usuário é reservado.'], 409);
    }

    $pdo = get_pdo();
    $check = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
    $check->execute([$usuario]);
    if ($check->fetch()) {
        json_response(['ok' => false, 'error' => 'Esse nome de usuário já está em uso.'], 409);
    }

    $hash = password_hash($senha, PASSWORD_BCRYPT);
    $ins = $pdo->prepare('INSERT INTO usuarios (usuario, senha_hash, email, criado_por) VALUES (?, ?, ?, ?)');
    $ins->execute([$usuario, $hash, $email, $_SESSION['user']]);

    json_response(['ok' => true]);
}

json_response(['error' => 'Método não permitido.'], 405);
