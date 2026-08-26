<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  CRON - LIMPEZA E MANUTENCAO
 * ============================================================================
 *
 *  Agendamento sugerido (uma vez por dia, de madrugada):
 *      15 3 * * * php /caminho/do/painel/cron/cleanup.php >> /caminho/storage/logs/cron.log 2>&1
 *
 *  O que faz (secao 21 do PLAN):
 *      - apaga metricas detalhadas alem do prazo de retencao;
 *      - apaga o historico de verificacao de sites alem do prazo;
 *      - apaga alertas JA RESOLVIDOS antigos;
 *      - apaga logs de auditoria antigos (0 = nunca apagar);
 *      - limpa nonces, buckets de rate limit e tentativas de login;
 *      - remove arquivos de log antigos de storage/logs.
 *
 *  O que NUNCA apaga:
 *      - servidores, sites, certificados e alertas em aberto.
 *
 *  Uso:
 *      php cron/cleanup.php               executa
 *      php cron/cleanup.php --dry-run     so mostra o que seria removido
 *      php cron/cleanup.php --quiet       sem saida
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

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\AuditService;
use App\Services\RetentionService;
use App\Services\SettingsService;

require __DIR__ . '/lock.php';

$quiet  = \in_array('--quiet', $argv, true);
$dryRun = \in_array('--dry-run', $argv, true);

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . \PHP_EOL;
    }
};

Logger::channel('cron');

$started = microtime(true);

$say('');
$say('  Limpeza e manutencao - ' . date('d/m/Y H:i:s'));
$say('  ' . str_repeat('=', 62));

// A limpeza pode demorar em bases grandes; a trava garante que o cron do dia
// seguinte nao comece por cima.
$lock = new CronLock('cleanup', 7200);

if (!$lock->acquire()) {
    $say('  Ja existe uma limpeza em andamento. Encerrando.');
    Logger::warning('cleanup: execucao ignorada, trava ativa.');
    exit(0);
}

if (!Database::isAvailable()) {
    $message = 'Banco de dados inacessivel: ' . (Database::lastError() ?? 'motivo desconhecido');

    $say('  ERRO: ' . $message);
    Logger::error('cleanup: ' . $message);

    $lock->release();
    exit(1);
}

SettingsService::applyOverrides(true);

$retention = Config::get('monitoring.retention', []);

$say('');
$say('  Politica de retencao em vigor:');
$say(sprintf('    metricas detalhadas ....: %s', formatRetention((int) ($retention['metrics'] ?? 30))));
$say(sprintf('    verificacoes de site ...: %s', formatRetention((int) ($retention['site_checks'] ?? 30))));
$say(sprintf('    alertas resolvidos .....: %s', formatRetention((int) ($retention['alerts'] ?? 90))));
$say(sprintf('    logs de auditoria ......: %s', formatRetention((int) ($retention['audit_logs'] ?? 180))));
$say(sprintf('    arquivos de log ........: %s', formatRetention((int) Config::get('log.max_files', 14))));

// ---------------------------------------------------------------------------
// Volume antes
// ---------------------------------------------------------------------------
$before = RetentionService::volumeSummary();

$say('');
$say('  Volume atual:');
foreach ($before as $table => $count) {
    $say(sprintf('    %-16s %s linha(s)', $table, number_format($count, 0, ',', '.')));
}

// ---------------------------------------------------------------------------
// Execucao
// ---------------------------------------------------------------------------
if ($dryRun) {
    $say('');
    $say('  MODO DRY-RUN: nada sera removido.');
    $say('');

    $candidates = [
        'metricas alem do prazo' => (int) Database::scalar(
            'SELECT COUNT(*) FROM server_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, (int) ($retention['metrics'] ?? 30))]
        ),
        'checagens alem do prazo' => (int) Database::scalar(
            'SELECT COUNT(*) FROM site_checks WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, (int) ($retention['site_checks'] ?? 30))]
        ),
        'alertas resolvidos antigos' => (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE status = 'resolved' AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [max(1, (int) ($retention['alerts'] ?? 90))]
        ),
    ];

    foreach ($candidates as $label => $count) {
        $say(sprintf('    %-28s %s', $label, number_format($count, 0, ',', '.')));
    }

    $say('');
    $lock->release();
    exit(0);
}

$say('');
$say('  Executando a limpeza...');

$result = RetentionService::runAll();

$say('');
foreach ($result as $step => $value) {
    $say(sprintf('    %-22s %s', str_replace('_', ' ', $step), \is_int($value) ? number_format($value, 0, ',', '.') : (string) $value));
}

// ---------------------------------------------------------------------------
// Volume depois
// ---------------------------------------------------------------------------
$after = RetentionService::volumeSummary();

$say('');
$say('  Volume apos a limpeza:');
foreach ($after as $table => $count) {
    $removed = ($before[$table] ?? 0) - $count;

    $say(sprintf(
        '    %-16s %s linha(s)%s',
        $table,
        number_format($count, 0, ',', '.'),
        $removed > 0 ? sprintf('  (-%s)', number_format($removed, 0, ',', '.')) : ''
    ));
}

$elapsed      = round(microtime(true) - $started, 2);
$totalRemoved = array_sum(array_map(
    static fn (int $count, string $table): int => ($before[$table] ?? 0) - $count,
    $after,
    array_keys($after)
));

$say('');
$say('  ' . str_repeat('=', 62));
$say(sprintf('  Concluido em %ss. %s registro(s) removido(s).', $elapsed, number_format($totalRemoved, 0, ',', '.')));
$say('');

Logger::info('cleanup concluido.', ['duracao' => $elapsed, 'removidos' => $totalRemoved]);

if ($totalRemoved > 0) {
    AuditService::log(
        'cron.cleanup',
        sprintf('Limpeza automatica removeu %s registro(s) antigo(s).', number_format($totalRemoved, 0, ',', '.')),
        [
            'user_id' => null,
            'actor'   => 'cron',
            'context' => ['duracao_segundos' => $elapsed, 'detalhe' => $result],
        ]
    );
}

$lock->release();

exit(0);

// ---------------------------------------------------------------------------

function formatRetention(int $days): string
{
    return $days <= 0 ? 'nunca apagar' : $days . ' dia(s)';
}
