-- ---------------------------------------------------------------------------
-- Avisos ao administrador: e-mail (SMTP) e WhatsApp (RyzeAPI)
--
-- POR QUE UMA TABELA PROPRIA, E NAO `settings`
-- ---------------------------------------------------------------------------
-- As settings existentes sao lidas em bloco e gravadas num cache de arquivo
-- (storage/cache/settings.php) para nao consultar o banco a cada pagina. Um
-- segredo passando por ali acabaria em texto claro num arquivo do disco - o
-- oposto do que se quer ao cifrar. Por isso a configuracao de avisos tem
-- tabela propria, lida sob demanda e nunca cacheada em arquivo.
--
-- `is_secret` marca as linhas cujo `value` esta cifrado (AES-256-GCM com a
-- APP_KEY). A coluna existe para que a leitura saiba decifrar sem depender de
-- uma lista de nomes espalhada pelo codigo.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_settings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel`    ENUM('email','whatsapp') NOT NULL,
    `key`        VARCHAR(60) NOT NULL,
    `value`      TEXT NULL DEFAULT NULL,
    `is_secret`  TINYINT(1) NOT NULL DEFAULT 0,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_settings` (`channel`, `key`),
    CONSTRAINT `fk_notification_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notification_log - historico de envios
--
-- Serve a DOIS propositos, e por isso e uma tabela e nao so uma linha de log:
--
--   1. Limite de envio. "Este dominio ja foi avisado nas ultimas 6 horas?" e
--      "quantas mensagens sairam na ultima hora?" sao perguntas que o banco
--      responde em O(indice). Sem isto, uma queda do servidor inteiro
--      dispararia uma mensagem por dominio de uma vez - o caminho mais curto
--      para o provedor bloquear a conta.
--
--   2. Diagnostico. Quando o operador diz "nao recebi o aviso", a resposta
--      esta aqui: nao foi enviado (limite), foi enviado e falhou (com o erro
--      do provedor), ou foi enviado com sucesso e o problema e na caixa dele.
--
-- `site_id` e ON DELETE SET NULL de proposito: apagar um site nao pode apagar
-- a evidencia de que o aviso saiu.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel`    ENUM('email','whatsapp') NOT NULL,
    `event`      VARCHAR(40) NOT NULL COMMENT 'site_offline, site_online, teste',
    `site_id`    INT UNSIGNED NULL DEFAULT NULL,
    `domain`     VARCHAR(190) NULL DEFAULT NULL COMMENT 'copia do dominio: sobrevive a exclusao do site',
    `recipient`  VARCHAR(255) NOT NULL,
    `status`     ENUM('sent','failed','skipped') NOT NULL,
    `error`      VARCHAR(255) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_notification_log_rate` (`channel`, `status`, `created_at`),
    KEY `idx_notification_log_domain` (`domain`, `event`, `created_at`),
    KEY `idx_notification_log_site` (`site_id`),
    CONSTRAINT `fk_notification_log_site` FOREIGN KEY (`site_id`)
        REFERENCES `sites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sites.notify_muted - "estou ciente deste dominio"
--
-- Marcado pelo operador quando ele ja sabe do problema e nao quer ser avisado
-- de novo. Some sozinho quando o site volta a responder (ver
-- AlertService::siteCameBack), para que uma queda futura volte a avisar sem
-- ninguem precisar lembrar de desligar o switcher.
-- ---------------------------------------------------------------------------
ALTER TABLE `sites`
    ADD COLUMN `notify_muted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `discovered`,
    ADD COLUMN `notify_muted_at` DATETIME NULL DEFAULT NULL AFTER `notify_muted`,
    ADD COLUMN `notify_muted_by` INT UNSIGNED NULL DEFAULT NULL AFTER `notify_muted_at`,
    ADD KEY `idx_sites_notify_muted` (`notify_muted`);
