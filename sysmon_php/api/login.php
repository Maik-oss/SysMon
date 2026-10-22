<?php
require_once '../includes/data.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    json_response(['ok' => false, 'error' => 'Usuário ou senha inválidos.'], 401);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT usuario, senha_hash FROM usuarios WHERE usuario = ? AND ativo = 1');
$stmt->execute([$username]);
$row = $stmt->fetch();

if ($row && password_verify($password, $row['senha_hash'])) {
    session_regenerate_id(true);
    $_SESSION['user'] = $row['usuario'];
    // Garante o e-mail padrão dos admins (só preenche se estiver vazio).
    try { seed_admin_emails($pdo); } catch (Throwable $e) { error_log('[sysmon] seed_admin_emails: ' . $e->getMessage()); }
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Usuário ou senha inválidos.'], 401);
