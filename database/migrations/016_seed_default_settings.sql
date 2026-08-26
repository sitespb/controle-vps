-- ---------------------------------------------------------------------------
-- Valores iniciais de `settings`.
--
-- Espelham os padroes de config/monitoring.php (secao 19 do PLAN). Editar
-- pela tela Configuracoes > Sistema passa a valer imediatamente para todo o
-- sistema, inclusive para os crons.
--
-- INSERT IGNORE: rodar a migration de novo nao sobrescreve o que o operador
-- ja ajustou.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `settings`
    (`key`, `value`, `type`, `group`, `label`, `description`, `unit`, `min_value`, `max_value`, `sort_order`)
VALUES
    ('monitoring.thresholds.cpu.warning',  '80',  'float', 'limites', 'CPU - atencao',   'Acima deste percentual a CPU entra em estado de atencao.',   '%', 1, 100, 10),
    ('monitoring.thresholds.cpu.critical', '90',  'float', 'limites', 'CPU - critico',   'Acima deste percentual a CPU gera alerta critico.',          '%', 1, 100, 11),

    ('monitoring.thresholds.ram.warning',  '80',  'float', 'limites', 'RAM - atencao',   'Acima deste percentual a memoria entra em atencao.',        '%', 1, 100, 20),
    ('monitoring.thresholds.ram.critical', '90',  'float', 'limites', 'RAM - critico',   'Acima deste percentual a memoria gera alerta critico.',     '%', 1, 100, 21),

    ('monitoring.thresholds.disk.warning',  '80', 'float', 'limites', 'Disco - atencao', 'Acima deste percentual o disco entra em atencao.',          '%', 1, 100, 30),
    ('monitoring.thresholds.disk.critical', '90', 'float', 'limites', 'Disco - critico', 'Acima deste percentual o disco gera alerta critico.',       '%', 1, 100, 31),

    ('monitoring.ssl.warning',  '30', 'int', 'ssl', 'SSL - aviso',   'Dias restantes a partir dos quais o certificado entra em atencao.', 'dias', 1, 365, 40),
    ('monitoring.ssl.critical', '7',  'int', 'ssl', 'SSL - critico', 'Dias restantes a partir dos quais o certificado vira alerta critico.', 'dias', 1, 90, 41),

    ('monitoring.agent_interval',       '300', 'int', 'coleta', 'Intervalo do agente',      'Periodicidade esperada de envio dos agentes.',                  'segundos', 60, 3600, 50),
    ('monitoring.server_offline_after', '600', 'int', 'coleta', 'Tolerancia de heartbeat',  'Sem comunicacao por este tempo o servidor e marcado offline.',  'segundos', 120, 7200, 51),

    ('monitoring.retention.metrics',     '30',  'int', 'retencao', 'Retencao de metricas',        'Dias de historico detalhado de CPU/RAM/disco/load.', 'dias', 1, 365, 60),
    ('monitoring.retention.site_checks', '30',  'int', 'retencao', 'Retencao de checagens',       'Dias de historico de verificacao dos sites.',        'dias', 1, 365, 61),
    ('monitoring.retention.alerts',      '90',  'int', 'retencao', 'Retencao de alertas',         'Dias que um alerta resolvido permanece no banco.',   'dias', 7, 730, 62),
    ('monitoring.retention.audit_logs',  '180', 'int', 'retencao', 'Retencao de logs',            'Dias de logs de auditoria. 0 = nunca apagar.',       'dias', 0, 3650, 63);
