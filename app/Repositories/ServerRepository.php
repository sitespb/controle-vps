<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;

/**
 * Consultas compostas sobre servidores.
 *
 * O objetivo aqui e performance (secao 39 do PLAN): a lista de servidores
 * carrega servidor + ultima metrica + contagem de sites + alertas abertos em
 * 4 consultas fixas, independentemente da quantidade de servidores. Nunca
 * uma consulta por linha.
 */
final class ServerRepository
{
    /**
     * Lista completa para a pagina de servidores e para o dashboard.
     *
     * @param  array{status?:string,search?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function listWithMetrics(array $filters = []): array
    {
        $where    = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            $where[]    = 's.status = ?';
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[]    = '(s.name LIKE ? OR s.hostname LIKE ? OR s.ip LIKE ? OR s.provider LIKE ?)';
            $term       = '%' . $filters['search'] . '%';
            $bindings   = array_merge($bindings, [$term, $term, $term, $term]);
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $servers = Database::select(
            "SELECT * FROM servers s $whereSql ORDER BY s.name ASC",
            $bindings
        );

        if ($servers === []) {
            return [];
        }

        $metrics    = ServerMetric::latestForAll();
        $siteCounts = Site::countByServer();
        $alerts     = $this->openAlertCountByServer();

        foreach ($servers as &$server) {
            $id     = (int) $server['id'];
            $metric = $metrics[$id] ?? null;

            $server['cpu_usage']    = $metric === null || $metric['cpu_usage'] === null ? null : (float) $metric['cpu_usage'];
            $server['ram_percent']  = $metric === null || $metric['ram_percent'] === null ? null : (float) $metric['ram_percent'];
            $server['disk_percent'] = $metric === null || $metric['disk_percent'] === null ? null : (float) $metric['disk_percent'];
            $server['load_1']       = $metric === null || $metric['load_1'] === null ? null : (float) $metric['load_1'];
            $server['ram_total']    = $metric === null ? null : (int) $metric['ram_total'];
            $server['ram_used']     = $metric === null ? null : (int) $metric['ram_used'];
            $server['disk_total']   = $metric === null ? null : (int) $metric['disk_total'];
            $server['disk_used']    = $metric === null ? null : (int) $metric['disk_used'];
            $server['metric_at']    = $metric['created_at'] ?? null;
            $server['sites_count']  = $siteCounts[$id] ?? 0;
            $server['alerts_count'] = $alerts[$id] ?? 0;
        }
        unset($server);

        return $servers;
    }

    /**
     * Servidor + ultima metrica + agregados, para a pagina individual.
     *
     * @return array<string,mixed>|null
     */
    public function findDetailed(int $id): ?array
    {
        $server = Server::find($id);

        if ($server === null) {
            return null;
        }

        $metric = ServerMetric::latestFor($id);

        $server['metric']       = $metric;
        $server['sites_count']  = (int) Database::scalar(
            'SELECT COUNT(*) FROM sites WHERE server_id = ? AND discovered = 1',
            [$id]
        );
        $server['sites_online'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM sites WHERE server_id = ? AND discovered = 1 AND status = 'online'",
            [$id]
        );
        $server['alerts_count'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE server_id = ? AND status IN ('open','acknowledged')",
            [$id]
        );

        return $server;
    }

    /** @return array<int,int> server_id => alertas abertos */
    public function openAlertCountByServer(): array
    {
        $rows = Database::select(
            "SELECT server_id, COUNT(*) AS total
             FROM alerts
             WHERE status IN ('open','acknowledged') AND server_id IS NOT NULL
             GROUP BY server_id"
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['server_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Contagem por status em uma unica consulta - alimenta os cards do
     * dashboard sem carregar as linhas.
     *
     * @return array{total:int,online:int,offline:int,warning:int,unknown:int}
     */
    public function statusSummary(): array
    {
        $rows = Database::select('SELECT status, COUNT(*) AS total FROM servers GROUP BY status');

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
     * Servidores sem heartbeat dentro da tolerancia - usado pelo cron de
     * deteccao de offline (secao 28 do PLAN).
     *
     * @return array<int,array<string,mixed>>
     */
    public function staleServers(int $toleranceSeconds): array
    {
        return Database::select(
            "SELECT id, name, status, last_seen_at
             FROM servers
             WHERE status <> 'offline'
               AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$toleranceSeconds]
        );
    }

    /**
     * Servidores que voltaram a se comunicar depois de estarem offline.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recoveredServers(int $toleranceSeconds): array
    {
        return Database::select(
            "SELECT id, name, status, last_seen_at
             FROM servers
             WHERE status = 'offline'
               AND last_seen_at IS NOT NULL
               AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$toleranceSeconds]
        );
    }

    /**
     * Ultima metrica de cada servidor ativo - entrada do processamento de
     * alertas de CPU/RAM/disco.
     *
     * @return array<int,array<string,mixed>>
     */
    public function latestMetricsWithServer(): array
    {
        return Database::select(
            "SELECT m.*, s.name AS server_name, s.status AS server_status
             FROM server_metrics m
             INNER JOIN (
                 SELECT server_id, MAX(id) AS max_id
                 FROM server_metrics
                 GROUP BY server_id
             ) last ON last.max_id = m.id
             INNER JOIN servers s ON s.id = m.server_id
             WHERE s.status <> 'offline'"
        );
    }
}
