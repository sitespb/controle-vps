-- ---------------------------------------------------------------------------
-- site_checks - historico de verificacoes de cada dominio (secoes 15 e 29)
--
-- Gravamos uma linha por coleta. `status_changed` marca as transicoes
-- (online -> offline e vice-versa), o que permite montar a linha do tempo da
-- pagina individual do site sem varrer todo o historico.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `site_checks` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED NOT NULL,
    `status`         ENUM('online','warning','offline','unknown') NOT NULL DEFAULT 'unknown',
    `http_status`    SMALLINT UNSIGNED NULL DEFAULT NULL,
    `response_time`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'Milissegundos',
    `error`          VARCHAR(255) NULL DEFAULT NULL,
    `status_changed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_checks_site_time` (`site_id`, `created_at`),
    KEY `idx_checks_created` (`created_at`),
    KEY `idx_checks_changed` (`site_id`, `status_changed`, `created_at`),
    CONSTRAINT `fk_checks_site` FOREIGN KEY (`site_id`)
        REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
