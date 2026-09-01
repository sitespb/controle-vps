<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Site;

/**
 * Traducao de resultado HTTP para status de site (secao 17 do PLAN).
 *
 * Regra fundamental: 4xx NAO derruba o site para offline. Um 404 ou 403
 * significa que o servidor web esta no ar e respondendo - o problema e de
 * conteudo/permissao, nao de disponibilidade. Isso vira "atencao".
 *
 *   200-399  => ONLINE
 *   400-499  => ATENCAO   (servidor no ar)
 *   500-599  => OFFLINE
 *   sem resposta / timeout => OFFLINE
 *   resposta muito lenta   => ATENCAO
 */
final class HttpStatusService
{
    public static function classify(?int $httpStatus, ?int $responseTimeMs = null, ?string $error = null): string
    {
        // Sem codigo de resposta: falha de conexao, DNS ou timeout.
        if ($httpStatus === null || $httpStatus === 0) {
            return $error === null || $error === ''
                ? Site::STATUS_UNKNOWN
                : Site::STATUS_OFFLINE;
        }

        $config = Config::get('monitoring.http', []);

        $onlineMin  = (int) ($config['online_min'] ?? 200);
        $onlineMax  = (int) ($config['online_max'] ?? 399);
        $offlineMin = (int) ($config['offline_min'] ?? 500);
        $offlineMax = (int) ($config['offline_max'] ?? 599);
        $slow       = (int) ($config['slow_response'] ?? 3000);

        if ($httpStatus >= $offlineMin && $httpStatus <= $offlineMax) {
            return Site::STATUS_OFFLINE;
        }

        if ($httpStatus >= $onlineMin && $httpStatus <= $onlineMax) {
            // No ar, porem lento: sinaliza atencao em vez de esconder.
            if ($responseTimeMs !== null && $responseTimeMs >= $slow) {
                return Site::STATUS_WARNING;
            }

            return Site::STATUS_ONLINE;
        }

        // 1xx e 4xx: servidor respondeu, mas ha algo a olhar.
        return Site::STATUS_WARNING;
    }

    /** Texto curto do significado do codigo, exibido na pagina do site. */
    public static function describe(?int $httpStatus): string
    {
        if ($httpStatus === null || $httpStatus === 0) {
            return 'Sem resposta';
        }

        return match ($httpStatus) {
            200     => '200 OK',
            201     => '201 Created',
            204     => '204 No Content',
            301     => '301 Moved Permanently',
            302     => '302 Found',
            304     => '304 Not Modified',
            307     => '307 Temporary Redirect',
            308     => '308 Permanent Redirect',
            400     => '400 Bad Request',
            401     => '401 Unauthorized',
            403     => '403 Forbidden',
            404     => '404 Not Found',
            408     => '408 Request Timeout',
            429     => '429 Too Many Requests',
            500     => '500 Internal Server Error',
            502     => '502 Bad Gateway',
            503     => '503 Service Unavailable',
            504     => '504 Gateway Timeout',
            default => (string) $httpStatus,
        };
    }

    /** Explicacao em portugues para a coluna "ultima resposta". */
    public static function explain(?int $httpStatus, ?string $error = null): string
    {
        if ($httpStatus === null || $httpStatus === 0) {
            return $error !== null && $error !== '' ? $error : 'Não foi possível conectar ao domínio.';
        }

        if ($httpStatus >= 500) {
            return 'O servidor web respondeu com erro. O site está indisponível para os visitantes.';
        }

        if ($httpStatus === 404) {
            return 'Página não encontrada. O servidor está no ar, mas o conteúdo respondeu 404.';
        }

        if ($httpStatus === 403) {
            return 'Acesso negado. O servidor está no ar, mas bloqueou a requisição.';
        }

        if ($httpStatus >= 400) {
            return 'O servidor está no ar, mas retornou um erro de requisição.';
        }

        if ($httpStatus >= 300) {
            return 'O domínio está redirecionando.';
        }

        return 'O site respondeu normalmente.';
    }
}
