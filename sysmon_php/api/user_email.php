<?php
// Admin cadastra/altera o e-mail de um usuário que ele mesmo gerencia
// (o e-mail dos próprios admins é alterado por eles na tela Configurações).
require_once '../includes/data.php';
require_auth();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$usuario = trim($body['usuario'] ?? '');
$email   = normalize_email($body['email'] ?? '');

if ($usuario === '' || is_admin_username($usuario)) {
    json_response(['ok' => false, 'error' => 'Usuário inválido.'], 400);
}
if ($email === null) {
    json_response(['ok' => false, 'error' => 'Informe um e-mail válido.'], 400);
}

$pdo = get_pdo();
$check = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
$check->execute([$usuario]);
if (!$check->fetch()) {
    json_response(['ok' => false, 'error' => 'Usuário não encontrado.'], 404);
}

$upd = $pdo->prepare('UPDATE usuarios SET email = ? WHERE usuario = ?');
$upd->execute([$email, $usuario]);

json_response(['ok' => true, 'email' => $email]);
