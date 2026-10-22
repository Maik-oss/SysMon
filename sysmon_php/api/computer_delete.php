<?php
// Remove um computador do painel (e, por causa do ON DELETE CASCADE
// no banco, todo o histórico de métricas/temperaturas dele junto).
// Só admins podem fazer isso — ver ADMIN_USERNAMES em includes/data.php.
require_once '../includes/data.php';
require_auth();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$codigo = strtoupper(trim($body['codigo'] ?? ''));

if ($codigo === '') {
    json_response(['ok' => false, 'error' => 'Informe o código do computador.'], 400);
}

$pdo = get_pdo();
$stmt = $pdo->prepare('DELETE FROM computadores WHERE codigo = ?');
$stmt->execute([$codigo]);

if ($stmt->rowCount() === 0) {
    json_response(['ok' => false, 'error' => 'Computador não encontrado.'], 404);
}

json_response(['ok' => true]);
