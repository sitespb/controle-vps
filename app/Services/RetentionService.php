<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\ServerMetric;
use App\Models\SiteCheck;

/**
 * Limpeza automatica dos dados (secao 21 do PLAN).
 *
 * O QUE E APAGADO:
 *   - metricas detalhadas alem de METRICS_RETENTION_DAYS (padrao 30 dias);
 *   - historico de checagem de sites alem do prazo configurado;
 *   - alertas JA RESOLVIDOS mais antigos que o prazo;
 *   - logs de auditoria alem do prazo (0 = nunca apagar);
 *   - nonces, buckets de rate limit e tentativas de login antigos;
 *   - arquivos de log em storage/logs/.
 *
 * O QUE NUNCA E APAGADO (regra explicita da secao 21):
 *   - servidores, sites, certificados e alertas em aberto.
 *
 * Cada etapa roda isolada: uma falha nao impede as demais.
 */
final class RetentionService
{
    /**
     * @return array<string,int|string> Resumo por etapa
     */
    public static function runAll(): array
    {
        $retention = Config::get('monitoring.retention', []);
        $summary   = [];

        $steps = [
            'metricas' => static fn (): int => ServerMetric::pruneOlderThan((int) ($retention['metrics'] ?? 30)),
            'checagens_de_site' => static fn (): int => SiteCheck::pruneOlderThan((int) ($retention['site_checks'] ?? 30)),
            'alertas_resolvidos' => static fn (): int => Alert::pruneResolvedOlderThan((int) ($retention['alerts'] ?? 90)),
            'logs_auditoria' => static fn (): int => AuditLog::pruneOlderThan((int) ($retention['audit_logs'] ?? 180)),
            'nonces' => static fn (): int => TokenService::pruneNonces((int) ($retention['nonces'] ?? 1)),
            'rate_limits' => static fn (): int => RateLimiter::prune(24),
            'tentativas_login' => static fn (): int => AuthService::pruneAttempts(7),
            'eventos_orfaos' => static fn (): int => self::pruneOrphanAlertEvents(),
            'arquivos_de_log' => static fn (): int => Logger::prune((int) Config::get('log.max_files', 14)),
        ];

        foreach ($steps as $name => $step) {
            try {
                $summary[$name] = $step();
            } catch (\Throwable $e) {
                $summary[$name] = 'erro: ' . $e->getMessage();
                Logger::error('Falha na limpeza (' . $name . '): ' . $e->getMessage());
            }
        }

        // Otimiza as tabelas que mais crescem, so quando algo foi removido.
        if (($summary['metricas'] ?? 0) > 0 || ($summary['checagens_de_site'] ?? 0) > 0) {
            $summary['otimizacao'] = self::optimizeTables(['server_metrics', 'site_checks']);
        }

        return $summary;
    }

    /**
     * Eventos cujo alerta ja foi removido. As FKs cuidam disso, mas a rotina
     * existe para bancos migrados de instalacoes antigas sem FK.
     */
    private static function pruneOrphanAlertEvents(): int
    {
        return Database::statement(
            'DELETE e FROM alert_events e
             LEFT JOIN alerts a ON a.id = e.alert_id
             WHERE a.id IS NULL'
        );
    }

    /** @param array<int,string> $tables */
    private static function optimizeTables(array $tables): string
    {
        $done = [];

        foreach ($tables as $table) {
            try {
                // Nome vem de constante do proprio codigo, nunca de entrada externa.
                Database::connection()->exec('OPTIMIZE TABLE `' . $table . '`');
                $done[] = $table;
            } catch (\Throwable $e) {
                Logger::warning('OPTIMIZE TABLE falhou em ' . $table . ': ' . $e->getMessage());
            }
        }

        return $done === [] ? 'nenhuma' : implode(', ', $done);
    }

    /**
     * Estatisticas de volume, exibidas na tela de Configuracoes > Sistema.
     *
     * @return array<int,array{tabela:string,linhas:int,tamanho:string}>
     */
    public static function tableStats(): array
    {
        $rows = Database::select(
            "SELECT table_name AS tabela,
                    table_rows AS linhas,
                    ROUND((data_length + index_length) / 1024 / 1024, 2) AS mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             ORDER BY (data_length + index_length) DESC"
        );

        return array_map(
            static fn (array $r): array => [
                'tabela'  => (string) $r['tabela'],
                'linhas'  => (int) $r['linhas'],
                'tamanho' => number_format((float) $r['mb'], 2, ',', '.') . ' MB',
            ],
            $rows
        );
    }

    /** Contagem exata das tabelas que mais crescem. @return array<string,int> */
    public static function volumeSummary(): array
    {
        return [
            'server_metrics' => (int) Database::scalar('SELECT COUNT(*) FROM server_metrics'),
            'site_checks'    => (int) Database::scalar('SELECT COUNT(*) FROM site_checks'),
            'alerts'         => (int) Database::scalar('SELECT COUNT(*) FROM alerts'),
            'audit_logs'     => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs'),
        ];
    }
}
