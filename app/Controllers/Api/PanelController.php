<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Alert;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Repositories\ServerRepository;
use App\Repositories\SiteRepository;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\DashboardService;
use App\Services\MonitoringService;

/**
 * API consumida pelo proprio painel via fetch (secao 30 do PLAN).
 *
 * Separada da API de agentes de proposito: autenticacao por sessao, escopo de
 * leitura do painel, respostas pensadas para o Chart.js e para a atualizacao
 * incremental dos cards.
 */
final class PanelController extends Controller
{
    /** GET /api/v1/dashboard/summary - atualiza os cards sem recarregar a pagina. */
    public function summary(Request $request): Response
    {
        return $this->apiOk((new DashboardService())->summary());
    }

    /** GET /api/v1/status - estado geral exibido na topbar. */
    public function status(Request $request): Response
    {
        return $this->apiOk(MonitoringService::overallStatus() + [
            'open_alerts' => Alert::countOpen(),
            'checked_at'  => now_string(),
        ]);
    }

    /**
     * GET /api/v1/servers/{id}/agent-status
     *
     * Consultado em laco pela tela de instalacao do agente, para responder
     * sozinha a pergunta "deu certo?". Devolve so o que aquela tela precisa -
     * um payload minusculo, porque e pedido a cada poucos segundos enquanto
     * alguem espera o agente aparecer.
     *
     * Quem decide se "conectou" e o navegador, comparando last_seen_at com o
     * valor que ele ja tinha. Assim um servidor que ja reportava ontem nao
     * aparece como recem-conectado ao abrir a pagina.
     */
    public function agentStatus(Request $request): Response
    {
        $server = Server::find($request->routeInt('id'));

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        return $this->apiOk([
            'status'        => $server['status'],
            'last_seen_at'  => $server['last_seen_at'],
            'last_seen_ago' => time_ago($server['last_seen_at']),
            'agent_version' => $server['agent_version'],
            'checked_at'    => now_string(),
        ]);
    }

    /** GET /api/v1/servers */
    public function servers(Request $request): Response
    {
        $servers = (new ServerRepository())->listWithMetrics(array_filter([
            'status' => $request->string('status'),
            'search' => $request->string('q'),
        ]));

        return $this->apiOk([
            'servers' => array_map(static fn (array $s): array => [
                'id'           => (int) $s['id'],
                'name'         => $s['name'],
                'provider'     => $s['provider'],
                'hostname'     => $s['hostname'],
                'ip'           => $s['ip'],
                'status'       => $s['status'],
                'cpu_usage'    => $s['cpu_usage'],
                'ram_percent'  => $s['ram_percent'],
                'disk_percent' => $s['disk_percent'],
                'sites_count'  => $s['sites_count'],
                'alerts_count' => $s['alerts_count'],
                'last_seen_at' => $s['last_seen_at'],
                'last_seen_ago' => time_ago($s['last_seen_at']),
            ], $servers),
            'total' => \count($servers),
        ]);
    }

