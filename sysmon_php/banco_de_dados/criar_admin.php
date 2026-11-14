<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/data.php';

$admins = [
    ['usuario' => 'maikson', 'senha' => 'ma012064', 'email' => 'maikson.santiago@escolar.ifrn.edu.br'],
    ['usuario' => 'matheus', 'senha' => 'mt303132', 'email' => 'm.gessle@escolar.ifrn.edu.br'],
    ['usuario' => 'samuel',  'senha' => 'sm202122',  'email' => 'samuel.pedrosa@escolar.ifrn.edu.br'],
];

$pdo = get_pdo();
$stmt = $pdo->prepare(
    'INSERT INTO usuarios (usuario, senha_hash, email, ativo)
     VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE senha_hash = VALUES(senha_hash), email = VALUES(email), ativo = 1'
);

foreach ($admins as $admin) {
    $hash = password_hash($admin['senha'], PASSWORD_BCRYPT);
    $stmt->execute([$admin['usuario'], $hash, $admin['email']]);
    echo "Usuário '{$admin['usuario']}' criado/atualizado com sucesso.\n";
}

echo "Agora apague (ou renomeie) este arquivo do servidor por segurança.\n";
