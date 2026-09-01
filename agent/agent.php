<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  CONTROLE VPS - AGENTE DE MONITORAMENTO
 * ============================================================================
 *
 *  Instalado em cada VPS. Coleta informacoes LOCALMENTE e envia para o painel
 *  central por HTTPS. Roda via cron, sem interface, sem daemon.
 *
 *  Uso:
 *      php agent.php                      coleta completa (modo cron)
 *      php agent.php --verbose            mostra o passo a passo na tela
 *      php agent.php --test               so testa a conexao e a autenticacao
 *      php agent.php --dry-run            coleta e imprime, sem enviar nada
 *      php agent.php --only=metrics       executa apenas uma etapa
 *                                         (heartbeat|metrics|services|sites)
 *      php agent.php --config=/caminho/config.php
 *
 *  ------------------------------------------------------------------------
 *  GARANTIA DE SEGURANCA DA V1
 *  ------------------------------------------------------------------------
 *  Este agente SO ENVIA dados. Ele nao possui - e nao deve ganhar - nenhum
 *  caminho de codigo que interprete a resposta do painel como comando,
 *  script, caminho de arquivo ou codigo a executar. O unico campo lido da
 *  resposta e `next_interval`, um numero usado apenas para log.
 *
 *  Todos os comandos de sistema executados sao literais deste repositorio,
 *  restritos pela allowlist de Agent\Shell. Ver secoes 5 e 41 do PLAN.
 * ============================================================================
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este agente roda apenas via linha de comando.\n");
}

/*
 * Versao do agente, reportada ao painel a cada coleta.
 *
 * Precisa acompanhar a TAG publicada (AGENT_REF em install.sh e em
 * config/monitoring.php). Ficou parada em 1.0.0 por tres versoes, e o efeito
 * so apareceu num deploy: com quatro servidores em producao o painel mostrava
 * "v1.0.0" em todos, e nao havia como saber quais ja tinham recebido a
 * correcao. AgentApiTest::testVersaoDoAgenteAcompanhaATagPublicada guarda o
 * alinhamento entre os tres arquivos.
 */
const AGENT_VERSION = '1.2.2';

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Logger.php';
require __DIR__ . '/lib/Shell.php';
require __DIR__ . '/lib/Signer.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/ServerMetricsService.php';
require __DIR__ . '/lib/ServicesService.php';
require __DIR__ . '/lib/CyberPanelService.php';
require __DIR__ . '/lib/AaPanelService.php';
require __DIR__ . '/lib/SiteDiscoveryService.php';
require __DIR__ . '/lib/HttpCheckService.php';
require __DIR__ . '/lib/SslService.php';
require __DIR__ . '/lib/DiskUsageService.php';

use Agent\ApiClient;
use Agent\Config;
use Agent\DiskUsageService;
use Agent\HttpCheckService;
use Agent\Logger;
use Agent\ServerMetricsService;
use Agent\ServicesService;
use Agent\Signer;
use Agent\SiteDiscoveryService;
use Agent\SslService;

// ---------------------------------------------------------------------------
// Argumentos
// ---------------------------------------------------------------------------

$options = [];

foreach (\array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }

    $arg = substr($arg, 2);

    if (str_contains($arg, '=')) {
        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = trim($value, "\"'");
    } else {
        $options[$arg] = true;
    }
}

$verbose    = isset($options['verbose']) || isset($options['v']);
$dryRun     = isset($options['dry-run']);
$testOnly   = isset($options['test']);
$only       = isset($options['only']) ? (string) $options['only'] : null;
$configFile = isset($options['config']) ? (string) $options['config'] : __DIR__ . '/config.php';

// Nunca deixar o processo morrer com fatal silencioso no cron.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$exitCode = 0;

