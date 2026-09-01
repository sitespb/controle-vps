<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * Protecao CSRF em toda requisicao que altera estado (secao 33 do PLAN).
 *
 * Aplica-se a POST/PUT/PATCH/DELETE do painel. A API de agentes NAO usa este
 * middleware: ela e autenticada por assinatura HMAC, que ja garante origem e
 * integridade, e nao depende de cookie de sessao (portanto nao e vulneravel a
 * CSRF por definicao).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if (\in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if (Csrf::check($request)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            throw new HttpException(419, 'Token CSRF inválido ou expirado. Recarregue a página.');
        }

        Session::flash('error', 'Sua sessão expirou. Envie o formulário novamente.');

        return Response::redirect($request->header('referer') ?? url('/'));
    }
}
