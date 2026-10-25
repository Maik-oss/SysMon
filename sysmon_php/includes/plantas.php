<?php
/**
 * Plantas do campus + bolinhas de status por sala.
 *
 * Como funciona:
 *   - As plantas (SVG) ficam em static/plantas/ e são listadas em PLANTAS.
 *   - Cada "marcador" é uma bolinha colocada numa posição da planta (x/y em %
 *     da imagem) e ligada a UMA OU MAIS salas do cadastro de computadores
 *     (computadores.sala). Mais de uma sala no mesmo marcador serve para
 *     nomes diferentes da planilha que são o mesmo lugar físico
 *     (ex.: "LAB. GAMES" e "LAB. JOGOS").
 *   - A cor e as contagens de cada bolinha são calculadas AO VIVO a partir
 *     do status real dos computadores daquelas salas.
 *
 * Tabelas (criadas sozinhas por ensure_schema() em data.php):
 *   planta_marcadores, planta_marcador_salas
 */
require_once __DIR__ . '/data.php';

// Plantas disponíveis. Para trocar o nome de um andar ou o arquivo de uma
// planta, mexa só aqui. A ordem daqui é a ordem das abas na tela.
const PLANTAS = [
    'principal-terreo' => ['predio' => 'Principal', 'andar' => 'Térreo',   'arquivo' => 'static/plantas/principal-terreo.svg'],
    'principal-1andar' => ['predio' => 'Principal', 'andar' => '1º andar', 'arquivo' => 'static/plantas/principal-1andar.svg'],
    'principal-2andar' => ['predio' => 'Principal', 'andar' => '2º andar', 'arquivo' => 'static/plantas/principal-2andar.svg'],
    'anexo-terreo'     => ['predio' => 'Anexo',     'andar' => 'Térreo',   'arquivo' => 'static/plantas/anexo-terreo.svg'],
    'anexo-1andar'     => ['predio' => 'Anexo',     'andar' => '1º andar', 'arquivo' => 'static/plantas/anexo-1andar.svg'],
    'anexo-2andar'     => ['predio' => 'Anexo',     'andar' => '2º andar', 'arquivo' => 'static/plantas/anexo-2andar.svg'],
];

/**
 * Situação de cada computador, uma só categoria por máquina:
 *   sem_agente : cadastrado na planilha, mas o agente nunca enviou dados
 *   offline    : o agente parou de enviar dados (mais de 90s)
 *   problema   : online, mas com alerta/crítico ativo ou contato atrasado
 *   ok         : online e sem nenhum problema
 * (mesma regra usada no gráfico de pizza do Monitoramento Geral)
 *
 * @return array<string, array{cat:string, sala:string, patrimonio:?string, label:string}>
 */
function plantas_classificar_pcs(): array {
    $pdo = get_pdo();

    $ativos = [];
    foreach ($pdo->query('SELECT codigo, nivel FROM eventos WHERE ativo = 1') as $r) {
        if ($r['nivel'] === 'critical') {
            $ativos[$r['codigo']]['critical'] = true;
        } elseif ($r['nivel'] === 'warning') {
            $ativos[$r['codigo']]['warning'] = true;
        }
    }

    $out = [];
    foreach (fetch_computers() as $codigo => $info) {
        if ($info['sem_agente']) {
            $cat = 'sem_agente';
        } elseif ($info['status'] === 'offline') {
            $cat = 'offline';
        } elseif (!empty($ativos[$codigo]['critical'])
               || !empty($ativos[$codigo]['warning'])
               || $info['status'] === 'warning') {
            $cat = 'problema';
        } else {
            $cat = 'ok';
        }
        $out[(string) $codigo] = [
            'cat'        => $cat,
            // "location" já é a sala quando ela existe (ver computer_cadastro()).
            'sala'       => trim((string) ($info['location'] ?? '')),
            'patrimonio' => $info['patrimonio'],
            'label'      => (string) $info['label'],
        ];
    }
    return $out;
}

/**
 * Cor da bolinha a partir das contagens da sala:
 *   cinza    : nenhum computador monitorado online (tudo offline ou sem agente)
 *   vermelho : algum computador com problema OU algum offline (a sala não
 *              está 100% bem — o número exato aparece no cartão da sala)
 *   verde    : todos os computadores monitorados online e sem problemas
 * Para tratar "alguns offline + o resto ok" de outro jeito, mexa só aqui.
 */
