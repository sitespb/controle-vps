<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  Rotas da API (JSON)
 * ============================================================================
 *
 * Duas superficies SEPARADAS, conforme a secao 30 do PLAN:
 *
 *   /api/v1/agent/*   - agentes. Autenticacao por assinatura HMAC + nonce.
 *                       Nao usa sessao nem cookie. Somente POST de entrada.
 *
 *   /api/v1/*         - painel. Autenticacao pela sessao do navegador.
 *                       Consumida pelo fetch das telas.
 *
 * Nenhuma rota administrativa fica exposta sem autenticacao. A unica rota
 * publica e /api/v1/health, que devolve apenas "ok" - nenhum dado de
 * infraestrutura, versao ou configuracao.
 *
 * @var \App\Core\Router $router
 */

use App\Controllers\Api\AgentController;
use App\Controllers\Api\PanelController;
use App\Core\Response;

// ---------------------------------------------------------------------------
// Publico - verificacao de vida do painel (usada pelo agente e por monitores)
// ---------------------------------------------------------------------------
$router->get('/api/v1/health', static fn (): Response => Response::apiOk(['status' => 'ok']), ['throttle:60,60,health']);

// ---------------------------------------------------------------------------
// API DOS AGENTES
// ---------------------------------------------------------------------------
// Ordem dos middlewares:
//   1. throttle - barra flood antes de qualquer trabalho de criptografia;
//   2. agent    - valida assinatura, timestamp, nonce e token.
//
// NAO ha middleware csrf aqui de proposito: a autenticacao e por assinatura,
// nao por cookie de sessao, portanto o vetor CSRF nao se aplica.
//
// Todos os verbos sao POST: a API de agentes so RECEBE dados. Nao existe
// endpoint que devolva instrucoes executaveis (secao 5 do PLAN).
$router->group(
    ['prefix' => '/api/v1/agent', 'middleware' => ['throttle:120,60,agent', 'agent']],
    static function ($router): void {
        $router->post('/heartbeat', [AgentController::class, 'heartbeat']);
        $router->post('/metrics', [AgentController::class, 'metrics']);
        $router->post('/sites', [AgentController::class, 'sites']);
        $router->post('/services', [AgentController::class, 'services']);
    }
);

// ---------------------------------------------------------------------------
// API DO PAINEL (sessao)
// ---------------------------------------------------------------------------
$router->group(
    ['prefix' => '/api/v1', 'middleware' => ['api']],
    static function ($router): void {
        $router->get('/status', [PanelController::class, 'status']);
        $router->get('/dashboard/summary', [PanelController::class, 'summary']);

        $router->get('/servers', [PanelController::class, 'servers']);
        $router->get('/servers/{id:\d+}/metrics', [PanelController::class, 'serverMetrics']);

        $router->get('/sites', [PanelController::class, 'sites']);
        $router->get('/sites/{id:\d+}/checks', [PanelController::class, 'siteChecks']);

        $router->get('/alerts', [PanelController::class, 'alerts']);
        $router->post('/alerts/{id:\d+}/acknowledge', [PanelController::class, 'acknowledgeAlert'], ['csrf']);
        $router->post('/alerts/{id:\d+}/resolve', [PanelController::class, 'resolveAlert'], ['csrf']);
    }
);
