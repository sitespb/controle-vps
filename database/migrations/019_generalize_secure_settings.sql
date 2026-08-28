-- ---------------------------------------------------------------------------
-- notification_settings -> secure_settings
--
-- POR QUE RENOMEAR ALGO CRIADO ONTEM
-- ---------------------------------------------------------------------------
-- A tabela nasceu para guardar a senha do SMTP e o token da RyzeAPI cifrados.
-- A chave secreta do Turnstile e exatamente a mesma coisa - uma credencial de
-- terceiro que nao pode ficar legivel num dump - mas nao tem relacao nenhuma
-- com notificacao.
--
-- Havia duas saidas: uma segunda tabela com o mesmo comportamento, ou
-- generalizar esta. Duas tabelas identicas convidariam uma terceira; e uma
-- tabela chamada `notification_settings` guardando captcha seria uma pegadinha
-- para quem lesse o schema depois. Renomear agora custa uma migration e cinco
-- arquivos - daqui a seis meses custaria muito mais.
--
-- RENAME TABLE e atomico e preserva as linhas: os segredos ja gravados em
-- producao continuam validos e legiveis com a mesma APP_KEY.
-- ---------------------------------------------------------------------------
RENAME TABLE `notification_settings` TO `secure_settings`;

-- `channel` fazia sentido quando so havia canais de aviso. `scope` descreve o
-- que a coluna realmente e: a que assunto aquele conjunto de chaves pertence.
ALTER TABLE `secure_settings`
    CHANGE COLUMN `channel` `scope` ENUM('email','whatsapp','turnstile') NOT NULL;

ALTER TABLE `secure_settings`
    RENAME INDEX `uq_notification_settings` TO `uq_secure_settings`;
