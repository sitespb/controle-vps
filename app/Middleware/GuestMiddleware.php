<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use Closure;

/**
 * Bloqueia paginas de visitante (login) para quem ja esta autenticado.
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if (AuthService::check() && AuthService::user() !== null) {
            return Response::redirect(url('/'));
        }

        return $next($request);
    }
}
