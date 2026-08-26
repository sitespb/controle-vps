<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  CRON - PROCESSAMENTO DE ALERTAS
 * ============================================================================
 *
 *  Agendamento sugerido: a cada 5 minutos, alinhado ao ciclo do agente.
 *  A linha de crontab pronta esta em docs/INSTALACAO-LOCAL.md.
 *
 *  O que faz (secoes 27, 28 e 29 do PLAN):
 *      1. marca como OFFLINE quem parou de enviar heartbeat, e resolve o
 *         alerta de quem voltou;
 *      2. reavalia CPU, RAM e disco a partir da ultima metrica de cada
 *         servidor - necessario porque um servidor que ficou mudo com o disco
 *         em 95% precisa continuar alertando;
 *      3. reavalia a disponibilidade dos sites;
 *      4. recalcula os dias restantes de cada certificado e abre/fecha os
 *         alertas de SSL.
 *
 *  Cada etapa e isolada: falhar em uma nao impede as demais (secao 32).
 *
 *  Uso manual:
 *      php cron/process-alerts.php            executa
 *      php cron/process-alerts.php --quiet    sem saida (modo cron silencioso)
 * ============================================================================
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script roda apenas via linha de comando.\n");
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(BASE_PATH);
App\Core\App::bootstrap(BASE_PATH);

use App\Core\Database;
use App\Core\Logger;
use App\Services\AuditService;
use App\Services\MonitoringService;
use App\Services\SettingsService;
use App\Services\SslService;

require __DIR__ . '/lock.php';

$quiet = \in_array('--quiet', $argv, true);

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . \PHP_EOL;
    }
};

Logger::channel('cron');

$started = microtime(true);

$say('');
$say('  Processamento de alertas - ' . date('d/m/Y H:i:s'));
$say('  ' . str_repeat('=', 62));

// ---------------------------------------------------------------------------
// Trava: duas execucoes simultaneas gerariam alertas duplicados
// ---------------------------------------------------------------------------
$lock = new CronLock('process-alerts');

if (!$lock->acquire()) {
    $say('  Ja existe uma execucao em andamento. Encerrando.');
    Logger::warning('process-alerts: execucao ignorada, trava ativa.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Banco disponivel?
// ---------------------------------------------------------------------------
if (!Database::isAvailable()) {
    $message = 'Banco de dados inacessivel: ' . (Database::lastError() ?? 'motivo desconhecido');

    $say('  ERRO: ' . $message);
    Logger::error('process-alerts: ' . $message);

    $lock->release();
    exit(1);
}

SettingsService::applyOverrides(true);

$summary  = [];
$failures = 0;

/**
 * Executa uma etapa isolando a falha.
 *
 * @param callable():array<string,mixed> $step
 */
$runStep = static function (string $label, callable $step) use (&$summary, &$failures, $say): void {
    $say('');
    $say('  ' . $label);

    try {
        $result = $step();

        foreach ($result as $key => $value) {
            $say(sprintf('    %-18s %s', $key, \is_array($value) ? json_encode($value) : (string) $value));
        }

        $summary[$label] = $result;
    } catch (Throwable $e) {
        $failures++;
        $say('    ERRO: ' . $e->getMessage());

        Logger::error("process-alerts [{$label}]: " . $e->getMessage(), [
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
        ]);

        $summary[$label] = ['erro' => $e->getMessage()];
    }
};

// ---------------------------------------------------------------------------
// 1. Servidores offline / recuperados (secao 28)
// ---------------------------------------------------------------------------
$runStep('1. Deteccao de servidores sem comunicacao', static function (): array {
    $result = MonitoringService::detectOfflineServers();

    return [
        'verificados' => $result['checked'],
        'offline'     => $result['went_offline'],
        'recuperados' => $result['recovered'],
        'falhas'      => $result['failed'],
    ];
});

// ---------------------------------------------------------------------------
// 2. Limites de CPU / RAM / disco (secao 19)
// ---------------------------------------------------------------------------
$runStep('2. Reavaliacao dos limites de recursos', static function (): array {
    $result = MonitoringService::evaluateResourceAlerts();

    return [
        'servidores' => $result['servers'],
        'alertas'    => $result['alerts'],
        'falhas'     => $result['failed'],
    ];
});

// ---------------------------------------------------------------------------
// 3. Disponibilidade dos sites (secao 29)
// ---------------------------------------------------------------------------
$runStep('3. Reavaliacao dos sites', static function (): array {
    $result = MonitoringService::evaluateSiteAlerts();

    return [
        'offline'   => $result['offline'],
        'resolvidos' => $result['online'],
        'falhas'    => $result['failed'],
    ];
});

// ---------------------------------------------------------------------------
// 4. Certificados SSL (secao 16)
// ---------------------------------------------------------------------------
$runStep('4. Recalculo dos certificados SSL', static function (): array {
    $result = SslService::refreshAll();

    return [
        'recalculados' => $result['recalculated'],
        'avaliados'    => $result['evaluated'],
    ];
});

// ---------------------------------------------------------------------------
// Encerramento
// ---------------------------------------------------------------------------
$elapsed = round(microtime(true) - $started, 2);

$openAlerts = (int) Database::scalar(
    "SELECT COUNT(*) FROM alerts WHERE status IN ('open','acknowledged')"
);

$say('');
$say('  ' . str_repeat('=', 62));
$say(sprintf('  Concluido em %ss. Alertas em aberto agora: %d.', $elapsed, $openAlerts));

if ($failures > 0) {
    $say(sprintf('  %d etapa(s) falharam - consulte storage/logs/.', $failures));
}

$say('');

Logger::info('process-alerts concluido.', [
    'duracao'          => $elapsed,
    'falhas'           => $failures,
    'alertas_abertos'  => $openAlerts,
]);

// Registrar cada execucao na auditoria encheria a tabela sem informacao util.
// So registramos quando algo relevante aconteceu.
$offline   = (int) ($summary['1. Deteccao de servidores sem comunicacao']['offline'] ?? 0);
$recovered = (int) ($summary['1. Deteccao de servidores sem comunicacao']['recuperados'] ?? 0);

if ($offline > 0 || $recovered > 0 || $failures > 0) {
    AuditService::log(
        'cron.process_alerts',
        sprintf(
            'Processamento de alertas: %d servidor(es) offline, %d recuperado(s), %d falha(s).',
            $offline,
            $recovered,
            $failures
        ),
        [
            'level'   => $failures > 0 ? 'error' : 'info',
            'user_id' => null,
            'actor'   => 'cron',
            'context' => ['duracao_segundos' => $elapsed],
        ]
    );
}

$lock->release();

exit($failures > 0 ? 1 : 0);
