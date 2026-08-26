<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Consultas da pagina de alertas.
 */
final class AlertRepository
{
    private const SORTABLE = [
        'created_at' => 'a.created_at',
        'last_seen'  => 'a.last_seen_at',
        'severity'   => "FIELD(a.severity,'critical','warning','info')",
        'type'       => 'a.type',
        'status'     => 'a.status',
    ];

    /**
     * @param  array{status?:string,severity?:string,type?:string,server_id?:int,search?:string,sort?:string,direction?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where    = [];
        $bindings = [];

        // Padrao da tela: apenas o que exige atencao.
        $status = $filters['status'] ?? 'active';

        if ($status === 'active') {
            $where[] = "a.status IN ('open','acknowledged')";
        } elseif ($status !== 'all' && $status !== '') {
            $where[]    = 'a.status = ?';
            $bindings[] = $status;
        }

        if (!empty($filters['severity'])) {
            $where[]    = 'a.severity = ?';
            $bindings[] = $filters['severity'];
        }

        if (!empty($filters['type'])) {
            $where[]    = 'a.type = ?';
            $bindings[] = $filters['type'];
        }

        if (!empty($filters['server_id'])) {
            $where[]    = 'a.server_id = ?';
            $bindings[] = (int) $filters['server_id'];
        }

        if (!empty($filters['search'])) {
            $where[]    = '(a.title LIKE ? OR a.message LIKE ?)';
            $term       = '%' . $filters['search'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM alerts a $whereSql", $bindings);

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $orderColumn = self::SORTABLE[$filters['sort'] ?? 'severity'] ?? self::SORTABLE['severity'];
        $direction   = strtoupper($filters['direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $items = Database::select(
            "SELECT a.*, srv.name AS server_name, s.domain AS site_domain, u.name AS acknowledged_by_name
             FROM alerts a
             LEFT JOIN servers srv ON srv.id = a.server_id
             LEFT JOIN sites s ON s.id = a.site_id
             LEFT JOIN users u ON u.id = a.acknowledged_by
             $whereSql
             ORDER BY $orderColumn $direction, a.last_seen_at DESC
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

    /** @return array<string,mixed>|null */
    public function findDetailed(int $id): ?array
    {
        return Database::selectOne(
            'SELECT a.*, srv.name AS server_name, s.domain AS site_domain, u.name AS acknowledged_by_name
             FROM alerts a
             LEFT JOIN servers srv ON srv.id = a.server_id
             LEFT JOIN sites s ON s.id = a.site_id
             LEFT JOIN users u ON u.id = a.acknowledged_by
             WHERE a.id = ?
             LIMIT 1',
            [$id]
        );
    }

    /**
     * Alertas dos ultimos N dias agrupados por dia e severidade - alimenta o
     * grafico de tendencia da pagina de metricas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dailyTrend(int $days = 14): array
    {
        return Database::select(
            'SELECT DATE(created_at) AS dia, severity, COUNT(*) AS total
             FROM alerts
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at), severity
             ORDER BY dia ASC',
            [$days]
        );
    }

    /** @return array<int,array<string,mixed>> Alertas de um servidor. */
    public function forServer(int $serverId, int $limit = 20): array
    {
        return Database::select(
            "SELECT a.*, s.domain AS site_domain
             FROM alerts a
             LEFT JOIN sites s ON s.id = a.site_id
             WHERE a.server_id = ?
             ORDER BY FIELD(a.status,'open','acknowledged','resolved'), a.last_seen_at DESC
             LIMIT " . max(1, $limit),
            [$serverId]
        );
    }

    /** @return array<int,array<string,mixed>> Alertas de um site. */
    public function forSite(int $siteId, int $limit = 20): array
    {
        return Database::select(
            "SELECT a.* FROM alerts a
             WHERE a.site_id = ?
             ORDER BY FIELD(a.status,'open','acknowledged','resolved'), a.last_seen_at DESC
             LIMIT " . max(1, $limit),
            [$siteId]
        );
    }
}
