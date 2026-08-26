-- ---------------------------------------------------------------------------
-- sites - dominios descobertos automaticamente (secoes 7, 8 e 14 do PLAN)
--
-- O operador NAO cadastra sites: o agente descobre os dominios no CyberPanel /
-- OpenLiteSpeed e envia a lista. A chave unica (server_id, domain) garante
-- idempotencia - reenviar a mesma lista atualiza em vez de duplicar.
--
-- Um dominio que some da lista enviada pelo agente nao e apagado: ele recebe
-- `discovered` = 0 e para de contar como ativo. Isso evita perder historico
-- por causa de uma coleta incompleta (secao 21: nao apagar dados importantes).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sites` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id`          INT UNSIGNED NOT NULL,
    `domain`             VARCHAR(190) NOT NULL,
    `url`                VARCHAR(255) NULL DEFAULT NULL,
    `status`             ENUM('online','warning','offline','unknown') NOT NULL DEFAULT 'unknown',
    `http_status`        SMALLINT UNSIGNED NULL DEFAULT NULL,
    `response_time`      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Milissegundos',
    `https_available`    TINYINT(1) NOT NULL DEFAULT 0,
    `ip`                 VARCHAR(45) NULL DEFAULT NULL,
    `php_version`        VARCHAR(20) NULL DEFAULT NULL,
    `wordpress_detected` TINYINT(1) NOT NULL DEFAULT 0,
    `wordpress_version`  VARCHAR(20) NULL DEFAULT NULL,
    `document_root`      VARCHAR(255) NULL DEFAULT NULL,
    `last_error`         VARCHAR(255) NULL DEFAULT NULL,
    `last_check_at`      DATETIME NULL DEFAULT NULL,
    `last_online_at`     DATETIME NULL DEFAULT NULL,
    `discovered`         TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = sumiu da ultima descoberta',
    `is_demo`            TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sites_server_domain` (`server_id`, `domain`),
    KEY `idx_sites_domain` (`domain`),
    KEY `idx_sites_status` (`status`),
    KEY `idx_sites_last_check` (`last_check_at`),
    KEY `idx_sites_wordpress` (`wordpress_detected`),
    KEY `idx_sites_is_demo` (`is_demo`),
    CONSTRAINT `fk_sites_server` FOREIGN KEY (`server_id`)
        REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
