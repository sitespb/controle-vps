<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Contrato dos middlewares.
 *
 * O middleware decide se a requisicao continua ($next) ou e interrompida
 * (retornando uma Response ou lancando HttpException).
 *
 * $parameter carrega o argumento do alias, quando existir:
 *   'role:admin'        => $parameter === 'admin'
 *   'throttle:60,60'    => $parameter === '60,60'
 */
interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response;
}
