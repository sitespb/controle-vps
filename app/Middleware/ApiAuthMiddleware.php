<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\SettingsService;
use Closure;

/**
 * Autenticacao das APIs consumidas pelo proprio painel (fetch/AJAX).
 *
 * Sao endpoints de leitura protegidos pela mesma sessao do navegador. Ficam
 * DELIBERADAMENTE separados da API de agentes (secao 30 do PLAN): mecanismos
 * de autenticacao diferentes, superficies diferentes.
 *
 * Responde sempre em JSON - nunca redireciona, para nao devolver HTML de
 * login dentro de um fetch.
 */
final class ApiAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if (!AuthService::check() || AuthService::user() === null) {
            throw HttpException::unauthorized('Sessão expirada. Faca login novamente.');
        }

        SettingsService::applyOverrides();

        return $next($request);
    }
}
