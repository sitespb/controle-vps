-- ---------------------------------------------------------------------------
-- alerts - alertas internos (secoes 18, 28 e 29 do PLAN)
--
-- DEDUPLICACAO: `fingerprint` = sha1(tipo|server_id|site_id). O indice
-- (fingerprint, status) permite localizar em O(1) se ja existe um alerta
-- ABERTO para aquele problema. Enquanto ele existir, novas ocorrencias apenas
-- incrementam `occurrences` e atualizam `last_seen_at` - nao criam linha nova.
-- E isso que cumpre "nao gerar dezenas de alertas iguais".
--
-- RESOLUCAO AUTOMATICA: quando a condicao deixa de existir, o mesmo
-- fingerprint e usado para marcar `status` = resolved e preencher `resolved_at`.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alerts` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id`       INT UNSIGNED NULL DEFAULT NULL,
    `site_id`         INT UNSIGNED NULL DEFAULT NULL,
    `type`            VARCHAR(40) NOT NULL COMMENT 'server_offline, server_disk_high, ssl_expiring, ...',
    `severity`        ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    `title`           VARCHAR(190) NOT NULL,
    `message`         TEXT NOT NULL,
    `metric_value`    DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Valor que disparou (ex.: 87.4 de disco)',
    `status`          ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
    `fingerprint`     CHAR(40) NOT NULL COMMENT 'sha1(type|server_id|site_id) - chave de deduplicacao',
    `occurrences`     INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `acknowledged_at` DATETIME NULL DEFAULT NULL,
    `acknowledged_by` INT UNSIGNED NULL DEFAULT NULL,
    `resolved_at`     DATETIME NULL DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_alerts_fingerprint_status` (`fingerprint`, `status`),
    KEY `idx_alerts_status_severity` (`status`, `severity`),
    KEY `idx_alerts_server` (`server_id`, `status`),
    KEY `idx_alerts_site` (`site_id`, `status`),
    KEY `idx_alerts_created` (`created_at`),
    KEY `idx_alerts_type` (`type`),
    CONSTRAINT `fk_alerts_server` FOREIGN KEY (`server_id`)
        REFERENCES `servers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alerts_site` FOREIGN KEY (`site_id`)
        REFERENCES `sites` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alerts_user` FOREIGN KEY (`acknowledged_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
