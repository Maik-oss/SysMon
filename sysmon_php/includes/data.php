<?php
require_once __DIR__ . '/db.php';

define('STATUS_ONLINE_SECONDS', 20);
define('STATUS_WARNING_SECONDS', 90);

define('ALERT_THRESHOLD', 85.0);

define('MANY_PROBLEMS_THRESHOLD', 3);

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

const ADMIN_USERNAMES = ['maikson', 'matheus', 'samuel', 'pedrosa'];

const ADMIN_DEFAULT_EMAILS = [
    'maikson' => 'maikson.santiago@escolar.ifrn.edu.br',
    'samuel'  => 'samuel.pedrosa@escolar.ifrn.edu.br',
    'matheus' => 'm.gessle@escolar.ifrn.edu.br',
];

const MAINTENANCE_TYPES = [
    'preventiva' => 'Preventiva',
    'corretiva'  => 'Corretiva',
    'limpeza'    => 'Limpeza',
    'troca_peca' => 'Troca de peça',
    'atualizacao'=> 'Atualização (software/BIOS)',
    'outro'      => 'Outro',
];

function is_admin_username(string $usuario): bool {
    foreach (ADMIN_USERNAMES as $nome) {
        if (strcasecmp($usuario, $nome) === 0) {
            return true;
        }
    }
    return false;
}

function is_admin(): bool {
    return isset($_SESSION['user']) && is_admin_username($_SESSION['user']);
}

function require_admin(): void {
    if (!is_admin()) {
        json_response(['error' => 'Acesso restrito.'], 403);
    }
}

function asset_v(string $relativePath): string {
    $full = __DIR__ . '/../' . $relativePath;
    $v = @filemtime($full);
    return $relativePath . '?v=' . ($v !== false ? $v : time());
}

function computer_cadastro(array $r): array {
    $pat = trim((string) ($r['patrimonio'] ?? ''));
    if ($pat === '' && preg_match('/^CM(?=[0-9A-Z]*[0-9])([0-9A-Z]{3,10})$/', strtoupper((string) $r['codigo']), $m)) {
        $pat = $m[1];       }
    $sala = trim((string) ($r['sala'] ?? ''));
    $loc  = $sala !== '' ? $sala : trim((string) ($r['location'] ?? ''));
    $modelo   = trim((string) ($r['modelo'] ?? ''));
    $situacao = trim((string) ($r['situacao'] ?? ''));
    return [
        'location'   => $loc,
        'patrimonio' => $pat !== '' ? $pat : null,
        'sala'       => $sala !== '' ? $sala : null,
        'modelo'     => $modelo !== '' ? $modelo : null,
        'situacao'   => $situacao !== '' ? $situacao : null,
        'sem_agente' => $r['last_seen'] === null,
                'pausada'     => (int) ($r['coleta_pausada'] ?? 0) === 1,
        'pausada_em'  => $r['pausada_em'] ?? null,
        'pausada_por' => $r['pausada_por'] ?? null,
    ];
}

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

function fetch_computers(): array {
    $pdo = get_pdo();
    $sql = "SELECT c.codigo, c.label, c.location, c.patrimonio, c.sala, c.modelo, c.situacao, c.coleta_pausada, c.pausada_em, c.pausada_por,
                   c.mobo_manufacturer, c.mobo_model, c.bios_version,
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
            'last_seen'         => $r['last_seen'],
            'mobo_manufacturer' => $r['mobo_manufacturer'],
            'mobo_model'        => $r['mobo_model'],
            'bios_version'      => $r['bios_version'],
        ] + computer_cadastro($r);
    }
    return $out;
}

function fetch_computer(string $code): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT c.codigo, c.label, c.location, c.patrimonio, c.sala, c.modelo, c.situacao, c.coleta_pausada, c.pausada_em, c.pausada_por,
                c.mobo_manufacturer, c.mobo_model, c.bios_version,
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
        'last_seen'         => $r['last_seen'],
        'mobo_manufacturer' => $r['mobo_manufacturer'],
        'mobo_model'        => $r['mobo_model'],
        'bios_version'      => $r['bios_version'],
    ] + computer_cadastro($r);
}

