<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Site extends Model
{
    protected static string $table = 'sites';

    protected static array $fillable = [
        'server_id', 'domain', 'url', 'status', 'http_status', 'response_time',
        'https_available', 'ip', 'php_version', 'wordpress_detected', 'wordpress_version',
        'document_root', 'last_error', 'last_check_at', 'last_online_at', 'discovered', 'is_demo',
    ];

    public const STATUS_ONLINE  = 'online';
    public const STATUS_WARNING = 'warning';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_UNKNOWN = 'unknown';

    /** @return array<string,mixed>|null */
    public static function findByServerAndDomain(int $serverId, string $domain): ?array
    {
        return Database::selectOne(
            'SELECT * FROM sites WHERE server_id = ? AND domain = ? LIMIT 1',
            [$serverId, mb_strtolower($domain)]
        );
    }

    /**
     * Site com os dados do servidor e do certificado - usado na pagina
     * individual do dominio. Uma consulta, tres tabelas.
     *
     * @return array<string,mixed>|null
     */
    public static function findDetailed(int $id): ?array
    {
        return Database::selectOne(
            'SELECT
                 s.*,
                 srv.name AS server_name,
                 srv.status AS server_status,
                 srv.ip AS server_ip,
                 srv.provider AS server_provider,
                 cert.issuer AS ssl_issuer,
                 cert.subject AS ssl_subject,
                 cert.valid_from AS ssl_valid_from,
                 cert.valid_until AS ssl_valid_until,
                 cert.days_remaining AS ssl_days_remaining,
                 cert.status AS ssl_status,
                 cert.error AS ssl_error,
                 cert.checked_at AS ssl_checked_at
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             LEFT JOIN ssl_certificates cert ON cert.site_id = s.id
             WHERE s.id = ?
             LIMIT 1',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forServer(int $serverId, int $limit = 200): array
    {
        return Database::select(
            'SELECT s.*, cert.status AS ssl_status, cert.days_remaining AS ssl_days_remaining,
                    cert.valid_until AS ssl_valid_until
             FROM sites s
             LEFT JOIN ssl_certificates cert ON cert.site_id = s.id
             WHERE s.server_id = ?
             ORDER BY s.domain ASC
             LIMIT ' . max(1, $limit),
            [$serverId]
        );
    }

    /** @return array<int,int> server_id => quantidade de sites */
    public static function countByServer(): array
    {
        $rows = Database::select(
            'SELECT server_id, COUNT(*) AS total FROM sites WHERE discovered = 1 GROUP BY server_id'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['server_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * O operador marcou "ciente" neste dominio?
     *
     * Consultado antes de cada aviso. E uma leitura por site por ciclo, mas
     * pela chave primaria - mais barato do que carregar a linha inteira so
     * para ler um flag.
     */
    public static function isNotifyMuted(int $siteId): bool
    {
        return (int) Database::scalar(
            'SELECT notify_muted FROM sites WHERE id = ?',
            [$siteId]
        ) === 1;
    }

    /** Liga ou desliga o "ciente" de um dominio. */
    public static function setNotifyMuted(int $siteId, bool $muted, ?int $userId = null): void
    {
        Database::update('sites', [
            'notify_muted'    => $muted ? 1 : 0,
            'notify_muted_at' => $muted ? now_string() : null,
            'notify_muted_by' => $muted ? $userId : null,
            'updated_at'      => now_string(),
        ], ['id' => $siteId]);
    }

    /**
     * Desfaz o "ciente" quando o site volta a responder.
     *
     * E o que torna o switcher seguro de usar: quem silencia um dominio hoje
     * nao precisa lembrar de reativa-lo, e uma queda futura volta a avisar
     * normalmente. Esquecer um dominio silenciado para sempre seria pior do
     * que o ruido que o switcher veio resolver.
     *
     * @return bool true quando havia algo a limpar
     */
    public static function clearNotifyMuted(int $siteId): bool
    {
        $affected = Database::statement(
            'UPDATE sites SET notify_muted = 0, notify_muted_at = NULL, notify_muted_by = NULL, updated_at = ?
             WHERE id = ? AND notify_muted = 1',
            [now_string(), $siteId]
        );

        return $affected > 0;
    }

    /**
     * Marca como "nao descobertos" os dominios que sumiram da ultima lista
     * enviada pelo agente. Nao apaga: preserva historico (secao 21 do PLAN).
     *
     * DEVOLVE OS SITES INVALIDADOS, e nao apenas a contagem, porque quem
     * chama precisa encerrar os alertas abertos deles: um dominio que nao
     * existe mais no servidor nao pode continuar reportando SSL vencido ou
     * site offline para sempre. O SELECT roda ANTES do UPDATE justamente
     * porque depois dele a condicao `discovered = 1` nao encontraria mais
     * nada.
     *
     * @param  array<int,string> $keepDomains
     * @return array<int,array{id:int,domain:string}>
     */
    public static function markMissingAsUndiscovered(int $serverId, array $keepDomains): array
    {
        if ($keepDomains === []) {
            // Lista vazia pode ser falha de coleta: nao invalida nada.
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($keepDomains), '?'));
        $domains      = array_map('mb_strtolower', $keepDomains);

        $missing = Database::select(
            "SELECT id, domain FROM sites
             WHERE server_id = ? AND discovered = 1 AND domain NOT IN ($placeholders)",
            array_merge([$serverId], $domains)
        );

        if ($missing === []) {
            return [];
        }

        Database::statement(
            "UPDATE sites SET discovered = 0, updated_at = ?
             WHERE server_id = ? AND discovered = 1 AND domain NOT IN ($placeholders)",
            array_merge([now_string(), $serverId], $domains)
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'domain' => (string) $row['domain']],
            $missing
        );
    }
}
