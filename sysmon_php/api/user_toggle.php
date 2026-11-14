<?php
require_once '../includes/data.php';
require_auth();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$usuario = trim($body['usuario'] ?? '');

if ($usuario === '' || is_admin_username($usuario)) {
    json_response(['ok' => false, 'error' => 'Usuário inválido.'], 400);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT ativo FROM usuarios WHERE usuario = ?');
$stmt->execute([$usuario]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'error' => 'Usuário não encontrado.'], 404);
}

$novoStatus = $row['ativo'] ? 0 : 1;
$upd = $pdo->prepare('UPDATE usuarios SET ativo = ? WHERE usuario = ?');
$upd->execute([$novoStatus, $usuario]);

json_response(['ok' => true, 'ativo' => (bool) $novoStatus]);
