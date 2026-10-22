<?php
require_once __DIR__ . '/db.php';

// ── Regras de status (ao vivo, calculado pela última métrica recebida) ──
// Se o agente não envia dados há mais tempo que isso, o computador entra
// em "warning"; depois disso, em "offline".
define('STATUS_ONLINE_SECONDS', 20);
define('STATUS_WARNING_SECONDS', 90);

// Limite (%) ou °C a partir do qual uma barra fica vermelha e vira
// notificação. Mesmo valor usado no front-end (static/js/dashboard.js),
// então se mudar um, mude o outro também.
define('ALERT_THRESHOLD', 85.0);

// A partir de quantos problemas críticos ativos ao mesmo tempo o painel
// mostra o banner de alerta "muitos problemas" na aba Alertas.
define('MANY_PROBLEMS_THRESHOLD', 3);

// ── Helpers de resposta ────────────────────────
function json_response($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_auth(): void {
    session_start();
    if (empty($_SESSION['user'])) {
        json_response(['error' => 'Não autenticado.'], 401);
    }
}

// Nomes dos usuários que podem cadastrar/gerenciar acessos de outras
// pessoas. Centralizado aqui para não espalhar essas strings pelos
// arquivos de API — para adicionar/remover um admin, mexe só aqui.
const ADMIN_USERNAMES = ['maikson', 'matheus', 'samuel', 'pedrosa'];

// E-mails já cadastrados dos administradores (usados nas notificações por
// e-mail). São gravados na coluna usuarios.email automaticamente — só
// preenche quando o campo ainda está vazio, então se o admin trocar o
// próprio e-mail em Configurações, a troca dele é respeitada.
const ADMIN_DEFAULT_EMAILS = [
    'maikson' => 'maikson.santiago@escolar.ifrn.edu.br',
    'samuel'  => 'samuel.pedrosa@escolar.ifrn.edu.br',
    'matheus' => 'm.gessle@escolar.ifrn.edu.br',
];

// Tipos de manutenção aceitos (chave gravada no banco => rótulo exibido).
const MAINTENANCE_TYPES = [
    'preventiva' => 'Preventiva',
    'corretiva'  => 'Corretiva',
    'limpeza'    => 'Limpeza',
    'troca_peca' => 'Troca de peça',
    'atualizacao'=> 'Atualização (software/BIOS)',
    'outro'      => 'Outro',
];

/** true se o nome de usuário passado (não necessariamente o logado) é admin. */
function is_admin_username(string $usuario): bool {
    foreach (ADMIN_USERNAMES as $nome) {
        if (strcasecmp($usuario, $nome) === 0) {
            return true;
        }
    }
    return false;
}

/** true se o usuário atualmente logado na sessão é admin. */
function is_admin(): bool {
    return isset($_SESSION['user']) && is_admin_username($_SESSION['user']);
}

/** Chamar sempre depois de require_auth() nos endpoints restritos ao admin. */
function require_admin(): void {
    if (!is_admin()) {
        json_response(['error' => 'Acesso restrito.'], 403);
    }
}

/**
 * Gera "?v=123456" a partir da data de modificação do arquivo, para
 * forçar o navegador a buscar a versão nova de um .css/.js sempre que
 * ele for alterado no servidor — em vez de continuar usando uma cópia
 * antiga guardada em cache.
 */
function asset_v(string $relativePath): string {
    $full = __DIR__ . '/../' . $relativePath;
    $v = @filemtime($full);
    return $relativePath . '?v=' . ($v !== false ? $v : time());
}

// ── Cálculo de status a partir do timestamp da última métrica ─────────
function compute_status(?string $lastSeen): string {
    if ($lastSeen === null) {
        return 'offline';
    }
    $diff = time() - strtotime($lastSeen);
    if ($diff <= STATUS_ONLINE_SECONDS) {
        return 'online';
    }
    if ($diff <= STATUS_WARNING_SECONDS) {
        return 'warning';
    }
    return 'offline';
}

// ── Acesso a dados (substitui os antigos arrays COMPUTERS/USERS) ──────

/** Lista todos os computadores cadastrados, com status calculado ao vivo. */
function fetch_computers(): array {
    $pdo = get_pdo();
    $sql = "SELECT c.codigo, c.label, c.location, c.mobo_manufacturer, c.mobo_model, c.bios_version,
                   m.ultimo_visto AS last_seen
            FROM computadores c
            LEFT JOIN (
                SELECT codigo, MAX(criado_em) AS ultimo_visto
                FROM metricas
                GROUP BY codigo
            ) m ON m.codigo = c.codigo
            ORDER BY c.codigo";

    $out = [];
    foreach ($pdo->query($sql) as $r) {
        $out[$r['codigo']] = [
            'label'             => $r['label'],
            'status'            => compute_status($r['last_seen']),
            'location'          => $r['location'],
            'last_seen'         => $r['last_seen'],
            'mobo_manufacturer' => $r['mobo_manufacturer'],
            'mobo_model'        => $r['mobo_model'],
            'bios_version'      => $r['bios_version'],
        ];
    }
    return $out;
}

/** Busca um único computador pelo código. Retorna null se não existir. */
function fetch_computer(string $code): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT c.codigo, c.label, c.location, c.mobo_manufacturer, c.mobo_model, c.bios_version,
                (SELECT MAX(criado_em) FROM metricas WHERE codigo = c.codigo) AS last_seen
         FROM computadores c
         WHERE c.codigo = ?"
    );
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    return [
        'label'             => $r['label'],
        'status'            => compute_status($r['last_seen']),
        'location'          => $r['location'],
        'last_seen'         => $r['last_seen'],
        'mobo_manufacturer' => $r['mobo_manufacturer'],
        'mobo_model'        => $r['mobo_model'],
        'bios_version'      => $r['bios_version'],
    ];
}

