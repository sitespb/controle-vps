<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use Closure;

/**
 * Restringe a rota a determinados papeis (secao 23 do PLAN).
 *
 *   'role:admin'            => somente administradores
 *   'role:admin,operator'   => qualquer um dos dois
 *
 * O papel `admin` sempre passa (ver AuthService::hasRole). A V1 tem apenas
 * dois papeis, mas a assinatura ja aceita a lista para o dia em que houver
 * mais.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        $roles = $parameter === null || $parameter === ''
            ? ['admin']
            : array_map('trim', explode(',', $parameter));

        if (!AuthService::hasRole(...$roles)) {
            throw HttpException::forbidden(
                'Esta area e restrita a administradores. Seu perfil e Operador (somente leitura).'
            );
        }

        return $next($request);
    }
}
