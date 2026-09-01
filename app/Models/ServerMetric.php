<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ServerMetric extends Model
{
    protected static string $table = 'server_metrics';

    protected static bool $timestamps = false;

    protected static array $fillable = [
        'server_id', 'cpu_usage',
        'ram_total', 'ram_used', 'ram_available', 'ram_percent',
        'swap_total', 'swap_used', 'swap_percent',
        'disk_total', 'disk_used', 'disk_free', 'disk_percent',
        'load_1', 'load_5', 'load_15', 'uptime', 'processes', 'created_at',
    ];

    /** Ultima amostra de um servidor. @return array<string,mixed>|null */
    public static function latestFor(int $serverId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM server_metrics WHERE server_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$serverId]
        );
    }

    /**
     * Ultima amostra de TODOS os servidores em uma unica consulta.
     *
     * Evita o problema N+1 do dashboard e da lista de servidores: em vez de
     * uma query por servidor, uma query com subselect do id maximo por
     * server_id, que usa o indice (server_id, created_at).
     *
     * @return array<int,array<string,mixed>> Indexado por server_id
     */
    public static function latestForAll(): array
    {
        $rows = Database::select(
            'SELECT m.*
             FROM server_metrics m
             INNER JOIN (
                 SELECT server_id, MAX(id) AS max_id
                 FROM server_metrics
                 GROUP BY server_id
             ) last ON last.max_id = m.id'
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['server_id']] = $row;
        }

        return $indexed;
    }

    /**
     * O uso de CPU se manteve alto nas ultimas $confirmations amostras?
     *
     * O agente amostra /proc/stat por 500 ms uma vez a cada 5 minutos. Essa
     * fotografia e otima para o grafico e pessima para decidir alerta: um
     * unico `lsphp` compilando naquele meio segundo marca 96%, e a amostra
     * seguinte ja esta em 2%. Foi o que produziu cinco alertas "CPU alta -
     * resolvido" em sequencia num servidor cuja carga real nunca passou de
     * 50% dos nucleos.
     *
     * Exigir amostras CONSECUTIVAS acima do limite troca um alerta imediato e
     * ruidoso por um alerta tardio e verdadeiro. E o mesmo desenho de
     * SiteCheck::offlineConfirmed, pelo mesmo motivo: alarme falso custa mais
     * que atraso.
     */
    public static function cpuHighConfirmed(int $serverId, int $confirmations, float $threshold): bool
    {
        $confirmations = max(1, $confirmations);

        $rows = Database::select(
            'SELECT cpu_usage FROM server_metrics
             WHERE server_id = ? ORDER BY id DESC LIMIT ' . $confirmations,
            [$serverId]
        );

        if (\count($rows) < $confirmations) {
            return false;
        }

        foreach ($rows as $row) {
            if ($row['cpu_usage'] === null || (float) $row['cpu_usage'] < $threshold) {
                return false;
            }
        }

        return true;
    }
    /**
     * Serie temporal para os graficos.
     *
     * @param  int $hours Janela em horas (24 = ultimas 24h)
     * @return array<int,array<string,mixed>>
     */
    public static function seriesFor(int $serverId, int $hours = 24, int $maxPoints = 288): array
    {
        $rows = Database::select(
            'SELECT created_at, cpu_usage, ram_percent, disk_percent, load_1, load_5, load_15
             FROM server_metrics
             WHERE server_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at ASC',
            [$serverId, $hours]
        );

        return self::downsample($rows, $maxPoints);
    }

    /**
     * Reduz a serie a no maximo $maxPoints amostras, preservando a forma da
     * curva. Sem isso, 30 dias de disco (8.640 pontos) travariam o Chart.js.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function downsample(array $rows, int $maxPoints): array
    {
        $total = \count($rows);

        if ($total <= $maxPoints || $maxPoints < 2) {
            return $rows;
        }

        $step   = $total / $maxPoints;
        $result = [];

        for ($i = 0; $i < $maxPoints; $i++) {
            $result[] = $rows[(int) floor($i * $step)];
        }

        // Garante que o ultimo ponto real apareca no grafico.
        $result[$maxPoints - 1] = $rows[$total - 1];

        return $result;
    }

    /**
     * Medias agregadas de toda a infraestrutura, para os medidores do
     * dashboard. Uma unica consulta sobre a ultima amostra de cada servidor.
     *
     * @return array{cpu:?float,ram:?float,disk:?float,samples:int}
     */
    public static function infrastructureAverages(): array
    {
        $row = Database::selectOne(
            'SELECT
                 AVG(m.cpu_usage)    AS cpu,
                 AVG(m.ram_percent)  AS ram,
                 AVG(m.disk_percent) AS disk,
                 COUNT(*)            AS samples
             FROM server_metrics m
             INNER JOIN (
                 SELECT server_id, MAX(id) AS max_id
                 FROM server_metrics
                 GROUP BY server_id
             ) last ON last.max_id = m.id
             INNER JOIN servers s ON s.id = m.server_id
             WHERE s.status <> ?',
            [Server::STATUS_OFFLINE]
        );

        return [
            'cpu'     => isset($row['cpu']) ? (float) $row['cpu'] : null,
            'ram'     => isset($row['ram']) ? (float) $row['ram'] : null,
            'disk'    => isset($row['disk']) ? (float) $row['disk'] : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    /** Remove amostras mais antigas que N dias. Usado pelo cron de limpeza. */
    public static function pruneOlderThan(int $days, int $batchSize = 5000): int
    {
        if ($days <= 0) {
            return 0;
        }

        $totalRemoved = 0;

        // Apaga em lotes para nao segurar lock longo em tabelas grandes.
        do {
            $removed = Database::statement(
                'DELETE FROM server_metrics
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                 LIMIT ' . max(100, $batchSize),
                [$days]
            );
            $totalRemoved += $removed;
        } while ($removed > 0);

        return $totalRemoved;
    }
}