/** Última linha de métricas (CPU/RAM/disco/GPU/rede/uptime) enviada pelo agente. */
function fetch_latest_metric(string $code): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT * FROM metricas WHERE codigo = ? ORDER BY criado_em DESC LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Última linha de temperaturas enviada pelo agente. */
function fetch_latest_temperature(string $code): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT * FROM temperaturas WHERE codigo = ? ORDER BY criado_em DESC LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Lista os usuários cadastrados pelos admins (todos, exceto os próprios
 * admins — que gerenciam a conta deles na tela "Conta", não aqui). */
function fetch_managed_users(): array {
    $pdo = get_pdo();
    $placeholders = implode(',', array_fill(0, count(ADMIN_USERNAMES), '?'));
    $stmt = $pdo->prepare(
        "SELECT usuario, email, notificar_email, ativo, criado_por, criado_em
         FROM usuarios
         WHERE usuario NOT IN ($placeholders)
         ORDER BY criado_em DESC"
    );
    $stmt->execute(ADMIN_USERNAMES);
    return $stmt->fetchAll();
}

// ══════════════════════════════════════════════════════════════════
// Notificações / histórico de problemas
// ══════════════════════════════════════════════════════════════════

/** Nome amigável de cada métrica, pra montar a mensagem do evento. */
function metric_label(string $chave): string {
    $labels = [
        'status'      => 'Status da máquina',
        'cpu_usage'   => 'Uso de CPU',
        'gpu_usage'   => 'Uso de GPU',
        'ram_pct'     => 'Uso de RAM',
        'disk_pct'    => 'Uso de disco',
        'cpu_temp'    => 'Temperatura da CPU',
        'gpu_temp'    => 'Temperatura da GPU',
        'cpu_package' => 'Temperatura da CPU (package)',
        'gpu_die'     => 'Temperatura do chip da GPU',
        'nvme_1'      => 'Temperatura do NVMe 1',
        'nvme_2'      => 'Temperatura do NVMe 2',
        'psu'         => 'Temperatura da fonte',
        'motherboard' => 'Temperatura da placa-mãe',
    ];
    return $labels[$chave] ?? $chave;
}

/** Insere um evento novo (problema começando). */
function open_event(string $codigo, string $tipo, string $chave, string $nivel, ?float $valor, string $mensagem): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO eventos (codigo, tipo, chave, nivel, valor, mensagem, ativo, visualizado)
         VALUES (?, ?, ?, ?, ?, ?, 1, 0)'
    );
    $stmt->execute([$codigo, $tipo, $chave, $nivel, $valor, $mensagem]);

    // Guarda para enviar por e-mail no fim da conferência (um único e-mail
    // agrupado por pessoa, em vez de um e-mail por problema).
    queue_alert_email([
        'codigo'   => $codigo,
        'nivel'    => $nivel,
        'mensagem' => $mensagem,
    ]);
}

/** Marca o(s) evento(s) ativo(s) daquela chave como resolvido(s). */
function resolve_events(string $codigo, string $chave): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "UPDATE eventos SET ativo = 0, resolvido_em = NOW()
         WHERE codigo = ? AND chave = ? AND ativo = 1"
    );
    $stmt->execute([$codigo, $chave]);
}

