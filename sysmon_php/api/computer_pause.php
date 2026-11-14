<?php
require_once '../includes/data.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$codigo = strtoupper(trim($body['codigo'] ?? ''));
if ($codigo === '') {
    json_response(['ok' => false, 'error' => 'Informe o código do computador.'], 400);
}
$pausar = !empty($body['pausar']);

$pdo = get_pdo();
$chk = $pdo->prepare('SELECT 1 FROM computadores WHERE codigo = ?');
$chk->execute([$codigo]);
if (!$chk->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Computador não encontrado.'], 404);
}

$usuario = (string) ($_SESSION['user'] ?? '');
if ($pausar) {
    $pdo->prepare('UPDATE computadores SET coleta_pausada = 1, pausada_em = NOW(), pausada_por = ?,
                          last_status = ? WHERE codigo = ?')
        ->execute([$usuario, 'pausado', $codigo]);
        $pdo->prepare('UPDATE eventos SET ativo = 0, resolvido_em = NOW() WHERE codigo = ? AND ativo = 1')
        ->execute([$codigo]);
} else {
    $pdo->prepare('UPDATE computadores SET coleta_pausada = 0, pausada_em = NULL, pausada_por = NULL
                   WHERE codigo = ?')
        ->execute([$codigo]);
}

$r = $pdo->prepare('SELECT coleta_pausada, pausada_em, pausada_por FROM computadores WHERE codigo = ?');
$r->execute([$codigo]);
$row = $r->fetch();
json_response([
    'ok'          => true,
    'pausada'     => (int) $row['coleta_pausada'] === 1,
    'pausada_em'  => $row['pausada_em'],
    'pausada_por' => $row['pausada_por'],
]);
