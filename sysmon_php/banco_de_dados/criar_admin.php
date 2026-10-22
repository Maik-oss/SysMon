<?php
/**
 * Script de configuração inicial — roda UMA VEZ para criar (ou resetar)
 * o usuário do painel, com a senha corretamente cifrada (bcrypt).
 *
 * Por que isso existe? A senha precisa ser cifrada com password_hash(),
 * função do PHP. Gerar esse hash "à mão" fora do seu servidor real
 * seria arriscado (poderia não bater com a senha desejada), então este
 * script gera o hash no seu próprio ambiente, na hora.
 *
 * COMO USAR:
 *   1) Rode "schema.sql" no banco (cria as tabelas).
 *   2) Ajuste $usuario e $senha abaixo, se quiser outro usuário/senha.
 *   3) Execute este arquivo UMA VEZ:
 *        - via terminal:  php criar_admin.php
 *        - ou pelo navegador: http://SEU_SITE/banco_de_dados/criar_admin.php
 *   4) IMPORTANTE: depois de rodar, apague este arquivo do servidor
 *      (ou mova para fora da pasta pública), para ninguém mais poder
 *      recriar/resetar o admin remotamente.
 */

require_once __DIR__ . '/../includes/db.php';
// db.php chama json_response() em caso de erro, então garantimos que
// includes/data.php (onde a função vive) também esteja carregado:
require_once __DIR__ . '/../includes/data.php';

// Lista de administradores do painel — usuário/senha em texto visível
// aqui apenas para referência de quem configurou o sistema. A senha é
// sempre salva no banco já cifrada (bcrypt), nunca em texto puro.
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
