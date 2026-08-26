<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Server;
use App\Repositories\AlertRepository;
use App\Repositories\ServerRepository;
use App\Repositories\SiteRepository;

/**
 * Rotinas de analise executadas pelo cron (secoes 27, 28, 29 e 32 do PLAN).
 *
 * Regra que atravessa toda a classe: a falha de UM servidor nunca pode
 * interromper o processamento dos demais. Cada item roda dentro do proprio
 * try/catch e o resultado agregado informa quantos falharam.
 */
final class MonitoringService
{
    /**
     * Marca como offline quem parou de enviar heartbeat e devolve os que
     * voltaram (secao 28).
     *
     * @return array{checked:int,went_offline:int,recovered:int,failed:int}
     */
    public static function detectOfflineServers(): array
    {
        $tolerance  = (int) Config::get('monitoring.server_offline_after', 600);
        $repository = new ServerRepository();

        $result = ['checked' => 0, 'went_offline' => 0, 'recovered' => 0, 'failed' => 0];

        // --- Quem ficou mudo ---
        foreach ($repository->staleServers($tolerance) as $server) {
            $result['checked']++;

            try {
                $id   = (int) $server['id'];
                $name = (string) $server['name'];

                Server::updateStatus($id, Server::STATUS_OFFLINE);
                AlertService::serverWentOffline($id, $name, $server['last_seen_at'] ?? null);

                AuditService::log(
                    'server.offline',
                    sprintf('Servidor "%s" marcado como offline por falta de comunicacao.', $name),
                    ['entity_type' => 'server', 'entity_id' => $id, 'level' => 'warning', 'user_id' => null, 'actor' => 'sistema']
                );

                $result['went_offline']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                Logger::error('Falha ao marcar servidor offline: ' . $e->getMessage(), [
                    'server_id' => $server['id'] ?? null,
                ]);
            }
        }

        // --- Quem voltou ---
        foreach ($repository->recoveredServers($tolerance) as $server) {
            $result['checked']++;

            try {
                $id   = (int) $server['id'];
                $name = (string) $server['name'];

                Server::updateStatus($id, Server::STATUS_ONLINE);
                AlertService::serverCameBack($id, $name);

                AuditService::log(
                    'server.online',
                    sprintf('Servidor "%s" voltou a se comunicar.', $name),
                    ['entity_type' => 'server', 'entity_id' => $id, 'user_id' => null, 'actor' => 'sistema']
                );

                $result['recovered']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                Logger::error('Falha ao restaurar servidor: ' . $e->getMessage(), [
                    'server_id' => $server['id'] ?? null,
                ]);
            }
        }

        return $result;
    }

    /**
     * Reavalia CPU / RAM / disco a partir da ultima metrica de cada servidor.
     *
     * Necessario porque um servidor que parou de enviar dados mantendo o disco
     * em 95% precisa continuar alertando.
     *
     * @return array{servers:int,alerts:int,failed:int}
     */
    public static function evaluateResourceAlerts(): array
    {
        $repository = new ServerRepository();
        $result     = ['servers' => 0, 'alerts' => 0, 'failed' => 0];

        foreach ($repository->latestMetricsWithServer() as $metric) {
            $result['servers']++;

            try {
                $raised = AlertService::evaluateServerMetrics(
                    (int) $metric['server_id'],
                    (string) $metric['server_name'],
                    $metric
                );

                $result['alerts'] += \count($raised);
            } catch (\Throwable $e) {
                $result['failed']++;
                Logger::error('Falha ao avaliar recursos: ' . $e->getMessage(), [
                    'server_id' => $metric['server_id'] ?? null,
                ]);
            }
        }

        return $result;
    }

    /**
     * Reavalia a disponibilidade dos sites com base no ultimo estado
     * conhecido (secao 29).
     *
     * @return array{offline:int,online:int,failed:int}
     */
    public static function evaluateSiteAlerts(): array
    {
        $repository = new SiteRepository();
        $result     = ['offline' => 0, 'online' => 0, 'failed' => 0];

        foreach ($repository->offlineForAlerts() as $site) {
            try {
                AlertService::siteWentOffline(
                    (int) $site['id'],
                    (int) $site['server_id'],
                    (string) $site['domain'],
                    $site['http_status'] === null ? null : (int) $site['http_status'],
                    $site['last_error'] === null ? null : (string) $site['last_error']
                );
                $result['offline']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                Logger::error('Falha ao alertar site offline: ' . $e->getMessage(), [
                    'site_id' => $site['id'] ?? null,
                ]);
            }
        }

        foreach ($repository->onlineForAlerts() as $site) {
            try {
                if (AlertService::siteCameBack((int) $site['id'], (int) $site['server_id'], (string) $site['domain'])) {
                    $result['online']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Estado geral da infraestrutura, usado no topo do painel.
     *
     * @return array{level:string,label:string,servers_offline:int,sites_offline:int,critical_alerts:int}
     */
    public static function overallStatus(): array
    {
        // Memoizado: a topbar e o dashboard pedem o mesmo estado na mesma
        // requisicao, e nao ha motivo para rodar as contagens duas vezes.
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $serversOffline = (int) Database::scalar(
            "SELECT COUNT(*) FROM servers WHERE status = 'offline'"
        );

        $sitesOffline = (int) Database::scalar(
            "SELECT COUNT(*) FROM sites WHERE discovered = 1 AND status = 'offline'"
        );

        $criticalAlerts = (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE status IN ('open','acknowledged') AND severity = 'critical'"
        );

        $warningAlerts = (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE status IN ('open','acknowledged') AND severity = 'warning'"
        );

        if ($serversOffline > 0 || $criticalAlerts > 0) {
            $level = 'critical';
            $label = 'Atencao necessaria';
        } elseif ($sitesOffline > 0 || $warningAlerts > 0) {
            $level = 'warning';
            $label = 'Alertas em aberto';
        } else {
            $level = 'normal';
            $label = 'Tudo operacional';
        }

        return $cache = [
            'level'           => $level,
            'label'           => $label,
            'servers_offline' => $serversOffline,
            'sites_offline'   => $sitesOffline,
            'critical_alerts' => $criticalAlerts,
        ];
    }

    /**
     * Tendencia de alertas dos ultimos dias, no formato do Chart.js.
     *
     * @return array{labels:array<int,string>,critical:array<int,int>,warning:array<int,int>,info:array<int,int>}
     */
    public static function alertTrend(int $days = 14): array
    {
        $rows = (new AlertRepository())->dailyTrend($days);

        $labels   = [];
        $buckets  = ['critical' => [], 'warning' => [], 'info' => []];
        $indexMap = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date               = date('Y-m-d', strtotime("-{$i} days"));
            $indexMap[$date]    = \count($labels);
            $labels[]           = date('d/m', strtotime($date));
            $buckets['critical'][] = 0;
            $buckets['warning'][]  = 0;
            $buckets['info'][]     = 0;
        }

        foreach ($rows as $row) {
            $date     = (string) $row['dia'];
            $severity = (string) $row['severity'];

            if (isset($indexMap[$date], $buckets[$severity])) {
                $buckets[$severity][$indexMap[$date]] = (int) $row['total'];
            }
        }

        return [
            'labels'   => $labels,
            'critical' => $buckets['critical'],
            'warning'  => $buckets['warning'],
            'info'     => $buckets['info'],
        ];
    }
}
