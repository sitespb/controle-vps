<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\SettingsService;
use Closure;

/**
 * Exige sessao autenticada.
 *
 * Tambem e o ponto onde as configuracoes do banco sao aplicadas sobre os
 * padroes do arquivo - assim toda pagina do painel ja enxerga os limites
 * ajustados pelo operador.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if (!AuthService::check() || AuthService::user() === null) {
            if ($request->wantsJson()) {
                throw HttpException::unauthorized('Sessão expirada ou inexistente.');
            }

            // Guarda o destino para retomar depois do login.
            Session::set('_intended', $request->fullPath());

            throw HttpException::unauthorized();
        }

        SettingsService::applyOverrides();

        return $next($request);
    }
}
