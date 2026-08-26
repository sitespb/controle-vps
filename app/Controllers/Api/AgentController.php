<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\MetricsIngestService;
use App\Services\ServiceIngestService;
use App\Services\SiteIngestService;

/**
 * API dos agentes (secao 9 do PLAN).
 *
 *   POST /api/v1/agent/heartbeat
 *   POST /api/v1/agent/metrics
 *   POST /api/v1/agent/sites
 *   POST /api/v1/agent/services
 *
 * ---------------------------------------------------------------------------
 * FLUXO DE DADOS: SEMPRE DE FORA PARA DENTRO
 * ---------------------------------------------------------------------------
 * Estes endpoints RECEBEM dados. A resposta devolve apenas confirmacao e
 * parametros de agendamento (proximo intervalo). Nao existe - e nao pode
 * passar a existir na V1 - nenhum campo de comando, script ou caminho de
 * arquivo na resposta. O agente, por sua vez, ignora qualquer coisa que nao
 * seja o intervalo (secao 5: "o agente jamais devera executar comandos
 * recebidos atraves da API").
 *
 * A autenticacao ja aconteceu no AgentAuthMiddleware; aqui o servidor chega
 * resolvido em $request->attribute('server').
 */
final class AgentController extends Controller
{
    /**
     * Sinaliza que o servidor esta vivo e atualiza a identificacao do sistema.
     */
    public function heartbeat(Request $request): Response
    {
        $server   = $this->server($request);
        $serverId = (int) $server['id'];

        $changes = MetricsIngestService::updateIdentity($serverId, $request->all());
        MetricsIngestService::recordHeartbeat($serverId);

        // Servidor que estava offline e voltou: resolve o alerta na hora, sem
        // esperar o proximo ciclo do cron.
        if (($server['status'] ?? '') === 'offline') {
            \App\Services\AlertService::serverCameBack($serverId, (string) $server['name']);

            AuditService::log(
                'server.online',
                sprintf('Servidor "%s" voltou a se comunicar.', $server['name']),
                [
                    'entity_type' => 'server',
                    'entity_id'   => $serverId,
                    'user_id'     => null,
                    'actor'       => 'agente',
                ]
            );
        }

        Logger::info('Heartbeat recebido', ['server_id' => $serverId, 'campos' => array_keys($changes)]);

        return $this->agentResponse([
            'server_id'   => $serverId,
            'server_name' => $server['name'],
            'received_at' => now_string(),
            'updated'     => array_values(array_diff(array_keys($changes), ['updated_at'])),
        ]);
    }

    /**
     * Recebe uma amostra de CPU, memoria, swap, disco, load e uptime.
     */
    public function metrics(Request $request): Response
    {
        $server   = $this->server($request);
        $serverId = (int) $server['id'];

        $payload = $request->all();

        if ($payload === []) {
            return $this->apiError('Corpo da requisicao vazio.', 422, 'empty_payload');
        }

        $result = MetricsIngestService::store($serverId, (string) $server['name'], $payload);

        Logger::info('Metricas recebidas', [
            'server_id' => $serverId,
            'metric_id' => $result['metric_id'],
        ]);

        return $this->agentResponse([
            'metric_id'      => $result['metric_id'],
            'alerts_raised'  => $result['alerts'],
            'received_at'    => now_string(),
        ]);
    }

    /**
     * Recebe a lista de dominios descobertos no CyberPanel/OpenLiteSpeed.
     */
    public function sites(Request $request): Response
    {
        $server   = $this->server($request);
        $serverId = (int) $server['id'];

        $sites = $request->input('sites', []);

        if (!\is_array($sites)) {
            return $this->apiError('O campo "sites" deve ser uma lista.', 422, 'invalid_payload');
        }

        // Lista vazia e legitima (servidor sem sites), mas registramos para
        // diferenciar de falha silenciosa na descoberta.
        if ($sites === []) {
            Logger::warning('Coleta de sites veio vazia', ['server_id' => $serverId]);
        }

        $domains = $request->input('domains', []);

        $result = SiteIngestService::store(
            $serverId,
            $sites,
            $request->bool('finalize', true),
            \is_array($domains) ? $domains : []
        );

        if ($result['errors'] !== []) {
            Logger::warning('Coleta de sites com erros parciais', [
                'server_id' => $serverId,
                'erros'     => \array_slice($result['errors'], 0, 5),
            ]);
        }

        AuditService::agentCommunication($serverId, 'sites', [
            'recebidos'    => $result['received'],
            'criados'      => $result['created'],
            'offline'      => $result['offline'],
            'nao_achados'  => $result['undiscovered'],
        ]);

        return $this->agentResponse([
            'received'        => $result['received'],
            'created'         => $result['created'],
            'updated'         => $result['updated'],
            'skipped'         => $result['skipped'],
            'offline'         => $result['offline'],
            'undiscovered'    => $result['undiscovered'],
            'alerts_resolved' => $result['alerts_resolved'],
            'received_at'     => now_string(),
        ]);
    }

    /**
     * Recebe o estado dos servicos (OpenLiteSpeed, MariaDB, Redis, CyberPanel, PHP).
     */
    public function services(Request $request): Response
    {
        $server   = $this->server($request);
        $serverId = (int) $server['id'];

        $services = $request->input('services', []);

        if (!\is_array($services)) {
            return $this->apiError('O campo "services" deve ser uma lista ou objeto.', 422, 'invalid_payload');
        }

        $result = ServiceIngestService::store($serverId, $services);

        return $this->agentResponse([
            'received'    => $result['received'],
            'stored'      => $result['stored'],
            'skipped'     => $result['skipped'],
            'received_at' => now_string(),
        ]);
    }

    /**
     * Resposta padrao para o agente.
     *
     * Alem dos dados de confirmacao, devolve apenas `next_interval` - um
     * numero. Nunca instrucoes executaveis.
     *
     * @param array<string,mixed> $data
     */
    private function agentResponse(array $data): Response
    {
        $data['next_interval'] = (int) Config::get('monitoring.agent_interval', 300);
        $data['server_time']   = time();

        return $this->apiOk($data);
    }

    /** @return array<string,mixed> */
    private function server(Request $request): array
    {
        $server = $request->attribute('server');

        if (!\is_array($server)) {
            // Nao deve acontecer: o middleware garante a presenca.
            throw new \RuntimeException('Servidor nao resolvido pelo middleware de agente.');
        }

        return $server;
    }
}
