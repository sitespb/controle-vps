<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Rate limiting basico da API (secao 33 do PLAN).
 *
 * Janela fixa por bucket, persistida em `api_rate_limits`. Tabela em vez de
 * arquivo/APCu porque o limite precisa valer para todos os processos PHP ao
 * mesmo tempo - com FPM/OLS cada requisicao pode cair em um worker diferente.
 *
 * A operacao e um unico INSERT ... ON DUPLICATE KEY UPDATE atomico: o
 * contador reinicia quando a janela expira e incrementa caso contrario, sem
 * SELECT antes (que abriria corrida entre requisicoes simultaneas).
 */
final class RateLimiter
{
    /**
     * @return array{allowed:bool,hits:int,limit:int,retry_after:int}
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $bucket = mb_substr($bucket, 0, 150);

        try {
            Database::statement(
                'INSERT INTO api_rate_limits (bucket, hits, window_start, updated_at)
                 VALUES (:bucket, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                     hits = IF(window_start < DATE_SUB(NOW(), INTERVAL :win SECOND), 1, hits + 1),
                     window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL :win2 SECOND), NOW(), window_start),
                     updated_at = NOW()',
                [':bucket' => $bucket, ':win' => $windowSeconds, ':win2' => $windowSeconds]
            );

            $row = Database::selectOne(
                'SELECT hits, TIMESTAMPDIFF(SECOND, window_start, NOW()) AS elapsed
                 FROM api_rate_limits WHERE bucket = ? LIMIT 1',
                [$bucket]
            );
        } catch (\Throwable $e) {
            // Falha no controle de limite nao pode bloquear a coleta legitima.
            Logger::warning('Rate limiter indisponível: ' . $e->getMessage(), ['bucket' => $bucket]);

            return ['allowed' => true, 'hits' => 0, 'limit' => $limit, 'retry_after' => 0];
        }

        $hits    = (int) ($row['hits'] ?? 1);
        $elapsed = (int) ($row['elapsed'] ?? 0);

        return [
            'allowed'     => $hits <= $limit,
            'hits'        => $hits,
            'limit'       => $limit,
            'retry_after' => max(1, $windowSeconds - $elapsed),
        ];
    }

    /** Consulta sem incrementar. */
    public static function hits(string $bucket, int $windowSeconds): int
    {
        $row = Database::selectOne(
            'SELECT hits, window_start FROM api_rate_limits WHERE bucket = ? LIMIT 1',
            [mb_substr($bucket, 0, 150)]
        );

        if ($row === null) {
            return 0;
        }

        $started = strtotime((string) $row['window_start']);

        if ($started === false || (time() - $started) > $windowSeconds) {
            return 0;
        }

        return (int) $row['hits'];
    }

    public static function clear(string $bucket): void
    {
        Database::delete('api_rate_limits', ['bucket' => mb_substr($bucket, 0, 150)]);
    }

    /** Remove buckets inativos (cron de limpeza). */
    public static function prune(int $hours = 24): int
    {
        return Database::statement(
            'DELETE FROM api_rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [max(1, $hours)]
        );
    }
}
