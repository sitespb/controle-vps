-- ---------------------------------------------------------------------------
-- sites.disk_usage - espaco em disco ocupado pelo document_root de cada site
--
-- O agente mede com `du -sb <document_root>` uma vez por hora e reaproveita o
-- valor em cache nos ciclos intermediarios (du em arvores grandes e caro em
-- I/O). O valor chega em bytes; NULL significa "ainda nao medido".
-- ---------------------------------------------------------------------------
ALTER TABLE `sites`
    ADD COLUMN `disk_usage` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Bytes ocupados pelo document_root (coleta horaria)'
    AFTER `document_root`;
