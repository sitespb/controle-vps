-- ---------------------------------------------------------------------------
-- settings - configuracoes editaveis pelo painel (secao 19 do PLAN)
--
-- Sobrepoem os padroes de config/monitoring.php em runtime. Isso atende ao
-- "esses valores deverao ficar configuraveis futuramente" ja na V1, sem
-- precisar editar arquivo no servidor.
--
-- `key` usa a mesma notacao de ponto do Config:
--   monitoring.thresholds.disk.warning
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`         VARCHAR(80) NOT NULL,
    `value`       TEXT NULL DEFAULT NULL,
    `type`        ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
    `group`       VARCHAR(40) NOT NULL DEFAULT 'geral',
    `label`       VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `unit`        VARCHAR(20) NULL DEFAULT NULL,
    `min_value`   DECIMAL(10,2) NULL DEFAULT NULL,
    `max_value`   DECIMAL(10,2) NULL DEFAULT NULL,
    `sort_order`  SMALLINT NOT NULL DEFAULT 0,
    `updated_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`key`),
    KEY `idx_settings_group` (`group`, `sort_order`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
