-- ---------------------------------------------------------------------------
-- login_attempts - protecao contra forca bruta (secao 33 do PLAN)
--
-- Guarda tentativas por e-mail e por IP. O bloqueio considera as duas chaves
-- para nao permitir que um atacante contorne o limite trocando de e-mail.
-- A senha tentada NUNCA e gravada.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(190) NOT NULL,
    `ip`         VARCHAR(45) NOT NULL,
    `success`    TINYINT(1) NOT NULL DEFAULT 0,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempts_email_time` (`email`, `created_at`),
    KEY `idx_attempts_ip_time` (`ip`, `created_at`),
    KEY `idx_attempts_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
