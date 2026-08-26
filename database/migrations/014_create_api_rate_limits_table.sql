-- ---------------------------------------------------------------------------
-- api_rate_limits - rate limiting basico da API (secao 33 do PLAN)
--
-- Uma linha por "bucket" (ex.: agent:12, login:203.0.113.9). O contador e
-- zerado quando a janela expira. Manter em tabela, e nao em arquivo, garante
-- que o limite valha para todos os processos PHP simultaneos.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_rate_limits` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`        VARCHAR(150) NOT NULL,
    `hits`          INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start`  DATETIME NOT NULL,
    `blocked_until` DATETIME NULL DEFAULT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rate_bucket` (`bucket`),
    KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
