<?php
// Admin redefine a senha de um usuário que ele mesmo cadastrou. Diferente
// de api/change_password.php (o próprio usuário trocando a senha dele),
// aqui não se pede a senha atual da outra pessoa — quem está autenticado
// como admin tem esse poder.
require_once '../includes/data.php';
require_auth();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$usuario = trim($body['usuario'] ?? '');
$senha   = $body['senha'] ?? '';

if ($usuario === '' || $senha === '') {
    json_response(['ok' => false, 'error' => 'Preencha todos os campos.'], 400);
}
if (strlen($senha) < 6) {
    json_response(['ok' => false, 'error' => 'A nova senha precisa ter pelo menos 6 caracteres.'], 400);
}
if (is_admin_username($usuario)) {
    json_response(['ok' => false, 'error' => 'Use a tela "Conta" para alterar a sua própria senha.'], 400);
}

$pdo   = get_pdo();
$check = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
$check->execute([$usuario]);
if (!$check->fetch()) {
    json_response(['ok' => false, 'error' => 'Usuário não encontrado.'], 404);
}

$hash = password_hash($senha, PASSWORD_BCRYPT);
$upd  = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE usuario = ?');
$upd->execute([$hash, $usuario]);

json_response(['ok' => true]);