function fetch_latest_metric(string $code): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT * FROM metricas WHERE codigo = ? ORDER BY criado_em DESC LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_latest_temperature(string $code): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT * FROM temperaturas WHERE codigo = ? ORDER BY criado_em DESC LIMIT 1"
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

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

function open_event(string $codigo, string $tipo, string $chave, string $nivel, ?float $valor, string $mensagem): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO eventos (codigo, tipo, chave, nivel, valor, mensagem, ativo, visualizado)
         VALUES (?, ?, ?, ?, ?, ?, 1, 0)'
    );
    $stmt->execute([$codigo, $tipo, $chave, $nivel, $valor, $mensagem]);

            queue_alert_email([
        'codigo'   => $codigo,
        'nivel'    => $nivel,
        'mensagem' => $mensagem,
    ]);
}

function resolve_events(string $codigo, string $chave): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "UPDATE eventos SET ativo = 0, resolvido_em = NOW()
         WHERE codigo = ? AND chave = ? AND ativo = 1"
    );
    $stmt->execute([$codigo, $chave]);
}

function has_active_event(string $codigo, string $chave): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT id FROM eventos WHERE codigo = ? AND chave = ? AND ativo = 1 LIMIT 1'
    );
    $stmt->execute([$codigo, $chave]);
    return (bool) $stmt->fetch();
}

function sync_alert_events(): void {
    $pdo = get_pdo();

    cleanup_old_events();

    $computers = $pdo->query(
        "SELECT c.codigo, c.label, c.last_status, c.coleta_pausada,
                (SELECT MAX(criado_em) FROM metricas WHERE codigo = c.codigo) AS last_seen
         FROM computadores c"
    )->fetchAll();

    foreach ($computers as $c) {
        $codigo = $c['codigo'];

                        if ((int) $c['coleta_pausada'] === 1) {
            $pdo->prepare('UPDATE eventos SET ativo = 0, resolvido_em = NOW() WHERE codigo = ? AND ativo = 1')
                ->execute([$codigo]);
            if ($c['last_status'] !== 'pausado') {
                $pdo->prepare('UPDATE computadores SET last_status = ? WHERE codigo = ?')->execute(['pausado', $codigo]);
            }
            continue;
        }

                                if ($c['last_seen'] === null) {
            continue;
        }

        $status = compute_status($c['last_seen']);

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
                        continue;
        }

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

            try {
        flush_alert_emails();
    } catch (Throwable $e) {
        error_log('[sysmon] Falha ao enviar e-mails de alerta: ' . $e->getMessage());
    }
}

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

function count_unread_events(): int {
    $pdo = get_pdo();
    return (int) $pdo->query("SELECT COUNT(*) FROM eventos WHERE visualizado = 0")->fetchColumn();
}

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
            WHERE EXISTS (SELECT 1 FROM metricas mm WHERE mm.codigo = c.codigo)
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

function fetch_fleet_summary(): array {
    $pdo   = get_pdo();
    $comps = fetch_computers();

    $ativos = [];       $stmt = $pdo->query("SELECT codigo, nivel FROM eventos WHERE ativo = 1");
    foreach ($stmt as $r) {
        if ($r['nivel'] === 'critical') { $ativos[$r['codigo']]['critical'] = true; }
        elseif ($r['nivel'] === 'warning') { $ativos[$r['codigo']]['warning'] = true; }
    }

    $cats = [
        'normal'  => ['rotulo' => 'Normal',   'total' => 0, 'pcs' => []],
        'alerta'  => ['rotulo' => 'Em alerta', 'total' => 0, 'pcs' => []],
        'critico' => ['rotulo' => 'Crítico',  'total' => 0, 'pcs' => []],
        'offline' => ['rotulo' => 'Offline',  'total' => 0, 'pcs' => []],
    ];

    $semAgente = 0;
    $pausados  = 0;
    foreach ($comps as $codigo => $info) {
        if ($info['pausada']) {                  $pausados++;
            continue;
        }
        if ($info['sem_agente']) {
            $semAgente++;
            continue;
        }
        if ($info['status'] === 'offline') {
            $cat = 'offline';
        } elseif (!empty($ativos[$codigo]['critical'])) {
            $cat = 'critico';
        } elseif (!empty($ativos[$codigo]['warning']) || $info['status'] === 'warning') {
            $cat = 'alerta';
        } else {
            $cat = 'normal';
        }
        $cats[$cat]['total']++;
        $cats[$cat]['pcs'][] = $codigo;
    }

    $total = count($comps) - $semAgente - $pausados;       $out = [];
    foreach ($cats as $chave => $c) {
        $out[] = [
            'chave'  => $chave,
            'rotulo' => $c['rotulo'],
            'total'  => $c['total'],
            'pcs'    => array_slice($c['pcs'], 0, 12),
        ];
    }

    return [
        'total'        => $total,
        'sem_agente'   => $semAgente,
        'pausados'     => $pausados,
        'cadastrados'  => count($comps),
        'com_problema' => $total - $cats['normal']['total'],
        'categorias'   => $out,
    ];
}