/** true se já existe um evento ativo (não resolvido) pra essa chave. */
function has_active_event(string $codigo, string $chave): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT id FROM eventos WHERE codigo = ? AND chave = ? AND ativo = 1 LIMIT 1'
    );
    $stmt->execute([$codigo, $chave]);
    return (bool) $stmt->fetch();
}

/**
 * Confere o estado atual (status + métricas + temperaturas) de todos os
 * computadores e abre/fecha eventos de acordo. Chamado a cada
 * carregamento do painel (api/computers.php e api/notifications.php),
 * então funciona mesmo sem cron — desde que alguém tenha o painel
 * aberto (ou o polling automático rodando) de tempos em tempos.
 */
function sync_alert_events(): void {
    $pdo = get_pdo();

    cleanup_old_events();

    $computers = $pdo->query(
        "SELECT c.codigo, c.label, c.last_status,
                (SELECT MAX(criado_em) FROM metricas WHERE codigo = c.codigo) AS last_seen
         FROM computadores c"
    )->fetchAll();

    foreach ($computers as $c) {
        $codigo = $c['codigo'];
        $status = compute_status($c['last_seen']);

        // ── Transição de status (online → warning/offline → online) ──
        if ($status !== $c['last_status']) {
            if ($status !== 'online') {
                $nivel = $status === 'offline' ? 'offline' : 'warning';
                $msg = $status === 'offline'
                    ? "\"{$c['label']}\" ({$codigo}) ficou offline."
                    : "\"{$c['label']}\" ({$codigo}) parou de responder (alerta).";
                if (!has_active_event($codigo, 'status')) {
                    open_event($codigo, 'status', 'status', $nivel, null, $msg);
                }
            } else {
                resolve_events($codigo, 'status');
            }
            $upd = $pdo->prepare('UPDATE computadores SET last_status = ? WHERE codigo = ?');
            $upd->execute([$status, $codigo]);
        }

        if ($status === 'offline') {
            // Máquina offline não tem métrica recente confiável — pula.
            continue;
        }

        // ── Métricas (última linha da tabela metricas) ──
        $m = fetch_latest_metric($codigo);
        if ($m) {
            $ramPct  = ((float)$m['ram_total']  > 0) ? round((float)$m['ram_used']  / (float)$m['ram_total']  * 100, 1) : null;
            $diskPct = ((float)$m['disk_total'] > 0) ? round((float)$m['disk_used'] / (float)$m['disk_total'] * 100, 1) : null;

            $checks = [
                'cpu_usage' => $m['cpu_usage'] !== null ? (float)$m['cpu_usage'] : null,
                'gpu_usage' => $m['gpu_usage'] !== null ? (float)$m['gpu_usage'] : null,
                'ram_pct'   => $ramPct,
                'disk_pct'  => $diskPct,
                'cpu_temp'  => $m['cpu_temp']  !== null ? (float)$m['cpu_temp']  : null,
                'gpu_temp'  => $m['gpu_temp']  !== null ? (float)$m['gpu_temp']  : null,
            ];
            foreach ($checks as $chave => $valor) {
                check_threshold($codigo, $c['label'], 'metrica', $chave, $valor);
            }
        }

        // ── Temperaturas (última linha da tabela temperaturas) ──
        $t = fetch_latest_temperature($codigo);
        if ($t) {
            $checks = [
                'cpu_package' => $t['cpu_package'] !== null ? (float)$t['cpu_package'] : null,
                'gpu_die'     => $t['gpu_die']     !== null ? (float)$t['gpu_die']     : null,
                'nvme_1'      => $t['nvme_1']      !== null ? (float)$t['nvme_1']      : null,
                'nvme_2'      => $t['nvme_2']      !== null ? (float)$t['nvme_2']      : null,
                'psu'         => $t['psu']         !== null ? (float)$t['psu']         : null,
                'motherboard' => $t['motherboard'] !== null ? (float)$t['motherboard'] : null,
            ];
            foreach ($checks as $chave => $valor) {
                check_threshold($codigo, $c['label'], 'temperatura', $chave, $valor);
            }
        }
    }

    // Eventos novos desta conferência → e-mail. Uma falha de envio nunca
    // pode derrubar o painel, por isso é isolada aqui.
    try {
        flush_alert_emails();
    } catch (Throwable $e) {
        error_log('[sysmon] Falha ao enviar e-mails de alerta: ' . $e->getMessage());
    }
}