function plantas_cor(array $n): string {
    $monitorados = $n['ok'] + $n['problema'] + $n['offline'];
    if ($monitorados === 0 || $n['ok'] + $n['problema'] === 0) {
        return 'cinza';
    }
    if ($n['problema'] > 0 || $n['offline'] > 0) {
        return 'vermelho';
    }
    return 'verde';
}

function plantas_contagem_vazia(): array {
    return ['total' => 0, 'ok' => 0, 'problema' => 0, 'offline' => 0, 'sem_agente' => 0];
}

/** Tudo que a tela precisa: plantas, bolinhas com contagens, salas sem posição. */
function plantas_payload(): array {
    $pdo = get_pdo();
    $pcs = plantas_classificar_pcs();

    // Computadores agrupados por sala.
    $porSala = [];
    $semSala = plantas_contagem_vazia();
    $geral   = plantas_contagem_vazia();
    foreach ($pcs as $codigo => $p) {
        $geral['total']++;
        $geral[$p['cat']]++;
        if ($p['sala'] === '') {
            $semSala['total']++;
            $semSala[$p['cat']]++;
            continue;
        }
        $porSala[$p['sala']][] = ['code' => (string) $codigo] + $p;
    }

    // Marcadores e as salas de cada um.
    $salasDoMarcador = [];
    $atribuidas = [];
    foreach ($pdo->query('SELECT marcador_id, sala FROM planta_marcador_salas ORDER BY sala') as $r) {
        $salasDoMarcador[(int) $r['marcador_id']][] = (string) $r['sala'];
        $atribuidas[(string) $r['sala']] = true;
    }

    $plantas = [];
    foreach (PLANTAS as $chave => $cfg) {
        $plantas[$chave] = [
            'id'         => $chave,
            'predio'     => $cfg['predio'],
            'andar'      => $cfg['andar'],
            'arquivo'    => $cfg['arquivo'],
            'marcadores' => [],
        ];
    }

    $mapeados = 0;
    foreach ($pdo->query('SELECT id, planta, nome, x, y FROM planta_marcadores ORDER BY id') as $m) {
        if (!isset($plantas[$m['planta']])) {
            continue;   // planta removida da configuração
        }
        $id    = (int) $m['id'];
        $salas = $salasDoMarcador[$id] ?? [];

        $n = plantas_contagem_vazia();
        $lista = [];
        foreach ($salas as $sala) {
            foreach ($porSala[$sala] ?? [] as $p) {
                $n['total']++;
                $n[$p['cat']]++;
                $lista[] = [
                    'code'       => $p['code'],
                    'patrimonio' => $p['patrimonio'],
                    'sala'       => $sala,
                    'cat'        => $p['cat'],
                ];
            }
        }
        $mapeados += $n['total'];

        // Problemas primeiro, depois offline, ok, sem agente.
        $peso = ['problema' => 0, 'offline' => 1, 'ok' => 2, 'sem_agente' => 3];
        usort($lista, function ($a, $b) use ($peso) {
            return [$peso[$a['cat']], $a['code']] <=> [$peso[$b['cat']], $b['code']];
        });

        $plantas[$m['planta']]['marcadores'][] = [
            'id'    => $id,
            'nome'  => $m['nome'],
            'x'     => (float) $m['x'],
            'y'     => (float) $m['y'],
            'salas' => $salas,
            'cor'   => plantas_cor($n),
            'n'     => $n,
            'pcs'   => $lista,
        ];
    }

    // Salas que existem no cadastro mas ainda não têm bolinha em nenhuma planta.
    $livres = [];
    foreach ($porSala as $sala => $lista) {
        $sala = (string) $sala;
        if (isset($atribuidas[$sala])) {
            continue;
        }
        $livres[] = ['sala' => $sala, 'total' => count($lista)];
    }
    usort($livres, function ($a, $b) { return strcasecmp($a['sala'], $b['sala']); });

    return [
        'plantas'     => array_values($plantas),
        'salas_livres' => $livres,
        'sem_sala'    => $semSala['total'],
        'geral'       => $geral,
        'mapeados'    => $mapeados,
    ];
}

/** Conjunto (chave = nome) de todas as salas existentes no cadastro. */
function plantas_salas_existentes(): array {
    $set = [];
    foreach (fetch_computers() as $info) {
        $loc = trim((string) ($info['location'] ?? ''));
        if ($loc !== '') {
            $set[$loc] = true;
        }
    }
    return $set;
}

