-- ---------------------------------------------------------------------------
-- servers - VPS monitorados (secoes 11, 12 e 22 do PLAN)
--
-- Os campos de identificacao (name, provider, hostname, ip, description) sao
-- preenchidos no cadastro manual. Os campos de sistema (os_*, kernel, arch,
-- cpu_cores, uptime, agent_version, public_ip) sao sobrescritos pelo agente
-- a cada heartbeat.
--
-- is_demo marca as linhas criadas pelo seeder de demonstracao (secao 38).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `servers` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid`            CHAR(32) NOT NULL COMMENT 'Identificacao unica publica do servidor',
    `name`           VARCHAR(120) NOT NULL,
    `provider`       VARCHAR(120) NULL DEFAULT NULL,
    `hostname`       VARCHAR(190) NULL DEFAULT NULL,
    `ip`             VARCHAR(45) NULL DEFAULT NULL,
    `description`    TEXT NULL DEFAULT NULL,
    `status`         ENUM('online','warning','offline','unknown') NOT NULL DEFAULT 'unknown',

    -- Preenchido pelo agente
    `public_ip`      VARCHAR(45) NULL DEFAULT NULL,
    `os_name`        VARCHAR(120) NULL DEFAULT NULL,
    `os_version`     VARCHAR(60) NULL DEFAULT NULL,
    `arch`           VARCHAR(30) NULL DEFAULT NULL,
    `kernel`         VARCHAR(120) NULL DEFAULT NULL,
    `cpu_cores`      SMALLINT UNSIGNED NULL DEFAULT NULL,
    `cpu_model`      VARCHAR(190) NULL DEFAULT NULL,
    `uptime`         BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Segundos',
    `agent_version`  VARCHAR(20) NULL DEFAULT NULL,
    `cyberpanel_version` VARCHAR(40) NULL DEFAULT NULL,

    `last_seen_at`   DATETIME NULL DEFAULT NULL COMMENT 'Ultimo heartbeat recebido',
    `last_metric_at` DATETIME NULL DEFAULT NULL COMMENT 'Ultima coleta de metricas',
    `is_demo`        TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_servers_uid` (`uid`),
    KEY `idx_servers_status` (`status`),
    KEY `idx_servers_last_seen` (`last_seen_at`),
    KEY `idx_servers_name` (`name`),
    KEY `idx_servers_is_demo` (`is_demo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
