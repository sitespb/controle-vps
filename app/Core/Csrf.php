<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Protecao CSRF por token de sessao.
 *
 * O token e gerado uma vez por sessao (padrao "per-session token"), enviado
 * em campo oculto nos formularios e no header X-CSRF-Token nas chamadas fetch.
 * A comparacao usa hash_equals para evitar timing attack.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public const FIELD = '_token';

    public const HEADER = 'x-csrf-token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!\is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function check(Request $request): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!\is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $request->input(self::FIELD);

        if (!\is_string($provided) || $provided === '') {
            $provided = $request->header(self::HEADER, '');
        }

        if (!\is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /** Campo pronto para colar dentro de um <form>. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /** Invalida o token atual (chamado no logout). */
    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
