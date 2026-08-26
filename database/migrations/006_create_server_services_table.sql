-- ---------------------------------------------------------------------------
-- server_services - estado dos servicos do VPS (secao 6 do PLAN)
--
-- Uma linha por servico por servidor, atualizada a cada coleta (upsert).
-- Ausencia de um servico NAO e erro critico: servidores diferentes tem
-- configuracoes diferentes. Por isso o status 'unknown' existe e nao gera
-- alerta.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `server_services` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id`  INT UNSIGNED NOT NULL,
    `name`       VARCHAR(60) NOT NULL COMMENT 'openlitespeed, mariadb, redis, cyberpanel, php',
    `label`      VARCHAR(80) NULL DEFAULT NULL,
    `status`     ENUM('running','stopped','unknown','not_installed') NOT NULL DEFAULT 'unknown',
    `version`    VARCHAR(60) NULL DEFAULT NULL,
    `detail`     VARCHAR(190) NULL DEFAULT NULL,
    `checked_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_services_server_name` (`server_id`, `name`),
    KEY `idx_services_status` (`status`),
    CONSTRAINT `fk_services_server` FOREIGN KEY (`server_id`)
        REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
