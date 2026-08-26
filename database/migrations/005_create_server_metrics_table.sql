-- ---------------------------------------------------------------------------
-- server_metrics - historico de uso (secoes 20, 21 e 22 do PLAN)
--
-- Uma linha a cada coleta do agente (padrao: 5 em 5 minutos). Com 12 amostras
-- por hora, um servidor gera ~8.640 linhas em 30 dias - volume que o indice
-- composto (server_id, created_at) resolve sem esforco.
--
-- O cron cron/cleanup.php apaga o que passar de METRICS_RETENTION_DAYS.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `server_metrics` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id`    INT UNSIGNED NOT NULL,

    `cpu_usage`    DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Percentual 0-100',
    `ram_total`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `ram_used`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `ram_available` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `ram_percent`  DECIMAL(5,2) NULL DEFAULT NULL,
    `swap_total`   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `swap_used`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `swap_percent` DECIMAL(5,2) NULL DEFAULT NULL,
    `disk_total`   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `disk_used`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `disk_free`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes',
    `disk_percent` DECIMAL(5,2) NULL DEFAULT NULL,
    `load_1`       DECIMAL(8,2) NULL DEFAULT NULL,
    `load_5`       DECIMAL(8,2) NULL DEFAULT NULL,
    `load_15`      DECIMAL(8,2) NULL DEFAULT NULL,
    `uptime`       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Segundos',
    `processes`    INT UNSIGNED NULL DEFAULT NULL,

    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_metrics_server_time` (`server_id`, `created_at`),
    KEY `idx_metrics_created` (`created_at`),
    CONSTRAINT `fk_metrics_server` FOREIGN KEY (`server_id`)
        REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
