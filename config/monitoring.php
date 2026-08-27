<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Regras de monitoramento e alerta - secoes 19, 21 e 28 do PLAN.
 *
 * Estes sao os valores PADRAO. A tabela `settings` pode sobrescrever qualquer
 * um deles em runtime (SettingsService::applyOverrides()), o que atende ao
 * requisito "esses valores deverao ficar configuraveis futuramente" sem
 * precisar mexer em codigo.
 */
return [
    /*
     * Percentuais. Ate `warning` e normal; de `warning` a `critical` e
     * atencao; acima de `critical` e critico.
     */
    'thresholds' => [
        'cpu'  => ['warning' => 80.0, 'critical' => 90.0],
        'ram'  => ['warning' => 80.0, 'critical' => 90.0],
        'disk' => ['warning' => 80.0, 'critical' => 90.0],
        'swap' => ['warning' => 80.0, 'critical' => 95.0],
    ],

    /*
     * SSL, em dias restantes.
     *   > warning        => valido  (verde)
     *   critical..warning => vencendo (amarelo)
     *   <= 0             => expirado (vermelho)
     *   sem dados        => desconhecido (cinza)
     */
    'ssl' => [
        'warning'  => 30,
        'critical' => 7,
    ],

    /*
     * Faixas de status HTTP - secao 17 do PLAN.
     * 4xx NAO derruba o site para offline: 404/403 indicam servidor no ar.
     */
    'http' => [
        'online_min'   => 200,
        'online_max'   => 399,
        'warning_min'  => 400,
        'warning_max'  => 499,
        'offline_min'  => 500,
        'offline_max'  => 599,
        'slow_response' => 3000, // ms - acima disso o site entra em "atencao"
    ],

    /* Intervalo esperado de coleta do agente, em segundos. */
    'agent_interval' => Env::int('AGENT_INTERVAL', 300),

    /*
     * De onde o instalador baixa o agente.
     *
     * `agent_ref` e uma TAG, nunca `main`: o comando que o painel exibe deve
     * instalar a versao do agente que ESTE painel entende. Ao publicar uma
     * versao nova do agente, suba a tag aqui junto com o painel - assim as
     * duas pontas andam sempre no mesmo passo.
     */
    'agent_repo' => Env::get('AGENT_REPO', 'sitespb/controle-vps'),
    'agent_ref'  => Env::get('AGENT_REF', 'v1.1.1'),

    /* Sem heartbeat por este periodo (segundos) => servidor OFFLINE. */
    'server_offline_after' => Env::int('SERVER_OFFLINE_AFTER', 600),

    /*
     * Limite de envio dos avisos (e-mail e WhatsApp).
     *
     * As duas camadas resolvem problemas diferentes: a janela por dominio
     * evita que um site instavel avise a cada coleta; o teto por hora evita
     * que a queda de um servidor inteiro dispare dezenas de mensagens de uma
     * vez e o provedor bloqueie a conta.
     */
    'notify' => [
        'window_minutes' => Env::int('NOTIFY_WINDOW_MINUTES', 360), // 6h por dominio
        'hourly_cap'     => Env::int('NOTIFY_HOURLY_CAP', 20),      // por canal
    ],

    /* Retencao de dados, em dias. 0 desativa a limpeza daquele conjunto. */
    'retention' => [
        'metrics'     => Env::int('METRICS_RETENTION_DAYS', 30),
        'site_checks' => Env::int('SITE_CHECKS_RETENTION_DAYS', 30),
        'audit_logs'  => Env::int('AUDIT_RETENTION_DAYS', 180),
        'alerts'      => 90,   // alertas ja resolvidos
        'nonces'      => 1,    // nonces de replay protection
    ],

    /* Servicos que o agente tenta detectar - secao 6 do PLAN. */
    'services' => [
        'openlitespeed' => 'OpenLiteSpeed',
        'mariadb'       => 'MariaDB / MySQL',
        'redis'         => 'Redis',
        'cyberpanel'    => 'CyberPanel',
        'aapanel'       => 'aaPanel',
        'nginx'         => 'Nginx',
        'apache'        => 'Apache',
        'php'           => 'PHP',
    ],

    /* Seguranca da API de agentes. */
    'agent_api' => [
        'clock_skew'   => Env::int('AGENT_CLOCK_SKEW', 300),
        'rate_limit'   => Env::int('API_RATE_LIMIT', 120),
        'rate_window'  => Env::int('API_RATE_WINDOW', 60),
        'max_body'     => 512 * 1024, // 512 KB
        'max_sites'    => 500,        // por requisicao
    ],

    /* Brute force no login - secao 33 do PLAN. */
    'login' => [
        'max_attempts'  => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => Env::int('LOGIN_DECAY_MINUTES', 15),
    ],
];
