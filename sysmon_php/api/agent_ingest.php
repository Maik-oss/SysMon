<?php
/**
 * Recebe, por HTTPS, as leituras que o agente coleta em cada computador.
 *
 * Por que existe: o agente antes gravava direto no MySQL (porta 3306), que
 * a rede do campus bloqueia e que obrigava a guardar a senha do banco em
 * cada computador. Agora o agente só envia um POST para cá (porta 443, a
 * mesma do painel) e somente o servidor conversa com o banco.
 *
 * Autenticação: cabeçalho X-SysMon-Key (ou campo "chave" no JSON) igual à
 * chave de includes/agent_config.php. Sem sessão de usuário.
 */
require_once __DIR__ . '/../includes/data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
}

$cfgFile = __DIR__ . '/../includes/agent_config.php';
$cfg = is_file($cfgFile) ? require $cfgFile : [];
$chaveServidor = (string) ($cfg['key'] ?? '');
if (strlen($chaveServidor) < 24) {
    error_log('[sysmon] agent_config.php sem chave (mínimo de 24 caracteres).');
    json_response(['ok' => false, 'error' => 'Recebimento de dados não configurado no servidor.'], 503);
}

$raw = file_get_contents('php://input', false, null, 0, 131072);   // até 128 KB
$in  = json_decode((string) $raw, true);
if (!is_array($in)) {
    json_response(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$chaveEnviada = (string) ($_SERVER['HTTP_X_SYSMON_KEY'] ?? ($in['chave'] ?? ''));
if (!hash_equals($chaveServidor, $chaveEnviada)) {
    usleep(400000);   // atrasa tentativas de adivinhar a chave
    json_response(['ok' => false, 'error' => 'Chave inválida.'], 401);
}

// ── validação ────────────────────────────────────────────────────────
function ing_dec($v, int $inteiros, int $casas = 1): ?float {
    if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) { return null; }
    $f = round((float) $v, $casas);
    return abs($f) < (10 ** $inteiros) ? $f : null;     // cabe na coluna DECIMAL
}
function ing_int($v, int $min, int $max): ?int {
    if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) { return null; }
    $i = (int) round((float) $v);
    return ($i >= $min && $i <= $max) ? $i : null;
}
function ing_txt($v, int $max): ?string {
    if ($v === null || is_array($v)) { return null; }
    $s = trim((string) $v);
    return $s === '' ? null : mb_substr($s, 0, $max);
}
function ing_json($v): ?string {
    if (!is_array($v)) { return null; }
    $j = json_encode($v);
    return ($j !== false && strlen($j) <= 8192) ? $j : null;
}
function ing_sec($arr, string $k): array {
    return (isset($arr[$k]) && is_array($arr[$k])) ? $arr[$k] : [];
}

$codigo = strtoupper(trim((string) ($in['codigo'] ?? '')));
if (!preg_match('/^[A-Z0-9_.-]{1,20}$/', $codigo)) {
    json_response(['ok' => false, 'error' => 'Código do computador inválido.'], 400);
}
$label = ing_txt($in['label'] ?? null, 100) ?? $codigo;
$local = ing_txt($in['location'] ?? null, 100) ?? '';

$mobo = ing_sec($in, 'mobo');
$m    = ing_sec($in, 'metrica');
$cpu  = ing_sec($m, 'cpu');
$gpu  = ing_sec($m, 'gpu');
$ram  = ing_sec($m, 'ram');
$disc = ing_sec($m, 'disco');
$rede = ing_sec($m, 'rede');
$tmp  = ing_sec($in, 'temperaturas');

$pdo = get_pdo();   // também cria as colunas/tabelas novas, se faltarem
try {
    $pdo->beginTransaction();

    // 1) cadastra o PC (se novo) ou atualiza. Se veio da planilha do campus
    //    (tem patrimônio), mantém o nome e a sala já cadastrados no painel.
    $pdo->prepare(
        'INSERT INTO computadores (codigo, label, location, mobo_manufacturer, mobo_model, bios_version)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            label = IF(patrimonio IS NOT NULL, label, VALUES(label)),
            location = IF(VALUES(location) = \'\', location, VALUES(location)),
            mobo_manufacturer = VALUES(mobo_manufacturer),
            mobo_model = VALUES(mobo_model),
            bios_version = VALUES(bios_version)'
    )->execute([
        $codigo, $label, $local,
        ing_txt($mobo['manufacturer'] ?? null, 100),
        ing_txt($mobo['model'] ?? null, 150),
        ing_txt($mobo['bios'] ?? null, 100),
    ]);

    // 2) leitura de uso (CPU, GPU, RAM, disco, rede, uptime)
    $pdo->prepare(
        'INSERT INTO metricas (
            codigo, cpu_usage, cpu_model, cpu_freq, cpu_cores,
            gpu_usage, gpu_temp, gpu_vram_pct, gpu_model,
            ram_used, ram_total, disk_used, disk_total,
            net_down, net_up, uptime_h
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $codigo,
        ing_dec($cpu['usage'] ?? null, 4), ing_txt($cpu['model'] ?? null, 100),
        ing_dec($cpu['freq'] ?? null, 3, 2), ing_json($cpu['cores'] ?? null),
        ing_dec($gpu['usage'] ?? null, 4), ing_dec($gpu['temp'] ?? null, 4),
        ing_dec($gpu['vram_pct'] ?? null, 4), ing_txt($gpu['model'] ?? null, 100),
        ing_dec($ram['used'] ?? null, 6), ing_dec($ram['total'] ?? null, 6),
        ing_dec($disc['used'] ?? null, 8), ing_dec($disc['total'] ?? null, 8),
        ing_dec($rede['down'] ?? null, 8), ing_dec($rede['up'] ?? null, 8),
        ing_int($m['uptime_h'] ?? null, 0, 2147483647),
    ]);

    // 3) temperaturas (campos vazios ficam NULL: o painel sabe lidar com isso)
    $pdo->prepare(
        'INSERT INTO temperaturas (
            codigo, cpu_package, cpu_cores, gpu_die, gpu_mem,
            nvme_1, nvme_2, psu, motherboard, ambient, fan_cpu_rpm, fan_sys_rpm
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $codigo,
        ing_dec($tmp['cpu_package'] ?? null, 4), ing_json($tmp['cpu_cores'] ?? null),
        ing_dec($tmp['gpu_die'] ?? null, 4), ing_dec($tmp['gpu_mem'] ?? null, 4),
        ing_dec($tmp['nvme_1'] ?? null, 4), ing_dec($tmp['nvme_2'] ?? null, 4),
        ing_dec($tmp['psu'] ?? null, 4), ing_dec($tmp['motherboard'] ?? null, 4),
        ing_dec($tmp['ambient'] ?? null, 4),
        ing_int($tmp['fan_cpu_rpm'] ?? null, 0, 32767), ing_int($tmp['fan_sys_rpm'] ?? null, 0, 32767),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('[sysmon] agent_ingest falhou para ' . $codigo . ': ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Falha ao gravar os dados.'], 500);
}

json_response(['ok' => true, 'codigo' => $codigo]);
