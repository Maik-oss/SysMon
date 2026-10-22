<?php
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$atual = $body['current_password'] ?? '';
$nova  = $body['new_password'] ?? '';

if ($atual === '' || $nova === '') {
    json_response(['ok' => false, 'error' => 'Preencha todos os campos.'], 400);
}
if (strlen($nova) < 6) {
    json_response(['ok' => false, 'error' => 'A nova senha precisa ter pelo menos 6 caracteres.'], 400);
}

$pdo     = get_pdo();
$usuario = $_SESSION['user'];

$stmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE usuario = ?');
$stmt->execute([$usuario]);
$row = $stmt->fetch();

if (!$row || !password_verify($atual, $row['senha_hash'])) {
    json_response(['ok' => false, 'error' => 'Senha atual incorreta.'], 401);
}

$novoHash = password_hash($nova, PASSWORD_BCRYPT);
$upd = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE usuario = ?');
$upd->execute([$novoHash, $usuario]);

json_response(['ok' => true]);
