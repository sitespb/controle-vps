<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Closure;

/**
 * Autenticacao dos agentes (secao 5 do PLAN).
 *
 * Headers exigidos em toda chamada de /api/v1/agent/*:
 *
 *   X-Server-Id   id numerico do servidor
 *   X-Timestamp   unix timestamp da requisicao
 *   X-Nonce       valor aleatorio, unico por servidor
 *   X-Signature   HMAC-SHA256 da string canonica
 *
 * A verificacao (TokenService::verifyRequest) confere, nesta ordem:
 *   1. formato dos cabecalhos;
 *   2. janela de tempo (AGENT_CLOCK_SKEW, padrao 300 s);
 *   3. existencia de token ativo para aquele servidor;
 *   4. assinatura, com hash_equals (comparacao em tempo constante);
 *   5. nonce inedito, garantido por chave unica no banco (anti replay).
 *
 * Nao ha caminho algum que permita o painel enviar comandos ao agente: este
 * middleware so autentica dados de ENTRADA. Ver docs/ARQUITETURA.md.
 */
final class AgentAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        SettingsService::applyOverrides();

        $body    = $request->rawBody();
        $maxBody = (int) Config::get('monitoring.agent_api.max_body', 524288);

        if (\strlen($body) > $maxBody) {
            return $this->deny($request, 'Corpo da requisicao excede o limite permitido.', 'payload_too_large', 413);
        }

        $serverId  = (int) ($request->header('x-server-id') ?? 0);
        $timestamp = (int) ($request->header('x-timestamp') ?? 0);
        $nonce     = (string) ($request->header('x-nonce') ?? '');
        $signature = (string) ($request->header('x-signature') ?? '');

        $result = TokenService::verifyRequest($serverId, $timestamp, $nonce, $signature, $body);

        if (!$result['ok']) {
            return $this->deny($request, $result['error'], $result['code'], $result['status'], $serverId);
        }

        $server = Server::find($serverId);

        if ($server === null) {
            return $this->deny($request, 'Servidor nao encontrado.', 'server_not_found', 404, $serverId);
        }

        // Marca o uso do token (rastreabilidade sem expor o segredo).
        ServerToken::touchUsage((int) $result['token']['id'], $request->ip());

        $request->setAttribute('server', $server);
        $request->setAttribute('server_id', $serverId);
        $request->setAttribute('token_id', (int) $result['token']['id']);

        return $next($request);
    }

    private function deny(
        Request $request,
        string $message,
        string $code,
        int $status,
        int $serverId = 0
    ): Response {
        // Registrado como erro de API (secao 31), sempre sem o segredo.
        Logger::warning('Agente recusado: ' . $message, [
            'code'      => $code,
            'server_id' => $serverId,
            'ip'        => $request->ip(),
            'path'      => $request->path(),
        ]);

        AuditService::log('agent.denied', sprintf('Requisicao de agente recusada: %s', $message), [
            'level'       => 'warning',
            'user_id'     => null,
            'actor'       => 'agente',
            'entity_type' => $serverId > 0 ? 'server' : null,
            'entity_id'   => $serverId > 0 ? $serverId : null,
            'ip'          => $request->ip(),
            'context'     => ['code' => $code, 'path' => $request->path()],
        ]);

        return Response::apiError($message, $status, $code);
    }
}