    /**
     * GET /api/v1/servers/{id}/metrics?horas=24
     *
     * Serie pronta para o Chart.js: labels + datasets numericos.
     */
    public function serverMetrics(Request $request): Response
    {
        $id = $request->routeInt('id');

        if (Server::find($id) === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        $hours = $this->validHours($request->int('horas', 24));
        $rows  = ServerMetric::seriesFor($id, $hours);

        $labels = [];
        $cpu    = [];
        $ram    = [];
        $disk   = [];
        $load1  = [];

        // Em janelas longas o rotulo mostra a data; em janelas curtas, a hora.
        $format = $hours > 48 ? 'd/m H:i' : 'H:i';

        foreach ($rows as $row) {
            $labels[] = date($format, (int) strtotime((string) $row['created_at']));
            $cpu[]    = $row['cpu_usage'] === null ? null : (float) $row['cpu_usage'];
            $ram[]    = $row['ram_percent'] === null ? null : (float) $row['ram_percent'];
            $disk[]   = $row['disk_percent'] === null ? null : (float) $row['disk_percent'];
            $load1[]  = $row['load_1'] === null ? null : (float) $row['load_1'];
        }

        return $this->apiOk([
            'labels' => $labels,
            'cpu'    => $cpu,
            'ram'    => $ram,
            'disk'   => $disk,
            'load'   => $load1,
            'hours'  => $hours,
            'points' => \count($labels),
        ]);
    }

    /** GET /api/v1/sites */
    public function sites(Request $request): Response
    {
        $result = (new SiteRepository())->paginate(
            array_filter([
                'search'    => $request->string('q'),
                'server_id' => $request->int('servidor'),
                'status'    => $request->string('status'),
                'ssl'       => $request->string('ssl'),
                'sort'      => $request->string('sort', 'domain'),
                'direction' => $request->string('dir', 'asc'),
            ]),
            max(1, $request->int('pagina', 1)),
            min(100, max(5, $request->int('por_pagina', 25)))
        );

        return $this->apiOk($result);
    }

    /** GET /api/v1/sites/{id}/checks?horas=24 */
    public function siteChecks(Request $request): Response
    {
        $id = $request->routeInt('id');

        if (Site::find($id) === null) {
            throw HttpException::notFound('Site nao encontrado.');
        }

        $hours = $this->validHours($request->int('horas', 24));
        $rows  = SiteCheck::responseSeries($id, $hours);

        $labels    = [];
        $responses = [];
        $statuses  = [];

        $format = $hours > 48 ? 'd/m H:i' : 'H:i';

        foreach ($rows as $row) {
            $labels[]    = date($format, (int) strtotime((string) $row['created_at']));
            $responses[] = $row['response_time'] === null ? null : (int) $row['response_time'];
            $statuses[]  = (string) $row['status'];
        }

        return $this->apiOk([
            'labels'    => $labels,
            'response'  => $responses,
            'statuses'  => $statuses,
            'uptime_24h' => SiteCheck::uptimePercent($id, 24),
            'hours'     => $hours,
        ]);
    }

    /** GET /api/v1/alerts */
    public function alerts(Request $request): Response
    {
        $limit = min(100, max(1, $request->int('limite', 20)));

        return $this->apiOk([
            'alerts' => array_map(static fn (array $a): array => [
                'id'           => (int) $a['id'],
                'type'         => $a['type'],
                'type_label'   => Alert::typeLabel((string) $a['type']),
                'severity'     => $a['severity'],
                'title'        => $a['title'],
                'message'      => $a['message'],
                'status'       => $a['status'],
                'server_name'  => $a['server_name'] ?? null,
                'site_domain'  => $a['site_domain'] ?? null,
                'occurrences'  => (int) $a['occurrences'],
                'last_seen_at' => $a['last_seen_at'],
                'last_seen_ago' => time_ago($a['last_seen_at']),
            ], Alert::openAlerts($limit)),
            'counts' => Alert::countOpenBySeverity(),
        ]);
    }

    /** POST /api/v1/alerts/{id}/acknowledge */
    public function acknowledgeAlert(Request $request): Response
    {
        $id     = $request->routeInt('id');
        $userId = AuthService::id();

        if ($userId === null) {
            throw HttpException::unauthorized();
        }

        if (!AlertService::acknowledge($id, $userId)) {
            return $this->apiError('Alerta nao encontrado ou nao esta aberto.', 409, 'invalid_state');
        }

        AuditService::log('alert.acknowledged', sprintf('Alerta #%d reconhecido.', $id), [
            'entity_type' => 'alert',
            'entity_id'   => $id,
        ]);

        return $this->apiOk(['id' => $id, 'status' => Alert::STATUS_ACKNOWLEDGED]);
    }

    /** POST /api/v1/alerts/{id}/resolve */
    public function resolveAlert(Request $request): Response
    {
        $id     = $request->routeInt('id');
        $userId = AuthService::id();

        if ($userId === null) {
            throw HttpException::unauthorized();
        }

        if (!AlertService::resolveManually($id, $userId)) {
            return $this->apiError('Alerta nao encontrado ou ja resolvido.', 409, 'invalid_state');
        }

        AuditService::log('alert.resolved', sprintf('Alerta #%d resolvido manualmente.', $id), [
            'entity_type' => 'alert',
            'entity_id'   => $id,
        ]);

        return $this->apiOk(['id' => $id, 'status' => Alert::STATUS_RESOLVED]);
    }

    private function validHours(int $hours): int
    {
        return \in_array($hours, [6, 24, 72, 168, 720], true) ? $hours : 24;
    }
}