/** Abre ou fecha o evento de uma métrica específica, comparando com ALERT_THRESHOLD. */
function check_threshold(string $codigo, string $label, string $tipo, string $chave, ?float $valor): void {
    if ($valor === null) {
        return;
    }
    $emAlerta = $valor >= ALERT_THRESHOLD;
    $jaAtivo  = has_active_event($codigo, $chave);

    if ($emAlerta && !$jaAtivo) {
        $unidade = ($tipo === 'temperatura' || substr($chave, -5) === '_temp') ? '°C' : '%';
        $msg = "\"{$label}\" ({$codigo}): " . metric_label($chave) . " em {$valor}{$unidade} (acima do limite).";
        open_event($codigo, $tipo, $chave, 'critical', $valor, $msg);
    } elseif (!$emAlerta && $jaAtivo) {
        resolve_events($codigo, $chave);
    }
}

/** Lista os eventos mais recentes (histórico + notificações), com nome do computador. */
function fetch_events(int $limit = 100, bool $onlyUnread = false): array {
    $pdo = get_pdo();
    $sql = "SELECT e.*, c.label AS computador_label
            FROM eventos e
            JOIN computadores c ON c.codigo = e.codigo";
    if ($onlyUnread) {
        $sql .= " WHERE e.visualizado = 0";
    }
    $sql .= " ORDER BY e.criado_em DESC LIMIT " . max(1, min(500, $limit));
    return $pdo->query($sql)->fetchAll();
}

/** Quantidade de notificações ainda não vistas. */
function count_unread_events(): int {
    $pdo = get_pdo();
    return (int) $pdo->query("SELECT COUNT(*) FROM eventos WHERE visualizado = 0")->fetchColumn();
}

/** Marca uma notificação (ou todas) como visualizada pelo usuário logado. */
function mark_events_read(?int $id, string $usuario): void {
    $pdo = get_pdo();
    if ($id !== null) {
        $stmt = $pdo->prepare(
            "UPDATE eventos SET visualizado = 1, visualizado_por = ?, visualizado_em = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$usuario, $id]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE eventos SET visualizado = 1, visualizado_por = ?, visualizado_em = NOW()
             WHERE visualizado = 0"
        );
        $stmt->execute([$usuario]);
    }
}

