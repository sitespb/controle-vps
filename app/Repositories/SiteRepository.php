<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Consultas da pagina de sites: pesquisa, filtros, ordenacao e paginacao
 * (secao 14 do PLAN).
 *
 * Toda ordenacao passa por uma allowlist de colunas - o parametro `sort` da
 * URL nunca chega ao SQL como texto livre.
 */
final class SiteRepository
{
    /** Colunas permitidas em ORDER BY. */
    private const SORTABLE = [
        'domain'        => 's.domain',
        'server'        => 'srv.name',
        'status'        => 's.status',
        'http_status'   => 's.http_status',
        'ssl'           => 'cert.days_remaining',
        'ssl_expiry'    => 'cert.valid_until',
        'php'           => 's.php_version',
        'response_time' => 's.response_time',
        'last_check'    => 's.last_check_at',
        'disk'          => 's.disk_usage',
    ];

    /**
     * Dominios hospedados em MAIS DE UM servidor.
     *
     * A chave unica de `sites` e (server_id, domain), entao o mesmo dominio em
     * dois servidores ja existe como duas linhas - detectar e so agrupar. Usa
     * o indice `idx_sites_domain`.
     *
     * `COUNT(DISTINCT server_id)` em vez de `COUNT(*)`: a unicidade ja impede
     * duas linhas do mesmo par, mas o DISTINCT deixa a intencao explicita e
     * sobrevive a uma mudanca futura no indice.
     */
    private const DUPLICATE_DOMAINS_SQL = 'SELECT domain
             FROM sites
             WHERE discovered = 1
             GROUP BY domain
             HAVING COUNT(DISTINCT server_id) > 1';

    /**
     * @param array{
     *     search?:string, server_id?:int, status?:string, ssl?:string,
     *     wordpress?:string, duplicados?:string, sort?:string, direction?:string
     * } $filters
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where    = ['s.discovered = 1'];
        $bindings = [];

        if (!empty($filters['search'])) {
            $where[]    = 's.domain LIKE ?';
            $bindings[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['server_id'])) {
            $where[]    = 's.server_id = ?';
            $bindings[] = (int) $filters['server_id'];
        }

        if (!empty($filters['status'])) {
            $where[]    = 's.status = ?';
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['ssl'])) {
            if ($filters['ssl'] === 'none') {
                $where[] = 'cert.id IS NULL';
            } else {
                $where[]    = 'cert.status = ?';
                $bindings[] = $filters['ssl'];
            }
        }

        if (isset($filters['wordpress']) && $filters['wordpress'] !== '') {
            $where[]    = 's.wordpress_detected = ?';
            $bindings[] = $filters['wordpress'] === 'yes' ? 1 : 0;
        }

        if (!empty($filters['duplicados'])) {
            // Tabela derivada em vez de IN (SELECT ... GROUP BY): assim o
            // agrupamento e materializado uma vez, e nao reavaliado por linha.
            $where[] = 's.domain IN (SELECT d.domain FROM (' . self::DUPLICATE_DOMAINS_SQL . ') AS d)';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $orderColumn = self::SORTABLE[$filters['sort'] ?? 'domain'] ?? 's.domain';
        $direction   = strtoupper($filters['direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $total = (int) Database::scalar(
            "SELECT COUNT(*)
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             LEFT JOIN ssl_certificates cert ON cert.site_id = s.id
             $whereSql",
            $bindings
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $items = Database::select(
            "SELECT
                 s.*,
                 srv.name AS server_name,
                 srv.status AS server_status,
                 cert.status AS ssl_status,
                 cert.days_remaining AS ssl_days_remaining,
                 cert.valid_until AS ssl_valid_until,
                 cert.issuer AS ssl_issuer
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             LEFT JOIN ssl_certificates cert ON cert.site_id = s.id
             $whereSql
             ORDER BY $orderColumn $direction, s.domain ASC
             LIMIT $perPage OFFSET $offset",
            $bindings
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    /**
     * Lista dos dominios duplicados, para marcar as linhas e contar.
     *
     * Devolve so os nomes - normalmente zero ou um punhado. A tela usa a lista
     * para decidir quais linhas ganham selo, o que evita uma subconsulta por
     * linha na listagem.
     *
     * @return array<int,string>
     */
    public function duplicateDomains(): array
    {
        $rows = Database::select(self::DUPLICATE_DOMAINS_SQL . ' ORDER BY domain');

        return array_map(static fn (array $row): string => (string) $row['domain'], $rows);
    }

