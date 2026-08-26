<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\ServerMetric;
use App\Services\DashboardService;

/**
 * Visao consolidada de metricas e graficos (secoes 14 e 20 do PLAN).
 */
final class MetricsController extends Controller
{
    public function index(Request $request): Response
    {
        $data     = (new DashboardService())->metricsData();
        $hours    = $this->validHours($request->int('horas', 24));
        $serverId = $request->int('servidor');

        // Serie do servidor selecionado; sem selecao, o primeiro da lista.
        if ($serverId === 0 && $data['serverList'] !== []) {
            $serverId = (int) $data['serverList'][0]['id'];
        }

        return $this->view('metrics/index', [
            'title'           => 'Metricas',
            'activeNav'       => 'metrics',
            'summary'         => $data['summary'],
            'servers'         => $data['serverList'],
            'selectedServer'  => $serverId,
            'series'          => $serverId > 0 ? ServerMetric::seriesFor($serverId, $hours) : [],
            'hours'           => $hours,
            'alertTrend'      => $data['alertTrend'],
            'phpDistribution' => $data['phpDistribution'],
            'sslSummary'      => $data['sslSummary'],
            'volume'          => $data['volume'],
        ]);
    }

    private function validHours(int $hours): int
    {
        return \in_array($hours, [6, 24, 72, 168, 720], true) ? $hours : 24;
    }
}
