-- ---------------------------------------------------------------------------
-- audit_logs - eventos administrativos (secao 31 do PLAN)
--
-- Registra: login, logout, criacao/edicao/exclusao de servidor, regeneracao
-- de token, comunicacao de agente, erros de API, mudancas de configuracao.
--
-- NUNCA registra: senha, token completo, dados sensiveis. O Logger::redact()
-- mascara automaticamente as chaves conhecidas antes de gravar em `context`.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = sistema, cron ou agente',
    `actor`       VARCHAR(120) NULL DEFAULT NULL COMMENT 'Nome legivel de quem agiu',
    `action`      VARCHAR(60) NOT NULL COMMENT 'login, server.created, token.regenerated, ...',
    `entity_type` VARCHAR(40) NULL DEFAULT NULL,
    `entity_id`   INT UNSIGNED NULL DEFAULT NULL,
    `description` VARCHAR(255) NOT NULL,
    `level`       ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    `ip`          VARCHAR(45) NULL DEFAULT NULL,
    `user_agent`  VARCHAR(255) NULL DEFAULT NULL,
    `context`     JSON NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`, `created_at`),
    KEY `idx_audit_action` (`action`, `created_at`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_level` (`level`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