/** Histórico de temperaturas de uma máquina, mais antigo → mais recente, para o gráfico. */
function fetch_temperature_history(string $codigo, int $limit = 40): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT cpu_package, gpu_die, motherboard, criado_em
         FROM temperaturas
         WHERE codigo = ?
         ORDER BY criado_em DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $codigo);
    $stmt->bindValue(2, max(2, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}

/**
 * Última temperatura VÁLIDA (CPU package + GPU die) de todo mundo.
 * CPU e GPU são buscadas separadamente, ignorando registros NULL: se o
 * coletor parou ou o LibreHardwareMonitor foi fechado (agente grava NULL),
 * o gráfico continua mostrando o último valor real de quando o PC estava
 * coletando. Quando a coleta volta, o valor passa a ser atualizado sozinho.
 */
function fetch_all_latest_temperatures(): array {
    $pdo = get_pdo();
    $sql = "SELECT c.codigo, c.label,
                   (SELECT t.cpu_package FROM temperaturas t
                     WHERE t.codigo = c.codigo AND t.cpu_package IS NOT NULL
                     ORDER BY t.criado_em DESC LIMIT 1) AS cpu_package,
                   (SELECT t.gpu_die FROM temperaturas t
                     WHERE t.codigo = c.codigo AND t.gpu_die IS NOT NULL
                     ORDER BY t.criado_em DESC LIMIT 1) AS gpu_die,
                   (SELECT MAX(t.criado_em) FROM temperaturas t
                     WHERE t.codigo = c.codigo
                       AND (t.cpu_package IS NOT NULL OR t.gpu_die IS NOT NULL)) AS temp_em,
                   (SELECT MAX(criado_em) FROM metricas WHERE codigo = c.codigo) AS last_seen
            FROM computadores c
            ORDER BY c.codigo";

    $out = [];
    foreach ($pdo->query($sql) as $r) {
        $out[] = [
            'code'   => $r['codigo'],
            'label'  => $r['label'],
            'status' => compute_status($r['last_seen']),
            'cpu'    => $r['cpu_package'] !== null ? (float) $r['cpu_package'] : null,
            'gpu'    => $r['gpu_die']     !== null ? (float) $r['gpu_die']     : null,
            'temp_em' => $r['temp_em'],
        ];
    }
    return $out;
}

/**
 * Resumo dos problemas críticos ATIVOS agora (offline + métrica/temp
 * acima do limite) — usado pro banner "muitos problemas" na aba Alertas.
 */
function fetch_critical_summary(): array {
    $pdo = get_pdo();
    $stmt = $pdo->query(
        "SELECT e.codigo, c.label, e.mensagem, e.nivel, e.criado_em
         FROM eventos e
         JOIN computadores c ON c.codigo = e.codigo
         WHERE e.ativo = 1 AND e.nivel IN ('critical', 'offline')
         ORDER BY e.criado_em DESC"
    );
    $rows = $stmt->fetchAll();
    return [
        'total' => count($rows),
        'itens' => array_slice(array_map(function ($r) {
            return [
                'codigo'    => $r['codigo'],
                'label'     => $r['label'],
                'mensagem'  => $r['mensagem'],
                'nivel'     => $r['nivel'],
                'criado_em' => $r['criado_em'],
            ];
        }, $rows), 0, 8),
    ];
}

/**
 * Reset automático do histórico: apaga notificações/eventos com mais
 * de 1 mês. Não precisa de cron — roda sozinho sempre que alguém abre
 * o painel (chamado no início de sync_alert_events()), então o
 * histórico nunca guarda mais que ~30 dias de problemas.
 */
function cleanup_old_events(): void {
    $pdo = get_pdo();
    $pdo->exec("DELETE FROM eventos WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
}

// ══════════════════════════════════════════════════════════════════
// Migração automática do banco (colunas/tabelas novas)
// ══════════════════════════════════════════════════════════════════

/**
 * Garante que o banco tem o que as funções novas precisam (coluna de
 * e-mail dos usuários e tabela de manutenções), mesmo em bancos criados
 * com o schema.sql antigo. Roda uma vez e deixa um marcador na pasta
 * temporária, então não pesa nas requisições seguintes. Chamada por
 * get_pdo() (includes/db.php).
 *
 * Usa information_schema em vez de "ADD COLUMN IF NOT EXISTS" porque o
 * MySQL/Percona 5.7 do provedor não suporta essa sintaxe.
 */
function ensure_schema(PDO $pdo, string $dbname): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sysmon_schema_v2_' . md5($dbname . '|' . __DIR__);
    if (@is_file($marker)) {
        return;
    }

    try {
        $hasCol = function (string $table, string $col) use ($pdo, $dbname): bool {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $st->execute([$dbname, $table, $col]);
            return (int) $st->fetchColumn() > 0;
        };

        if (!$hasCol('usuarios', 'email')) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN email VARCHAR(150) DEFAULT NULL');
        }
        if (!$hasCol('usuarios', 'notificar_email')) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN notificar_email TINYINT(1) NOT NULL DEFAULT 1');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS manutencoes (
                id              BIGINT AUTO_INCREMENT PRIMARY KEY,
                codigo          VARCHAR(20) NOT NULL,
                tipo            VARCHAR(20) NOT NULL DEFAULT \'outro\',
                descricao       TEXT NOT NULL,
                realizado_em    DATETIME NOT NULL,
                registrado_por  VARCHAR(50) NOT NULL,
                criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_manut_codigo (codigo, realizado_em),
                CONSTRAINT fk_manut_computador
                    FOREIGN KEY (codigo) REFERENCES computadores(codigo)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        seed_admin_emails($pdo);
        @file_put_contents($marker, 'ok');
    } catch (Throwable $e) {
        // Ex.: tabelas ainda não criadas (schema.sql não rodou) ou usuário
        // do banco sem permissão de ALTER. Tenta de novo na próxima vez.
        error_log('[sysmon] ensure_schema: ' . $e->getMessage());
    }
}

/** Preenche o e-mail padrão dos admins que ainda estão sem e-mail. */
function seed_admin_emails(PDO $pdo): void {
    $stmt = $pdo->prepare(
        "UPDATE usuarios SET email = ?
         WHERE usuario = ? AND (email IS NULL OR email = '')"
    );
    foreach (ADMIN_DEFAULT_EMAILS as $usuario => $email) {
        $stmt->execute([$email, $usuario]);
    }
}

// ══════════════════════════════════════════════════════════════════
// E-mail dos usuários + notificação por e-mail
// ══════════════════════════════════════════════════════════════════

