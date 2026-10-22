<?php
/**
 * Conexão única (singleton) com o banco de dados via PDO.
 */
function get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $cfg = require __DIR__ . '/config.php';
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $cfg['dbname']
        );

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Não expõe detalhes sensíveis da exceção ao cliente.
            error_log('[sysmon] Falha ao conectar no banco: ' . $e->getMessage());
            json_response(['error' => 'Falha na conexão com o banco de dados.'], 500);
        }

        // Cria colunas/tabelas novas (e-mail dos usuários, manutenções) se
        // o banco ainda for da versão anterior. Definida em data.php.
        if (function_exists('ensure_schema')) {
            ensure_schema($pdo, $cfg['dbname']);
        }
    }

    return $pdo;
}
