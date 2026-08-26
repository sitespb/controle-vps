<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use Closure;

/**
 * Rate limiting basico (secao 33 do PLAN).
 *
 * Uso nas rotas:
 *   'throttle'            => usa o padrao da configuracao
 *   'throttle:30,60'      => 30 requisicoes por 60 segundos
 *   'throttle:5,300,login' => 5 por 300 s no bucket "login"
 *
 * O bucket combina o nome informado, o IP e - quando existir - o servidor
 * identificado no header, para que um agente barulhento nao consuma a cota de
 * outro.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        [$limit, $window, $name] = $this->parse($parameter);

        $bucket = $this->bucketFor($request, $name);
        $result = RateLimiter::hit($bucket, $limit, $window);

        if (!$result['allowed']) {
            throw HttpException::tooManyRequests(sprintf(
                'Limite de %d requisicoes por %d segundos atingido. Tente novamente em %d s.',
                $limit,
                $window,
                $result['retry_after']
            ));
        }

        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $limit - $result['hits']));
    }

    /** @return array{0:int,1:int,2:string} */
    private function parse(?string $parameter): array
    {
        $defaultLimit  = (int) Config::get('monitoring.agent_api.rate_limit', 120);
        $defaultWindow = (int) Config::get('monitoring.agent_api.rate_window', 60);

        if ($parameter === null || $parameter === '') {
            return [$defaultLimit, $defaultWindow, 'api'];
        }

        $parts = explode(',', $parameter);

        return [
            isset($parts[0]) && is_numeric($parts[0]) ? max(1, (int) $parts[0]) : $defaultLimit,
            isset($parts[1]) && is_numeric($parts[1]) ? max(1, (int) $parts[1]) : $defaultWindow,
            isset($parts[2]) && $parts[2] !== '' ? preg_replace('/[^a-z0-9_\-]/i', '', $parts[2]) : 'api',
        ];
    }

    private function bucketFor(Request $request, string $name): string
    {
        $serverId = $request->header('x-server-id');

        if ($serverId !== null && ctype_digit($serverId)) {
            return sprintf('%s:server:%d', $name, (int) $serverId);
        }

        return sprintf('%s:ip:%s', $name, $request->ip());
    }
}