/**
 * Cria ou atualiza um marcador. Uma sala só pode estar em um marcador: se ela
 * já estava em outro, é movida para este (e marcadores que ficarem sem sala
 * são apagados).
 */
function plantas_salvar_marcador(array $in): array {
    $pdo = get_pdo();

    $id     = (int) ($in['id'] ?? 0);
    $planta = (string) ($in['planta'] ?? '');
    if (!isset(PLANTAS[$planta])) {
        return ['ok' => false, 'status' => 400, 'error' => 'Planta inválida.'];
    }

    $x = isset($in['x']) ? (float) $in['x'] : -1.0;
    $y = isset($in['y']) ? (float) $in['y'] : -1.0;
    if ($x < 0 || $x > 100 || $y < 0 || $y > 100) {
        return ['ok' => false, 'status' => 400, 'error' => 'Posição inválida na planta.'];
    }
    $x = round($x, 3);
    $y = round($y, 3);

    $salas = [];
    foreach ((array) ($in['salas'] ?? []) as $s) {
        $s = trim((string) $s);
        if ($s !== '') {
            $salas[$s] = $s;
        }
    }
    $salas = array_values($salas);
    if (!$salas) {
        return ['ok' => false, 'status' => 400, 'error' => 'Escolha ao menos uma sala para este marcador.'];
    }
    $existentes = plantas_salas_existentes();
    foreach ($salas as $s) {
        if (!isset($existentes[$s])) {
            return ['ok' => false, 'status' => 400, 'error' => "A sala \"{$s}\" não existe no cadastro de computadores."];
        }
    }

    $nome = trim((string) ($in['nome'] ?? ''));
    if ($nome === '') {
        $nome = $salas[0];
    }
    $nome = mb_substr($nome, 0, 100);

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM planta_marcadores WHERE id = ?');
            $chk->execute([$id]);
            if ((int) $chk->fetchColumn() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'status' => 404, 'error' => 'Marcador não encontrado.'];
            }
            $pdo->prepare('UPDATE planta_marcadores SET planta = ?, nome = ?, x = ?, y = ? WHERE id = ?')
                ->execute([$planta, $nome, $x, $y, $id]);
            $pdo->prepare('DELETE FROM planta_marcador_salas WHERE marcador_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO planta_marcadores (planta, nome, x, y, criado_por) VALUES (?, ?, ?, ?, ?)')
                ->execute([$planta, $nome, $x, $y, $_SESSION['user'] ?? null]);
            $id = (int) $pdo->lastInsertId();
        }

        $del = $pdo->prepare('DELETE FROM planta_marcador_salas WHERE sala = ?');
        $ins = $pdo->prepare('INSERT INTO planta_marcador_salas (sala, marcador_id) VALUES (?, ?)');
        foreach ($salas as $s) {
            $del->execute([$s]);      // tira a sala de outro marcador, se estava
            $ins->execute([$s, $id]);
        }

        // Marcadores que perderam todas as salas não fazem mais sentido.
        $pdo->exec(
            'DELETE FROM planta_marcadores
             WHERE id NOT IN (SELECT marcador_id FROM planta_marcador_salas)'
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[sysmon] plantas_salvar_marcador: ' . $e->getMessage());
        return ['ok' => false, 'status' => 500, 'error' => 'Não foi possível salvar o marcador.'];
    }

    return ['ok' => true, 'id' => $id];
}

/** Só muda a posição (arrastar a bolinha). */
function plantas_mover_marcador(int $id, float $x, float $y): bool {
    if ($id <= 0 || $x < 0 || $x > 100 || $y < 0 || $y > 100) {
        return false;
    }
    $st = get_pdo()->prepare('UPDATE planta_marcadores SET x = ?, y = ? WHERE id = ?');
    $st->execute([round($x, 3), round($y, 3), $id]);
    // rowCount() é 0 quando a posição não mudou; confere se o marcador existe.
    $chk = get_pdo()->prepare('SELECT COUNT(*) FROM planta_marcadores WHERE id = ?');
    $chk->execute([$id]);
    return (int) $chk->fetchColumn() > 0;
}

function plantas_excluir_marcador(int $id): bool {
    if ($id <= 0) {
        return false;
    }
    $st = get_pdo()->prepare('DELETE FROM planta_marcadores WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() > 0;
}
