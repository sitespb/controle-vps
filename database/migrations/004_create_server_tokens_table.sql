-- ---------------------------------------------------------------------------
-- server_tokens - credencial de cada agente (secoes 5 e 12 do PLAN)
--
-- O token em texto puro existe UMA UNICA VEZ: no momento em que e exibido
-- para o operador copiar. No banco fica apenas o SHA-256 dele.
--
-- Esse mesmo hash e a chave HMAC usada para assinar as requisicoes do agente:
-- o agente calcula sha256(token) localmente e assina com o resultado, de forma
-- que o token em si nunca trafega na rede. Detalhes em docs/ARQUITETURA.md.
--
-- `token_prefix` guarda apenas os primeiros caracteres, o suficiente para o
-- operador identificar visualmente qual token esta ativo, nunca para usa-lo.
--
-- Regenerar o token cria uma nova linha e preenche `revoked_at` da anterior:
-- o historico fica auditavel e o token antigo deixa de funcionar na hora.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `server_tokens` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id`    INT UNSIGNED NOT NULL,
    `token_hash`   CHAR(64) NOT NULL COMMENT 'SHA-256 do token; tambem e a chave HMAC',
    `token_prefix` VARCHAR(24) NOT NULL COMMENT 'Inicio do token, apenas para identificacao visual',
    `created_by`   INT UNSIGNED NULL DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    `last_used_ip` VARCHAR(45) NULL DEFAULT NULL,
    `revoked_at`   DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tokens_hash` (`token_hash`),
    KEY `idx_tokens_server` (`server_id`, `revoked_at`),
    CONSTRAINT `fk_tokens_server` FOREIGN KEY (`server_id`)
        REFERENCES `servers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tokens_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
