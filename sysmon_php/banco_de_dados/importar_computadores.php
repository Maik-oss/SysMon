<?php
$VINCULOS = [
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

foreach (['patrimonio' => 'VARCHAR(10)', 'sala' => 'VARCHAR(100)',
          'modelo' => 'VARCHAR(150)', 'situacao' => 'VARCHAR(30)'] as $col => $tipo) {
    $st = $pdo->query("SHOW COLUMNS FROM computadores LIKE '{$col}'");
    if (!$st->fetch()) {
        $pdo->exec("ALTER TABLE computadores ADD COLUMN {$col} {$tipo} DEFAULT NULL");
        echo "Coluna criada: {$col}\n";
    }
}

$existentes = [];
foreach ($pdo->query('SELECT codigo, patrimonio FROM computadores') as $r) {
    $existentes[$r['codigo']] = $r['patrimonio'];
}
$porPatrimonio = [];   foreach ($VINCULOS as $codigo => $pat) {
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
        if (isset($porPatrimonio[$pat])) {                      $codigo = $porPatrimonio[$pat];
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