function cleanup_old_events(): void {
    $pdo = get_pdo();
    $pdo->exec("DELETE FROM eventos WHERE criado_em < DATE_SUB(NOW(), INTERVAL 12 MONTH)");
}

function ensure_schema(PDO $pdo, string $dbname): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sysmon_schema_v5_' . md5($dbname . '|' . __DIR__);
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

        foreach (['patrimonio' => 'VARCHAR(10)', 'sala' => 'VARCHAR(100)',
                  'modelo' => 'VARCHAR(150)', 'situacao' => 'VARCHAR(30)'] as $col => $tipo) {
            if (!$hasCol('computadores', $col)) {
                $pdo->exec("ALTER TABLE computadores ADD COLUMN {$col} {$tipo} DEFAULT NULL");
            }
        }

                if (!$hasCol('computadores', 'coleta_pausada')) {
            $pdo->exec('ALTER TABLE computadores ADD COLUMN coleta_pausada TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!$hasCol('computadores', 'pausada_em')) {
            $pdo->exec('ALTER TABLE computadores ADD COLUMN pausada_em DATETIME DEFAULT NULL');
        }
        if (!$hasCol('computadores', 'pausada_por')) {
            $pdo->exec('ALTER TABLE computadores ADD COLUMN pausada_por VARCHAR(50) DEFAULT NULL');
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

                $pdo->exec(
            'CREATE TABLE IF NOT EXISTS planta_marcadores (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                planta      VARCHAR(40)  NOT NULL,
                nome        VARCHAR(100) NOT NULL,
                x           DECIMAL(6,3) NOT NULL,
                y           DECIMAL(6,3) NOT NULL,
                criado_por  VARCHAR(50)  DEFAULT NULL,
                criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_planta (planta)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS planta_marcador_salas (
                sala         VARCHAR(100) NOT NULL PRIMARY KEY,
                marcador_id  INT NOT NULL,
                CONSTRAINT fk_pms_marcador
                    FOREIGN KEY (marcador_id) REFERENCES planta_marcadores(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        seed_admin_emails($pdo);
        @file_put_contents($marker, 'ok');
    } catch (Throwable $e) {
                        error_log('[sysmon] ensure_schema: ' . $e->getMessage());
    }
}

function seed_admin_emails(PDO $pdo): void {
    $stmt = $pdo->prepare(
        "UPDATE usuarios SET email = ?
         WHERE usuario = ? AND (email IS NULL OR email = '')"
    );
    foreach (ADMIN_DEFAULT_EMAILS as $usuario => $email) {
        $stmt->execute([$email, $usuario]);
    }
}

function normalize_email(string $email): ?string {
    $email = trim($email);
    if ($email === '' || strlen($email) > 150) {
        return null;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function fetch_email_recipients(): array {
    $pdo = get_pdo();
    seed_admin_emails($pdo);
    return $pdo->query(
        "SELECT usuario, email FROM usuarios
         WHERE ativo = 1 AND notificar_email = 1
           AND email IS NOT NULL AND email <> ''"
    )->fetchAll();
}

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

function add_maintenance(string $codigo, string $tipo, string $descricao, string $realizadoEm, string $usuario): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO manutencoes (codigo, tipo, descricao, realizado_em, registrado_por)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$codigo, $tipo, $descricao, $realizadoEm, $usuario]);
    return (int) $pdo->lastInsertId();
}

function delete_maintenance(int $id): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM manutencoes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function fetch_problem_history(string $periodo): array {
    $pdo  = get_pdo();
    $dias = $periodo === 'mes' ? 30 : 7;

    $stmt = $pdo->query(
        "SELECT e.id, e.codigo, e.chave, e.nivel, e.mensagem, e.ativo,
                e.criado_em, e.resolvido_em,
                TIMESTAMPDIFF(MINUTE, e.criado_em, COALESCE(e.resolvido_em, NOW())) AS dur_min,
                c.label
         FROM eventos e
         JOIN computadores c ON c.codigo = e.codigo
         WHERE e.criado_em >= DATE_SUB(NOW(), INTERVAL {$dias} DAY)
           AND e.nivel IN ('warning', 'critical', 'offline')
         ORDER BY e.criado_em DESC
         LIMIT 3000"
    );
    $rows = $stmt->fetchAll();

    $hoje = $pdo->query('SELECT CURDATE()')->fetchColumn();

        $porDia = [];
    for ($i = $dias - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("{$hoje} -{$i} day"));
        $porDia[$d] = ['dia' => $d, 'total' => 0, 'criticos' => 0];
    }

    $totais = ['total' => 0, 'criticos' => 0, 'offline' => 0, 'alerta' => 0,
               'em_andamento' => 0, 'minutos' => 0];
    $porPc = [];
    $porChave = [];
    $eventos = [];

    foreach ($rows as $r) {
        $nivel = $r['nivel'];
        $dur   = max(0, (int) $r['dur_min']);
        $vermelho = ($nivel === 'critical' || $nivel === 'offline');

        $totais['total']++;
        if ($nivel === 'critical') { $totais['criticos']++; }
        elseif ($nivel === 'offline') { $totais['offline']++; }
        else { $totais['alerta']++; }
        if ((int) $r['ativo'] === 1) { $totais['em_andamento']++; }
        $totais['minutos'] += $dur;

        $dia = substr((string) $r['criado_em'], 0, 10);
        if (isset($porDia[$dia])) {
            $porDia[$dia]['total']++;
            if ($vermelho) { $porDia[$dia]['criticos']++; }
        }

        $cod = $r['codigo'];
        if (!isset($porPc[$cod])) {
            $porPc[$cod] = ['codigo' => $cod, 'label' => $r['label'],
                            'total' => 0, 'criticos' => 0, 'minutos' => 0];
        }
        $porPc[$cod]['total']++;
        if ($vermelho) { $porPc[$cod]['criticos']++; }
        $porPc[$cod]['minutos'] += $dur;

        $chave = (string) $r['chave'];
        if (!isset($porChave[$chave])) {
            $porChave[$chave] = [
                'chave'  => $chave,
                'rotulo' => $chave === 'status' ? 'Offline / sem resposta' : metric_label($chave),
                'total'  => 0,
            ];
        }
        $porChave[$chave]['total']++;

        if (count($eventos) < 200) {
            $eventos[] = [
                'id'           => (int) $r['id'],
                'codigo'       => $cod,
                'label'        => $r['label'],
                'nivel'        => $nivel,
                'mensagem'     => $r['mensagem'],
                'ativo'        => (bool) $r['ativo'],
                'criado_em'    => $r['criado_em'],
                'resolvido_em' => $r['resolvido_em'],
                'dur_min'      => $dur,
            ];
        }
    }

    $porPc = array_values($porPc);
    usort($porPc, function ($a, $b) {
        return [$b['criticos'], $b['total']] <=> [$a['criticos'], $a['total']];
    });
    $porChave = array_values($porChave);
    usort($porChave, function ($a, $b) { return $b['total'] <=> $a['total']; });

    $totais['pcs_afetados'] = count($porPc);

    return [
        'periodo'  => $periodo === 'mes' ? 'mes' : 'semana',
        'dias'     => $dias,
        'totais'   => $totais,
        'por_dia'  => array_values($porDia),
        'por_pc'   => array_slice($porPc, 0, 15),
        'por_tipo' => array_slice($porChave, 0, 10),
        'eventos'  => $eventos,
        'truncado' => count($rows) >= 3000,
    ];
}
