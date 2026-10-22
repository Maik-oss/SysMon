<?php
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$novoUsuario = trim($body['new_username'] ?? '');
$senhaAtual  = $body['current_password'] ?? '';

if ($novoUsuario === '' || $senhaAtual === '') {
    json_response(['ok' => false, 'error' => 'Preencha todos os campos.'], 400);
}
if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $novoUsuario)) {
    json_response(['ok' => false, 'error' => 'Usuário deve ter entre 3 e 50 caracteres (letras, números, ".", "_" ou "-").'], 400);
}

$pdo         = get_pdo();
$usuarioAtual = $_SESSION['user'];

$stmt = $pdo->prepare('SELECT id, senha_hash FROM usuarios WHERE usuario = ?');
$stmt->execute([$usuarioAtual]);
$row = $stmt->fetch();

if (!$row || !password_verify($senhaAtual, $row['senha_hash'])) {
    json_response(['ok' => false, 'error' => 'Senha atual incorreta.'], 401);
}

// Já é o mesmo nome de usuário: nada para fazer.
if (strcasecmp($novoUsuario, $usuarioAtual) === 0) {
    json_response(['ok' => true, 'usuario' => $usuarioAtual]);
}

// Garante que o novo nome de usuário ainda não está em uso.
$check = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
$check->execute([$novoUsuario]);
if ($check->fetch()) {
    json_response(['ok' => false, 'error' => 'Esse nome de usuário já está em uso.'], 409);
}

$upd = $pdo->prepare('UPDATE usuarios SET usuario = ? WHERE id = ?');
$upd->execute([$novoUsuario, $row['id']]);

$_SESSION['user'] = $novoUsuario;

json_response(['ok' => true, 'usuario' => $novoUsuario]);
