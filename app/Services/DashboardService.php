<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Alert;
use App\Models\ServerMetric;
use App\Repositories\AlertRepository;
use App\Repositories\ServerRepository;
use App\Repositories\SiteRepository;

/**
 * Monta os dados do dashboard (secao 10 do PLAN).
 *
 * Todas as contagens vem de consultas agregadas - nenhuma linha de servidor
 * ou de site e carregada so para ser contada (secao 39).
 */
final class DashboardService
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
        private SiteRepository $sites = new SiteRepository(),
        private AlertRepository $alerts = new AlertRepository(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $serverSummary = $this->servers->statusSummary();
        $siteSummary   = $this->sites->statusSummary();
        $averages      = ServerMetric::infrastructureAverages();
        $alertCounts   = Alert::countOpenBySeverity();

        return [
            'servers' => $serverSummary,
            'sites'   => $siteSummary,
            'alerts'  => [
                'total'    => array_sum($alertCounts),
                'critical' => $alertCounts['critical'],
                'warning'  => $alertCounts['warning'],
                'info'     => $alertCounts['info'],
            ],
            'usage' => [
                'cpu' => [
                    'value' => $averages['cpu'] === null ? null : round($averages['cpu'], 1),
                    'level' => threshold_level($averages['cpu'], 'cpu'),
                ],
                'ram' => [
                    'value' => $averages['ram'] === null ? null : round($averages['ram'], 1),
                    'level' => threshold_level($averages['ram'], 'ram'),
                ],
                'disk' => [
                    'value' => $averages['disk'] === null ? null : round($averages['disk'], 1),
                    'level' => threshold_level($averages['disk'], 'disk'),
                ],
                'samples' => $averages['samples'],
            ],
            'ssl'     => $this->sites->sslSummary(),
            'overall' => MonitoringService::overallStatus(),
        ];
    }

    /**
     * Dados completos da tela inicial.
     *
     * @return array<string,mixed>
     */
    public function dashboardData(): array
    {
        return [
            'summary'       => $this->summary(),
            'serverList'    => $this->servers->listWithMetrics(),
            'openAlerts'    => Alert::openAlerts(8),
            'sitesOffline'  => $this->sites->currentlyOffline(6),
            'sslExpiring'   => $this->sites->sslExpiringSoon(30, 6),
            'wordpressCount' => $this->sites->countWordpress(),
        ];
    }

    /**
     * Dados da pagina de Metricas (visao consolidada + graficos).
     *
     * @return array<string,mixed>
     */
    public function metricsData(int $trendDays = 14): array
    {
        return [
            'summary'         => $this->summary(),
            'serverList'      => $this->servers->listWithMetrics(),
            'alertTrend'      => MonitoringService::alertTrend($trendDays),
            'phpDistribution' => $this->sites->phpDistribution(),
            'sslSummary'      => $this->sites->sslSummary(),
            'volume'          => RetentionService::volumeSummary(),
        ];
    }
}
