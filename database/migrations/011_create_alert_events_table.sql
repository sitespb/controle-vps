-- ---------------------------------------------------------------------------
-- alert_events - linha do tempo de cada alerta (secao 18 do PLAN)
--
-- Registra criacao, reincidencia, reconhecimento e resolucao. Permite
-- responder "quando isso comecou e quando parou" sem inspecionar logs.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alert_events` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `alert_id`   INT UNSIGNED NOT NULL,
    `event`      ENUM('created','recurred','acknowledged','resolved','reopened') NOT NULL,
    `message`    VARCHAR(255) NULL DEFAULT NULL,
    `user_id`    INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = gerado pelo sistema/cron',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_alert_events_alert` (`alert_id`, `created_at`),
    CONSTRAINT `fk_alert_events_alert` FOREIGN KEY (`alert_id`)
        REFERENCES `alerts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alert_events_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