/** Valida e normaliza um e-mail. Retorna null se for inválido. */
function normalize_email(string $email): ?string {
    $email = trim($email);
    if ($email === '' || strlen($email) > 150) {
        return null;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/** Destinatários: usuários ativos, com e-mail e com notificações ligadas. */
function fetch_email_recipients(): array {
    $pdo = get_pdo();
    seed_admin_emails($pdo);
    return $pdo->query(
        "SELECT usuario, email FROM usuarios
         WHERE ativo = 1 AND notificar_email = 1
           AND email IS NOT NULL AND email <> ''"
    )->fetchAll();
}

/**
 * Fila de eventos novos da conferência atual. Chamada sem argumentos
 * devolve (e esvazia) a fila; com argumento, adiciona um evento.
 */
function queue_alert_email(?array $event = null): array {
    static $queue = [];
    if ($event !== null) {
        $queue[] = $event;
        return [];
    }
    $out = $queue;
    $queue = [];
    return $out;
}

/** Envia, para cada destinatário, UM e-mail com todos os eventos novos. */
function flush_alert_emails(): void {
    $events = queue_alert_email();
    if (!$events) {
        return;
    }
    require_once __DIR__ . '/mailer.php';

    $recipients = fetch_email_recipients();
    if (!$recipients) {
        return;
    }

    $n = count($events);
    $subject = $n === 1
        ? '[SysMon] ' . $events[0]['mensagem']
        : "[SysMon] {$n} novos alertas nos computadores";
    $subject = mb_substr($subject, 0, 150);

    $nivelLabel = ['offline' => 'OFFLINE', 'warning' => 'ALERTA', 'critical' => 'CRÍTICO'];
    $textLines = [];
    $htmlItems = '';
    foreach ($events as $e) {
        $tag = $nivelLabel[$e['nivel']] ?? strtoupper($e['nivel']);
        $textLines[] = "- [{$tag}] {$e['mensagem']}";
        $cor = $e['nivel'] === 'critical' ? '#d33' : ($e['nivel'] === 'offline' ? '#666' : '#c90');
        $htmlItems .= '<li style="margin:0 0 8px"><b style="color:' . $cor . '">' . htmlspecialchars($tag)
                    . '</b> — ' . htmlspecialchars($e['mensagem']) . '</li>';
    }
    $quando = date('d/m/Y H:i');
    $text = "SysMon — {$n} novo(s) alerta(s) em {$quando}:\n\n" . implode("\n", $textLines)
          . "\n\nAcesse o painel para ver os detalhes.\n"
          . "Para parar de receber estes e-mails, desligue a opção em Configurações.";
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
          . '<h3 style="margin:0 0 12px">SysMon — ' . $n . ' novo(s) alerta(s)</h3>'
          . '<p style="margin:0 0 12px;color:#666">' . htmlspecialchars($quando) . '</p>'
          . '<ul style="padding-left:18px;margin:0 0 16px">' . $htmlItems . '</ul>'
          . '<p style="color:#888;font-size:12px">Acesse o painel para ver os detalhes. '
          . 'Para parar de receber estes e-mails, desligue a opção em Configurações.</p></div>';

    foreach ($recipients as $r) {
        [$ok, $err] = send_email($r['email'], $subject, $html, $text);
        if (!$ok) {
            error_log("[sysmon] E-mail de alerta para {$r['usuario']} falhou: {$err}");
        }
    }
}

// ══════════════════════════════════════════════════════════════════
// Histórico de manutenção
// ══════════════════════════════════════════════════════════════════

/** Manutenções de um computador, da mais recente para a mais antiga. */
function fetch_maintenance(string $codigo, int $limit = 200): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT id, tipo, descricao, realizado_em, registrado_por, criado_em
         FROM manutencoes
         WHERE codigo = ?
         ORDER BY realizado_em DESC, id DESC
         LIMIT ' . max(1, min(500, $limit))
    );
    $stmt->execute([$codigo]);
    return $stmt->fetchAll();
}

/** Registra uma manutenção. $realizadoEm no formato "Y-m-d H:i:s". */
function add_maintenance(string $codigo, string $tipo, string $descricao, string $realizadoEm, string $usuario): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO manutencoes (codigo, tipo, descricao, realizado_em, registrado_por)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$codigo, $tipo, $descricao, $realizadoEm, $usuario]);
    return (int) $pdo->lastInsertId();
}

/** Remove um registro de manutenção. true se algo foi apagado. */
function delete_maintenance(int $id): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM manutencoes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}
