-- ---------------------------------------------------------------------------
-- ssl_certificates - certificado atual de cada dominio (secao 16 do PLAN)
--
-- Um registro por site (UNIQUE em site_id): guardamos o estado corrente, nao
-- o historico. `days_remaining` e desnormalizado de proposito, para que a
-- listagem de sites nao precise calcular data em SQL a cada linha - ele e
-- recalculado pelo cron de alertas todo dia.
--
-- status: valid (verde) | expiring (amarelo) | expired (vermelho) | unknown (cinza)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ssl_certificates` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id`        INT UNSIGNED NOT NULL,
    `issuer`         VARCHAR(190) NULL DEFAULT NULL,
    `subject`        VARCHAR(190) NULL DEFAULT NULL,
    `valid_from`     DATE NULL DEFAULT NULL,
    `valid_until`    DATE NULL DEFAULT NULL,
    `days_remaining` INT NULL DEFAULT NULL COMMENT 'Negativo quando expirado',
    `status`         ENUM('valid','expiring','expired','unknown') NOT NULL DEFAULT 'unknown',
    `error`          VARCHAR(255) NULL DEFAULT NULL,
    `checked_at`     DATETIME NULL DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ssl_site` (`site_id`),
    KEY `idx_ssl_status` (`status`),
    KEY `idx_ssl_valid_until` (`valid_until`),
    KEY `idx_ssl_days` (`days_remaining`),
    CONSTRAINT `fk_ssl_site` FOREIGN KEY (`site_id`)
        REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