    /**
     * As OUTRAS copias de um dominio, em servidores diferentes.
     *
     * Traz o que a decisao exige: onde o arquivo esta, quanto ocupa, e o IP em
     * que o agente daquele servidor conectou ao verificar o dominio. Esse
     * ultimo e o que permite dizer qual copia esta realmente no ar.
     *
     * @return array<int,array<string,mixed>>
     */
    public function otherCopiesOf(string $domain, int $exceptSiteId): array
    {
        return Database::select(
            'SELECT s.id, s.server_id, s.status, s.ip, s.document_root, s.disk_usage,
                    s.last_check_at, s.notify_muted,
                    srv.name AS server_name, srv.ip AS server_ip, srv.status AS server_status
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             WHERE s.domain = ? AND s.id <> ? AND s.discovered = 1
             ORDER BY srv.name ASC',
            [mb_strtolower($domain), $exceptSiteId]
        );
    }

    /**
     * Contagem por status para os cards do dashboard.
     *
     * @return array{total:int,online:int,offline:int,warning:int,unknown:int}
     */
    public function statusSummary(): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total FROM sites WHERE discovered = 1 GROUP BY status'
        );

        $summary = ['total' => 0, 'online' => 0, 'offline' => 0, 'warning' => 0, 'unknown' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $total  = (int) $row['total'];

            $summary['total'] += $total;
            if (isset($summary[$status])) {
                $summary[$status] = $total;
            }
        }

        return $summary;
    }

    /**
     * Resumo de SSL para o dashboard.
     *
     * @return array{valid:int,expiring:int,expired:int,unknown:int,none:int}
     */
    public function sslSummary(): array
    {
        // 'none' e literal, e nao parametro: com only_full_group_by ativo o
        // MySQL 8 nao reconhece dois placeholders como a mesma expressao
        // entre o SELECT e o GROUP BY. O valor e constante do proprio codigo,
        // portanto nao ha superficie para injecao.
        $rows = Database::select(
            "SELECT COALESCE(cert.status, 'none') AS status, COUNT(*) AS total
             FROM sites s
             LEFT JOIN ssl_certificates cert ON cert.site_id = s.id
             WHERE s.discovered = 1
             GROUP BY COALESCE(cert.status, 'none')"
        );

        $summary = ['valid' => 0, 'expiring' => 0, 'expired' => 0, 'unknown' => 0, 'none' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($summary[$status])) {
                $summary[$status] = (int) $row['total'];
            }
        }

        return $summary;
    }

    /**
     * Sites offline no momento - lista curta do dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public function currentlyOffline(int $limit = 10): array
    {
        return Database::select(
            "SELECT s.id, s.domain, s.http_status, s.last_check_at, s.last_error, srv.name AS server_name
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             WHERE s.discovered = 1 AND s.status = 'offline'
             ORDER BY s.last_check_at DESC
             LIMIT " . max(1, $limit)
        );
    }

    /**
     * Certificados proximos do vencimento - lista curta do dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sslExpiringSoon(int $days = 30, int $limit = 10): array
    {
        return Database::select(
            'SELECT s.id, s.domain, cert.days_remaining, cert.valid_until, cert.status, srv.name AS server_name
             FROM ssl_certificates cert
             INNER JOIN sites s ON s.id = cert.site_id
             INNER JOIN servers srv ON srv.id = s.server_id
             WHERE s.discovered = 1 AND cert.days_remaining IS NOT NULL AND cert.days_remaining <= ?
             ORDER BY cert.days_remaining ASC
             LIMIT ' . max(1, $limit),
            [$days]
        );
    }

    /** @return array<int,array<string,mixed>> Sites offline com dados para alerta. */
    public function offlineForAlerts(): array
    {
        return Database::select(
            "SELECT s.id, s.domain, s.server_id, s.http_status, s.last_error, srv.name AS server_name
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             WHERE s.discovered = 1 AND s.status = 'offline'"
        );
    }

    /** @return array<int,array<string,mixed>> Sites que voltaram a responder. */
    public function onlineForAlerts(): array
    {
        return Database::select(
            "SELECT s.id, s.domain, s.server_id, srv.name AS server_name
             FROM sites s
             INNER JOIN servers srv ON srv.id = s.server_id
             WHERE s.discovered = 1 AND s.status IN ('online','warning')"
        );
    }

    public function countWordpress(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM sites WHERE discovered = 1 AND wordpress_detected = 1'
        );
    }

    /** @return array<int,array{version:string,total:int}> Distribuicao de versoes de PHP. */
    public function phpDistribution(): array
    {
        $rows = Database::select(
            "SELECT COALESCE(NULLIF(php_version, ''), 'Desconhecido') AS version, COUNT(*) AS total
             FROM sites
             WHERE discovered = 1
             GROUP BY COALESCE(NULLIF(php_version, ''), 'Desconhecido')
             ORDER BY total DESC"
        );

        return array_map(
            static fn (array $r): array => ['version' => (string) $r['version'], 'total' => (int) $r['total']],
            $rows
        );
    }
}
