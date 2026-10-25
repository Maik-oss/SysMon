-- ============================================================
-- SysMon — schema do banco de dados
-- Banco alvo: piprojeto2 (já criado pelo provedor DBaaS)
-- Rode este script UMA VEZ, conectado ao banco "piprojeto2"
-- (ex.: via phpMyAdmin do painel, HeidiSQL, DBeaver, ou:
--   mysql -h piprojeto2.mysql.dbaas.com.br -u piprojeto2 -p piprojeto2 < schema.sql
-- ============================================================

-- Computadores monitorados (cadastrados manualmente ou
-- auto-registrados pelo agente na primeira execução).
CREATE TABLE IF NOT EXISTS computadores (
    codigo             VARCHAR(20) PRIMARY KEY,
    label              VARCHAR(100) NOT NULL,
    location           VARCHAR(100) DEFAULT NULL,
    patrimonio         VARCHAR(10)  DEFAULT NULL,
    sala               VARCHAR(100) DEFAULT NULL,
    modelo             VARCHAR(150) DEFAULT NULL,
    situacao           VARCHAR(30)  DEFAULT NULL,
    mobo_manufacturer  VARCHAR(100) DEFAULT NULL,
    mobo_model         VARCHAR(150) DEFAULT NULL,
    bios_version       VARCHAR(100) DEFAULT NULL,
    criado_em          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuários do painel (login do dashboard). Os usuários definidos em
-- ADMIN_USERNAMES (includes/data.php) são os únicos que podem cadastrar
-- novos acessos (ver tela "Usuários" no painel) — os demais usuários
-- criados por eles ficam registrados aqui também, com "criado_por"
-- preenchido para saber quem cadastrou.
CREATE TABLE IF NOT EXISTS usuarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario     VARCHAR(50) NOT NULL UNIQUE,
    senha_hash  VARCHAR(255) NOT NULL,
    email       VARCHAR(150) DEFAULT NULL,
    notificar_email TINYINT(1) NOT NULL DEFAULT 1,
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    criado_por  VARCHAR(50) DEFAULT NULL,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Série histórica de métricas. Cada execução do agente insere
-- UMA linha nova (não faz UPDATE) — assim dá para ver histórico
-- e o dashboard sempre lê a mais recente por "codigo".
CREATE TABLE IF NOT EXISTS metricas (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(20) NOT NULL,

    cpu_usage     DECIMAL(5,1)  DEFAULT NULL,
    cpu_temp      DECIMAL(5,1)  DEFAULT NULL,
    cpu_model     VARCHAR(100)  DEFAULT NULL,
    cpu_freq      DECIMAL(5,2)  DEFAULT NULL,
    cpu_cores     JSON          DEFAULT NULL,

    gpu_usage     DECIMAL(5,1)  DEFAULT NULL,
    gpu_temp      DECIMAL(5,1)  DEFAULT NULL,
    gpu_vram_pct  DECIMAL(5,1)  DEFAULT NULL,
    gpu_model     VARCHAR(100)  DEFAULT NULL,

    ram_used      DECIMAL(7,1)  DEFAULT NULL,
    ram_total     DECIMAL(7,1)  DEFAULT NULL,

    disk_used     DECIMAL(9,1)  DEFAULT NULL,
    disk_total    DECIMAL(9,1)  DEFAULT NULL,

    psu_watts     DECIMAL(6,1)  DEFAULT NULL,
    psu_max       DECIMAL(6,1)  DEFAULT NULL,
    psu_temp      DECIMAL(5,1)  DEFAULT NULL,

    net_down      DECIMAL(9,1)  DEFAULT NULL,
    net_up        DECIMAL(9,1)  DEFAULT NULL,

    uptime_h      INT           DEFAULT NULL,

    criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_codigo_criado (codigo, criado_em),
    CONSTRAINT fk_metricas_computador
        FOREIGN KEY (codigo) REFERENCES computadores(codigo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Série histórica de temperaturas (separada por ser opcional/nem
-- sempre disponível via WMI puro — ver observação no agente).
CREATE TABLE IF NOT EXISTS temperaturas (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(20) NOT NULL,

    cpu_package   DECIMAL(5,1) DEFAULT NULL,
    cpu_cores     JSON         DEFAULT NULL,
    gpu_die       DECIMAL(5,1) DEFAULT NULL,
    gpu_mem       DECIMAL(5,1) DEFAULT NULL,
    nvme_1        DECIMAL(5,1) DEFAULT NULL,
    nvme_2        DECIMAL(5,1) DEFAULT NULL,
    psu           DECIMAL(5,1) DEFAULT NULL,
    motherboard   DECIMAL(5,1) DEFAULT NULL,
    ambient       DECIMAL(5,1) DEFAULT NULL,
    fan_cpu_rpm   SMALLINT     DEFAULT NULL,
    fan_sys_rpm   SMALLINT     DEFAULT NULL,

    criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_codigo_criado (codigo, criado_em),
    CONSTRAINT fk_temperaturas_computador
        FOREIGN KEY (codigo) REFERENCES computadores(codigo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Dados iniciais ─────────────────────────────────────────
-- Este script NÃO cria o usuário admin com senha pronta, porque a senha
-- precisa ser cifrada com password_hash() do PHP (bcrypt), e isso deve
-- rodar no seu servidor. Depois de rodar este schema.sql, execute uma
-- vez o arquivo banco_de_dados/criar_admin.php (veja instruções nele)
-- para criar o primeiro usuário do painel.

-- Exemplo de computadores — o agente também cria/atualiza essa
-- linha automaticamente na primeira execução, então isto é opcional.
INSERT INTO computadores (codigo, label, location)
SELECT 'CM000001', 'Servidor Principal', 'Rack A1'
WHERE NOT EXISTS (SELECT 1 FROM computadores WHERE codigo = 'CM000001');

-- ── Migração (se o banco já existia antes destas colunas) ──
-- Se você já rodou este schema.sql antes e só precisa adicionar as
-- colunas novas (modelo/fabricante da placa-mãe, versão da BIOS,
-- RPM dos fans), rode os comandos abaixo uma vez.
--
-- IMPORTANTE: seu servidor é MySQL/Percona 5.7, que NÃO suporta a
-- sintaxe "ADD COLUMN IF NOT EXISTS" (só existe a partir do MySQL
-- 8.0.29). Por isso os comandos abaixo são ALTER TABLE simples — se
-- você rodar duas vezes (colunas já existindo), vai dar erro "Duplicate
-- column name", o que é esperado e inofensivo: só significa que a
-- coluna já foi criada antes.
ALTER TABLE computadores
    ADD COLUMN mobo_manufacturer VARCHAR(100) DEFAULT NULL,
    ADD COLUMN mobo_model        VARCHAR(150) DEFAULT NULL,
    ADD COLUMN bios_version      VARCHAR(100) DEFAULT NULL;

ALTER TABLE temperaturas
    ADD COLUMN fan_cpu_rpm SMALLINT DEFAULT NULL,
    ADD COLUMN fan_sys_rpm SMALLINT DEFAULT NULL;

-- Guarda quem cadastrou cada usuário extra do painel (ex.: qual
-- administrador criou aquele acesso), só para exibir na lista de
-- usuários. Mesmo aviso de sempre: se a coluna já existir, o ALTER
-- abaixo dá erro "Duplicate column name" — é esperado e inofensivo.
ALTER TABLE usuarios
    ADD COLUMN criado_por VARCHAR(50) DEFAULT NULL;

-- Guarda o último status conhecido de cada computador (online/warning/
-- offline), só para o sistema saber quando ele MUDOU de estado e
-- registrar um evento novo em "eventos" (em vez de registrar toda
-- hora que a página é atualizada).
ALTER TABLE computadores
    ADD COLUMN last_status VARCHAR(20) DEFAULT NULL;

-- ── Notificações / histórico de problemas ───────────────────────────
-- Cada linha é um evento: uma máquina ficou em alerta/offline, ou uma
-- métrica/temperatura passou do limite (barra vermelha). Fica salvo
-- mesmo depois que o problema é resolvido, servindo de histórico.
--
--   tipo:  'status' (ficou em Alerta/Offline) | 'metrica' | 'temperatura'
--   chave: qual métrica (ex: 'cpu_usage', 'cpu_temp', 'status') — usado
--          para saber se aquele problema específico já está "ativo"
--          (em andamento) e não registrar duplicado toda hora.
--   nivel: 'warning' | 'critical' | 'offline' | 'recuperado'
--   ativo: 1 enquanto o problema persiste, 0 quando volta ao normal.
--   visualizado: 0 = notificação nova (não lida), 1 = já vista.
CREATE TABLE IF NOT EXISTS eventos (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    codigo           VARCHAR(20) NOT NULL,
    tipo             VARCHAR(20) NOT NULL,
    chave            VARCHAR(40) DEFAULT NULL,
    nivel            VARCHAR(20) NOT NULL,
    valor            DECIMAL(7,1) DEFAULT NULL,
    mensagem         VARCHAR(255) NOT NULL,
    ativo            TINYINT(1) NOT NULL DEFAULT 1,
    visualizado      TINYINT(1) NOT NULL DEFAULT 0,
    visualizado_por  VARCHAR(50) DEFAULT NULL,
    visualizado_em   TIMESTAMP NULL DEFAULT NULL,
    resolvido_em     TIMESTAMP NULL DEFAULT NULL,
    criado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_codigo_criado (codigo, criado_em),
    INDEX idx_ativo (codigo, chave, ativo),
    INDEX idx_visualizado (visualizado),
    CONSTRAINT fk_eventos_computador
        FOREIGN KEY (codigo) REFERENCES computadores(codigo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Histórico de manutenção ─────────────────────────────────────────
-- Cada linha é uma manutenção feita em um computador: quando foi feita
-- (realizado_em) e quem registrou (registrado_por = usuário logado).
-- Apagar o computador apaga o histórico dele (ON DELETE CASCADE).
CREATE TABLE IF NOT EXISTS manutencoes (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    codigo          VARCHAR(20) NOT NULL,
    tipo            VARCHAR(20) NOT NULL DEFAULT 'outro',
    descricao       TEXT NOT NULL,
    realizado_em    DATETIME NOT NULL,
    registrado_por  VARCHAR(50) NOT NULL,
    criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_manut_codigo (codigo, realizado_em),
    CONSTRAINT fk_manut_computador
        FOREIGN KEY (codigo) REFERENCES computadores(codigo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── E-mail dos usuários / notificações por e-mail ───────────────────
-- Em instalações NOVAS, as colunas "email" e "notificar_email" já vêm no
-- CREATE TABLE usuarios acima. Em bancos ANTIGOS, o próprio painel cria
-- as colunas/tabela sozinho na primeira requisição (ver ensure_schema()
-- em includes/data.php) — mas, se preferir rodar na mão (MySQL 5.7, sem
-- "IF NOT EXISTS"; dá erro "Duplicate column name" se já existir):
--
--   ALTER TABLE usuarios ADD COLUMN email VARCHAR(150) DEFAULT NULL;
--   ALTER TABLE usuarios ADD COLUMN notificar_email TINYINT(1) NOT NULL DEFAULT 1;
--
-- E-mails dos administradores (só preenche se ainda estiver vazio):
UPDATE usuarios SET email = 'maikson.santiago@escolar.ifrn.edu.br' WHERE usuario = 'maikson' AND (email IS NULL OR email = '');
UPDATE usuarios SET email = 'samuel.pedrosa@escolar.ifrn.edu.br'   WHERE usuario = 'samuel'  AND (email IS NULL OR email = '');
UPDATE usuarios SET email = 'm.gessle@escolar.ifrn.edu.br'          WHERE usuario = 'matheus' AND (email IS NULL OR email = '');

-- ── Cadastro vindo da planilha do campus ────────────────────────────
-- patrimonio, sala, modelo e situacao. O painel cria estas colunas sozinho
-- (ensure_schema) e o script importar_computadores.php preenche os dados.
-- Se rodar à mão e a coluna já existir, "Duplicate column name" é inofensivo.
ALTER TABLE computadores ADD COLUMN patrimonio VARCHAR(10) DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN sala VARCHAR(100) DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN modelo VARCHAR(150) DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN situacao VARCHAR(30) DEFAULT NULL;


-- ── Plantas do campus ───────────────────────────────────────────────
-- Cada marcador é uma bolinha numa posição (x/y em % da imagem) de uma
-- planta (static/plantas/*.svg, lista em includes/plantas.php) e fica
-- ligado a uma ou mais salas do cadastro (computadores.sala). A cor e as
-- contagens vêm do status ao vivo dos computadores. O painel cria estas
-- tabelas sozinho (ensure_schema); rode à mão só se preferir.
CREATE TABLE IF NOT EXISTS planta_marcadores (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    planta      VARCHAR(40)  NOT NULL,
    nome        VARCHAR(100) NOT NULL,
    x           DECIMAL(6,3) NOT NULL,
    y           DECIMAL(6,3) NOT NULL,
    criado_por  VARCHAR(50)  DEFAULT NULL,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_planta (planta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS planta_marcador_salas (
    sala         VARCHAR(100) NOT NULL PRIMARY KEY,
    marcador_id  INT NOT NULL,
    CONSTRAINT fk_pms_marcador
        FOREIGN KEY (marcador_id) REFERENCES planta_marcadores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