try {
    // -----------------------------------------------------------------------
    // Inicializacao
    // -----------------------------------------------------------------------
    $config = Config::load($configFile);
    $logger = new Logger(
        $config->string('LOG_PATH', __DIR__ . '/logs'),
        $verbose,
        $config->int('LOG_KEEP_DAYS', 14)
    );

    date_default_timezone_set($config->string('TIMEZONE', 'UTC'));

    $logger->line('');
    $logger->line('  Controle VPS - Agente v' . AGENT_VERSION);
    $logger->line('  ' . str_repeat('-', 60));
    $logger->line('  Servidor .....: #' . $config->serverId());
    $logger->line('  Painel .......: ' . $config->centralUrl());
    $logger->line('  Modo .........: ' . ($dryRun ? 'DRY-RUN (nada sera enviado)' : ($testOnly ? 'TESTE' : 'coleta')));
    $logger->line('');

    $signer = new Signer($config->serverId(), $config->token());

    $api = new ApiClient(
        $config->centralUrl(),
        $signer,
        $logger,
        AGENT_VERSION,
        $config->int('HTTP_TIMEOUT', 20),
        $config->int('HTTP_CONNECT_TIMEOUT', 8),
        $config->int('HTTP_RETRIES', 2),
        $config->bool('VERIFY_TLS', true)
    );

    // -----------------------------------------------------------------------
    // Modo teste: valida conectividade e autenticacao, sem gravar metrica
    // -----------------------------------------------------------------------
    if ($testOnly) {
        $exitCode = runConnectivityTest($api, $config, $logger);
        exit($exitCode);
    }

    $shouldRun = static fn (string $step): bool => $only === null || $only === $step;

    $started  = microtime(true);
    $failures = 0;

    $metricsService   = new ServerMetricsService($logger);
    $servicesService  = new ServicesService($logger);
    $discoveryService = new SiteDiscoveryService($logger);
    $diskUsageService = new DiskUsageService($logger);

    // -----------------------------------------------------------------------
    // 1. HEARTBEAT - identificacao do sistema
    // -----------------------------------------------------------------------
    if ($shouldRun('heartbeat')) {
        $logger->line('  [1/4] Heartbeat e identificacao do sistema...');

        try {
            $payload = [
                'agent_version' => AGENT_VERSION,
                'system'        => $metricsService->systemInfo(),
                'collected_at'  => date('c'),
            ];

            if ($dryRun) {
                dumpPayload($logger, 'heartbeat', $payload);
            } else {
                $response = $api->post('v1/agent/heartbeat', $payload);

                if ($response['ok']) {
                    $logger->info('Heartbeat enviado.', ['servidor' => $response['data']['server_name'] ?? null]);
                    $logger->line('        OK  ' . ($payload['system']['hostname'] ?? 'sem hostname')
                        . ' | ' . ($payload['system']['os_name'] ?? '?') . ' ' . ($payload['system']['os_version'] ?? ''));
                } else {
                    $failures++;
                    $logger->line('        FALHA: ' . $response['error']);
                }
            }
        } catch (Throwable $e) {
            // Secao 32: uma etapa que falha nao interrompe as demais.
            $failures++;
            $logger->error('Erro na etapa de heartbeat: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // 2. METRICAS - CPU, memoria, swap, disco, load, uptime
    // -----------------------------------------------------------------------
    if ($shouldRun('metrics')) {
        $logger->line('  [2/4] Coletando metricas de recursos...');

        try {
            $payload = $metricsService->metrics();

            if ($dryRun) {
                dumpPayload($logger, 'metrics', $payload);
            } else {
                $response = $api->post('v1/agent/metrics', $payload);

                if ($response['ok']) {
                    $logger->info('Metricas enviadas.', [
                        'cpu'   => $payload['cpu']['usage'],
                        'ram'   => $payload['memory']['percent'],
                        'disco' => $payload['disk']['percent'],
                    ]);
                    $logger->line(sprintf(
                        '        OK  CPU %s%%  RAM %s%%  Disco %s%%  Load %s',
                        $payload['cpu']['usage'] ?? '--',
                        $payload['memory']['percent'] ?? '--',
                        $payload['disk']['percent'] ?? '--',
                        $payload['load']['1'] ?? '--'
                    ));

                    foreach ($response['data']['alerts_raised'] ?? [] as $alert) {
                        $logger->line('        ALERTA aberto no painel: ' . $alert);
                    }
                } else {
                    $failures++;
                    $logger->line('        FALHA: ' . $response['error']);
                }
            }
        } catch (Throwable $e) {
            $failures++;
            $logger->error('Erro na etapa de metricas: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // 3. SERVICOS - OpenLiteSpeed, MariaDB, Redis, CyberPanel, PHP
    // -----------------------------------------------------------------------
    if ($shouldRun('services')) {
        $logger->line('  [3/4] Detectando servicos...');

        try {
            $services = $servicesService->collect();

            if ($dryRun) {
                dumpPayload($logger, 'services', ['services' => $services]);
            } else {
                $response = $api->post('v1/agent/services', ['services' => $services]);

                if ($response['ok']) {
                    $logger->info('Servicos enviados.', ['total' => \count($services)]);

                    foreach ($services as $service) {
                        $logger->line(sprintf(
                            '        %-16s %-14s %s',
                            $service['label'],
                            $service['status'],
                            $service['version'] ?? ''
                        ));
                    }
                } else {
                    $failures++;
                    $logger->line('        FALHA: ' . $response['error']);
                }
            }
        } catch (Throwable $e) {
            $failures++;
            $logger->error('Erro na etapa de servicos: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // 4. SITES - descoberta automatica + verificacao HTTP/SSL
    // -----------------------------------------------------------------------
    if ($shouldRun('sites')) {
        $logger->line('  [4/4] Descobrindo e verificando dominios...');

        try {
            $discovery = $discoveryService->discover();
            $sites     = $discovery['sites'];

            $logger->line(sprintf(
                '        %d dominio(s) descoberto(s) via %s.',
                \count($sites),
                $discovery['source']
            ));

            if ($sites !== []) {
                // Deteccao de WordPress no disco - nao depende de rede.
                foreach ($sites as $index => $site) {
                    $wordpress = $discoveryService->detectWordPress($site['document_root'] ?? null);

                    $sites[$index]['wordpress_detected'] = $wordpress['detected'];
                    $sites[$index]['wordpress_version']  = $wordpress['version'];
                }

                // Espaco em disco ocupado pelo document_root - medido com `du`
                // uma vez por hora e cacheado nos ciclos intermediarios.
                $sites = $diskUsageService->enrich($sites);

                // Verificacao HTTP e leitura do certificado, em paralelo.
                $httpCheck = new HttpCheckService(
                    $logger,
                    $config->int('CHECK_CONCURRENCY', 10),
                    $config->int('CHECK_TIMEOUT', 10),
                    $config->int('CHECK_CONNECT_TIMEOUT', 5)
                );

                $sites = $httpCheck->checkAll($sites);

                // Plano B para os certificados que o cURL nao entregou.
                if ($config->bool('SSL_FALLBACK', true)) {
                    $sites = (new SslService($logger, $config->int('SSL_TIMEOUT', 6)))
                        ->fillMissing($sites, $config->int('SSL_FALLBACK_LIMIT', 100));
                }

                $summary = summarizeSites($sites);

                $logger->line(sprintf(
                    '        %d online, %d em atencao, %d offline, %d com SSL lido.',
                    $summary['online'],
                    $summary['warning'],
                    $summary['offline'],
                    $summary['with_ssl']
                ));
            }

            if ($dryRun) {
                dumpPayload($logger, 'sites', ['sites' => \array_slice($sites, 0, 5)]);
                $logger->line('        (dry-run: exibindo apenas os 5 primeiros)');
            } else {
                // Envia em lotes: 200 dominios com certificado geram um corpo
                // grande, e o painel limita o tamanho da requisicao.
                $batchSize = $config->int('SITES_BATCH_SIZE', 100);
                $batches   = $sites === [] ? [[]] : array_chunk($sites, max(10, $batchSize));
                $totalBatches = \count($batches);

                foreach ($batches as $number => $batch) {
                    $payload = ['sites' => $batch];

                    // So o ULTIMO lote finaliza a coleta: ele carrega a lista
                    // completa de dominios para o painel marcar como "nao
                    // descoberto" apenas o que realmente sumiu. Finalizar a
                    // cada lote apagaria os dominios dos lotes seguintes.
                    if ($number === $totalBatches - 1) {
                        $payload['finalize'] = true;
                        $payload['domains']  = array_column($sites, 'domain');
                    } else {
                        $payload['finalize'] = false;
                    }

                    $response = $api->post('v1/agent/sites', $payload);

                    if ($response['ok']) {
                        $logger->info('Lote de sites enviado.', [
                            'lote'      => $number + 1,
                            'de'        => $totalBatches,
                            'recebidos' => $response['data']['received'] ?? 0,
                            'criados'   => $response['data']['created'] ?? 0,
                        ]);
                    } else {
                        $failures++;
                        $logger->line('        FALHA no lote ' . ($number + 1) . ': ' . $response['error']);
                    }
                }

                if ($failures === 0) {
                    $logger->line('        OK  dominios enviados ao painel.');
                }
            }
        } catch (Throwable $e) {
            $failures++;
            $logger->error('Erro na etapa de sites: ' . $e->getMessage(), [
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Encerramento
    // -----------------------------------------------------------------------
    $logger->prune();

    $elapsed = round(microtime(true) - $started, 2);

    if ($failures === 0) {
        $logger->info("Ciclo concluido em {$elapsed}s.");
        $logger->line('');
        $logger->line("  Concluido em {$elapsed}s.");
    } else {
        $logger->warning("Ciclo concluido com {$failures} falha(s) em {$elapsed}s.");
        $logger->line('');
        $logger->line("  Concluido com {$failures} falha(s) em {$elapsed}s. Veja logs/ para detalhes.");
        $exitCode = 1;
    }

    $logger->line('');
} catch (Throwable $e) {
    // Falha de inicializacao (config invalido, PHP sem cURL, etc).
    fwrite(\STDERR, 'ERRO FATAL NO AGENTE: ' . $e->getMessage() . \PHP_EOL);

    if (isset($logger)) {
        $logger->error('Erro fatal: ' . $e->getMessage(), [
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
        ]);
    } else {
        // Sem logger ainda: grava no fallback para o problema nao sumir.
        @file_put_contents(
            __DIR__ . '/logs/agent-fatal.log',
            sprintf("[%s] %s (%s:%d)%s", date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), \PHP_EOL),
            FILE_APPEND
        );
    }

    $exitCode = 2;
}

exit($exitCode);

// ---------------------------------------------------------------------------
// Auxiliares
// ---------------------------------------------------------------------------

/**
 * Testa conectividade e autenticacao ponta a ponta.
 * Enviamos um heartbeat real: e a unica forma honesta de provar que a
 * assinatura, o token e o relogio estao corretos.
 */
function runConnectivityTest(ApiClient $api, Config $config, Logger $logger): int
{
    $logger->line('  Teste de conectividade');
    $logger->line('  ' . str_repeat('-', 60));

    // 1. O painel responde?
    $logger->line('  1. Alcancando o painel central...');

    if (!$api->health()) {
        $logger->line('     FALHA: o painel nao respondeu em ' . $config->centralUrl() . '/v1/health');
        $logger->line('');
        $logger->line('     Verifique:');
        $logger->line('       - a URL em CENTRAL_URL (precisa terminar em /api)');
        $logger->line('       - se o servidor do painel esta no ar');
        $logger->line('       - firewall de saida do VPS na porta 443');
        $logger->line('       - certificado TLS valido (ou VERIFY_TLS => false para testes)');

        return 1;
    }

    $logger->line('     OK  painel acessivel.');

    // 2. A autenticacao funciona?
    $logger->line('  2. Autenticando com o token deste servidor...');

    $metrics  = new ServerMetricsService($logger);
    $response = $api->post('v1/agent/heartbeat', [
        'agent_version' => AGENT_VERSION,
        'system'        => $metrics->systemInfo(),
        'collected_at'  => date('c'),
    ]);

    if (!$response['ok']) {
        $logger->line('     FALHA: ' . $response['error']);
        $logger->line('');
        $logger->line('     Causas mais comuns:');
        $logger->line('       - SERVER_ID ou SERVER_TOKEN incorretos no config.php');
        $logger->line('       - token regenerado no painel (gere um novo e atualize aqui)');
        $logger->line('       - relogio do VPS fora de sincronia (rode: timedatectl set-ntp true)');

        return 1;
    }

    $logger->line('     OK  autenticado como "' . ($response['data']['server_name'] ?? '?') . '".');

    // 3. A descoberta encontra dominios?
    $logger->line('  3. Testando a descoberta de dominios...');

    $discovery = (new SiteDiscoveryService($logger))->discover();

    if ($discovery['sites'] === []) {
        $logger->line('     AVISO: nenhum dominio encontrado.');
        $logger->line('            Normal em servidor recem-instalado. Se houver sites,');
        $logger->line('            confirme que o agente roda como root.');
    } else {
        $logger->line(sprintf(
            '     OK  %d dominio(s) via %s. Exemplo: %s',
            \count($discovery['sites']),
            $discovery['source'],
            $discovery['sites'][0]['domain']
        ));
    }

    $logger->line('');
    $logger->line('  Tudo pronto. Configure o cron para rodar a cada '
        . max(1, (int) round($config->int('INTERVAL', 300) / 60)) . ' minuto(s).');
    $logger->line('');

    return 0;
}

/** @param array<string,mixed> $payload */
function dumpPayload(Logger $logger, string $step, array $payload): void
{
    $logger->line('        --- ' . strtoupper($step) . ' (dry-run) ---');
    $logger->line(
        (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * @param  array<int,array<string,mixed>> $sites
 * @return array{online:int,warning:int,offline:int,with_ssl:int}
 */
function summarizeSites(array $sites): array
{
    $summary = ['online' => 0, 'warning' => 0, 'offline' => 0, 'with_ssl' => 0];

    foreach ($sites as $site) {
        $status = $site['http_status'] ?? null;

        if ($status === null) {
            $summary['offline']++;
        } elseif ($status >= 500) {
            $summary['offline']++;
        } elseif ($status >= 400) {
            $summary['warning']++;
        } else {
            $summary['online']++;
        }

        if (isset($site['ssl']['valid_until']) && $site['ssl']['valid_until'] !== null) {
            $summary['with_ssl']++;
        }
    }

    return $summary;
}
