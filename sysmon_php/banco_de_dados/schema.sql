
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

INSERT INTO computadores (codigo, label, location)
SELECT 'CM000001', 'Servidor Principal', 'Rack A1'
WHERE NOT EXISTS (SELECT 1 FROM computadores WHERE codigo = 'CM000001');

ALTER TABLE computadores
    ADD COLUMN mobo_manufacturer VARCHAR(100) DEFAULT NULL,
    ADD COLUMN mobo_model        VARCHAR(150) DEFAULT NULL,
    ADD COLUMN bios_version      VARCHAR(100) DEFAULT NULL;

ALTER TABLE temperaturas
    ADD COLUMN fan_cpu_rpm SMALLINT DEFAULT NULL,
    ADD COLUMN fan_sys_rpm SMALLINT DEFAULT NULL;

ALTER TABLE usuarios
    ADD COLUMN criado_por VARCHAR(50) DEFAULT NULL;

ALTER TABLE computadores
    ADD COLUMN last_status VARCHAR(20) DEFAULT NULL;

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

UPDATE usuarios SET email = 'maikson.santiago@escolar.ifrn.edu.br' WHERE usuario = 'maikson' AND (email IS NULL OR email = '');
UPDATE usuarios SET email = 'samuel.pedrosa@escolar.ifrn.edu.br'   WHERE usuario = 'samuel'  AND (email IS NULL OR email = '');
UPDATE usuarios SET email = 'm.gessle@escolar.ifrn.edu.br'          WHERE usuario = 'matheus' AND (email IS NULL OR email = '');

ALTER TABLE computadores ADD COLUMN patrimonio VARCHAR(10) DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN sala VARCHAR(100) DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN modelo VARCHAR(150) DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN situacao VARCHAR(30) DEFAULT NULL;


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

ALTER TABLE computadores ADD COLUMN coleta_pausada TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE computadores ADD COLUMN pausada_em DATETIME DEFAULT NULL;
ALTER TABLE computadores ADD COLUMN pausada_por VARCHAR(50) DEFAULT NULL;

