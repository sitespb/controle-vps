-- ---------------------------------------------------------------------------
-- agent_nonces - protecao contra replay attack (secao 5 do PLAN)
--
-- Cada requisicao assinada pelo agente carrega um nonce aleatorio. A chave
-- unica (server_id, nonce) faz o proprio banco rejeitar a segunda tentativa
-- de usar a mesma assinatura - nao ha janela de corrida no PHP.
--
-- Combinado com a validacao de timestamp (AGENT_CLOCK_SKEW, padrao 5 min),
-- a tabela so precisa guardar nonces recentes; o cron de limpeza apaga o resto.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_nonces` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id`  INT UNSIGNED NOT NULL,
    `nonce`      VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nonce_server` (`server_id`, `nonce`),
    KEY `idx_nonce_created` (`created_at`),
    CONSTRAINT `fk_nonce_server` FOREIGN KEY (`server_id`)
        REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
