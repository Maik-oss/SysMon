<?php
/**
 * Importa a lista de computadores do campus (computadores_ifrn.php, vinda da
 * planilha Lista_de_PCs.pdf) para o painel: patrimônio, sala, modelo e
 * situação. Cada PC vira "CM" + patrimônio (ex.: CM406652) e já aparece na
 * lista, filtrável por sala e modelo, como "Sem agente" até o agente rodar.
 *
 * COMO USAR (uma vez):
 *   1) Suba a pasta banco_de_dados/ atualizada para o servidor.
 *   2) Entre no painel como ADMINISTRADOR e, na mesma janela do navegador,
 *      abra:  https://SEU_SITE/banco_de_dados/importar_computadores.php
 *   3) Leia o resultado na tela. Depois APAGUE este arquivo e o
 *      computadores_ifrn.php do servidor.
 *
 * É seguro rodar de novo: não duplica, só atualiza patrimônio/sala/modelo/
 * situação. (Se você apagou um PC no painel, ele volta ao rodar outra vez.)
 *
 * Computador que o agente já cadastrou com outro código (ex.: pelo nome do
 * Windows): informe abaixo a qual patrimônio ele corresponde.
 */
$VINCULOS = [
    // 'CODIGO_JA_CADASTRADO' => 'PATRIMONIO',
    // exemplo: 'DESKTOP-ABC123' => '406652',
];

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/data.php';

if (PHP_SAPI !== 'cli') {
    require_auth();
    require_admin();
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo   = get_pdo();
$lista = require __DIR__ . '/computadores_ifrn.php';

// Garante as colunas novas mesmo se o painel ainda não as criou sozinho.
foreach (['patrimonio' => 'VARCHAR(10)', 'sala' => 'VARCHAR(100)',
          'modelo' => 'VARCHAR(150)', 'situacao' => 'VARCHAR(30)'] as $col => $tipo) {
    $st = $pdo->query("SHOW COLUMNS FROM computadores LIKE '{$col}'");
    if (!$st->fetch()) {
        $pdo->exec("ALTER TABLE computadores ADD COLUMN {$col} {$tipo} DEFAULT NULL");
        echo "Coluna criada: {$col}\n";
    }
}

// Já cadastrados (pelo agente ou por importação anterior).
$existentes = [];
foreach ($pdo->query('SELECT codigo, patrimonio FROM computadores') as $r) {
    $existentes[$r['codigo']] = $r['patrimonio'];
}
$porPatrimonio = [];   // patrimônio => código já cadastrado com outro nome
foreach ($VINCULOS as $codigo => $pat) {
    $porPatrimonio[(string) $pat] = $codigo;
}

$ins = $pdo->prepare(
    'INSERT INTO computadores (codigo, label, patrimonio, sala, modelo, situacao)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        patrimonio = VALUES(patrimonio), sala = VALUES(sala),
        modelo = VALUES(modelo), situacao = VALUES(situacao)'
);
$upd = $pdo->prepare(
    'UPDATE computadores SET patrimonio = ?, sala = ?, modelo = ?, situacao = ? WHERE codigo = ?'
);

$novos = $atualizados = $vinculados = 0;
$usados = [];
$pdo->beginTransaction();
try {
    foreach ($lista as [$pat, $sala, $modelo, $situacao]) {
        if (isset($porPatrimonio[$pat])) {          // PC já cadastrado com outro código
            $codigo = $porPatrimonio[$pat];
            if (!isset($existentes[$codigo])) {
                echo "AVISO: vínculo {$codigo} → {$pat} ignorado (código não existe no painel).\n";
                continue;
            }
            $upd->execute([$pat, $sala, $modelo, $situacao, $codigo]);
            $usados[$codigo] = true;
            $vinculados++;
            continue;
        }
        $codigo = 'CM' . $pat;
        $label  = $sala !== null ? $sala : "Patrimônio {$pat}";
        $ja = array_key_exists($codigo, $existentes);
        $ins->execute([$codigo, mb_substr($label, 0, 100), $pat, $sala, $modelo, $situacao]);
        $usados[$codigo] = true;
        $ja ? $atualizados++ : $novos++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'ERRO — nada foi gravado: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\nImportação concluída.\n";
echo "  Computadores na lista:        " . count($lista) . "\n";
echo "  Novos cadastrados:            {$novos}\n";
echo "  Já existiam (atualizados):    {$atualizados}\n";
echo "  Vinculados a PC já cadastrado: {$vinculados}\n";

// PCs que o agente cadastrou e que não correspondem a nenhum patrimônio.
$fora = [];
foreach ($pdo->query('SELECT codigo, label FROM computadores ORDER BY codigo') as $r) {
    if (!isset($usados[$r['codigo']])) {
        $fora[] = $r['codigo'] . ' (' . $r['label'] . ')';
    }
}
if ($fora) {
    echo "\nJá cadastrados que NÃO estão na planilha (se algum é da lista, coloque em \$VINCULOS no topo deste arquivo e rode de novo):\n";
    foreach ($fora as $f) { echo "  - {$f}\n"; }
}
echo "\nLembrete: apague importar_computadores.php e computadores_ifrn.php do servidor.\n";
