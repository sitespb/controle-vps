<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Server;
use App\Models\ServerMetric;

/**
 * Recepcao das metricas enviadas pelos agentes (secoes 6, 8 e 20 do PLAN).
 *
 * Aceita tanto o formato aninhado (que o agente envia) quanto o plano, para
 * que uma futura versao do agente possa simplificar o payload sem quebrar o
 * painel. Tudo que chega e normalizado, limitado a faixas validas e persistido
 * com prepared statement.
 */
final class MetricsIngestService
{
    /**
     * @param  array<string,mixed> $payload
     * @return array{metric_id:int,alerts:array<int,string>}
     */
    public static function store(int $serverId, string $serverName, array $payload): array
    {
        $metric = self::normalize($payload);

        $metric['server_id']  = $serverId;
        $metric['created_at'] = now_string();

        $metricId = Database::insert('server_metrics', $metric);

        // Atualiza o retrato atual do servidor.
        Database::statement(
            'UPDATE servers
             SET last_metric_at = ?, last_seen_at = ?, uptime = ?, updated_at = ?
             WHERE id = ?',
            [now_string(), now_string(), $metric['uptime'], now_string(), $serverId]
        );

        // Avalia as regras de CPU / RAM / disco.
        $alerts = [];

        try {
            $alerts = AlertService::evaluateServerMetrics($serverId, $serverName, $metric);
        } catch (\Throwable $e) {
            // Falha na avaliacao de alerta nao invalida a metrica ja gravada.
            Logger::error('Falha ao avaliar alertas de métrica: ' . $e->getMessage(), [
                'server_id' => $serverId,
            ]);
        }

        return ['metric_id' => $metricId, 'alerts' => $alerts];
    }

    /**
     * Converte o payload em colunas da tabela.
     *
     * @param  array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function normalize(array $p): array
    {
        $cpu    = \is_array($p['cpu'] ?? null) ? $p['cpu'] : [];
        $memory = \is_array($p['memory'] ?? null) ? $p['memory'] : (\is_array($p['ram'] ?? null) ? $p['ram'] : []);
        $swap   = \is_array($p['swap'] ?? null) ? $p['swap'] : [];
        $disk   = \is_array($p['disk'] ?? null) ? $p['disk'] : [];
        $load   = $p['load'] ?? [];

        // load pode vir como {"1":..,"5":..,"15":..} ou como [1,5,15].
        $load1  = self::pickLoad($load, '1', 0) ?? self::float($p['load_1'] ?? null);
        $load5  = self::pickLoad($load, '5', 1) ?? self::float($p['load_5'] ?? null);
        $load15 = self::pickLoad($load, '15', 2) ?? self::float($p['load_15'] ?? null);

        $ramTotal     = self::bytes($memory['total'] ?? $p['ram_total'] ?? null);
        $ramUsed      = self::bytes($memory['used'] ?? $p['ram_used'] ?? null);
        $ramAvailable = self::bytes($memory['available'] ?? $p['ram_available'] ?? null);

        $swapTotal = self::bytes($swap['total'] ?? $p['swap_total'] ?? null);
        $swapUsed  = self::bytes($swap['used'] ?? $p['swap_used'] ?? null);

        $diskTotal = self::bytes($disk['total'] ?? $p['disk_total'] ?? null);
        $diskUsed  = self::bytes($disk['used'] ?? $p['disk_used'] ?? null);
        $diskFree  = self::bytes($disk['free'] ?? $p['disk_free'] ?? null);

        return [
            'cpu_usage'    => self::percent($cpu['usage'] ?? $p['cpu_usage'] ?? null),
            'ram_total'    => $ramTotal,
            'ram_used'     => $ramUsed,
            'ram_available' => $ramAvailable ?? self::subtract($ramTotal, $ramUsed),
            'ram_percent'  => self::percent($memory['percent'] ?? $p['ram_percent'] ?? null)
                ?? self::ratio($ramUsed, $ramTotal),
            'swap_total'   => $swapTotal,
            'swap_used'    => $swapUsed,
            'swap_percent' => self::percent($swap['percent'] ?? $p['swap_percent'] ?? null)
                ?? self::ratio($swapUsed, $swapTotal),
            'disk_total'   => $diskTotal,
            'disk_used'    => $diskUsed,
            'disk_free'    => $diskFree ?? self::subtract($diskTotal, $diskUsed),
            'disk_percent' => self::percent($disk['percent'] ?? $p['disk_percent'] ?? null)
                ?? self::ratio($diskUsed, $diskTotal),
            'load_1'       => $load1,
            'load_5'       => $load5,
            'load_15'      => $load15,
            'uptime'       => self::positiveInt($p['uptime'] ?? null),
            'processes'    => self::positiveInt($p['processes'] ?? null),
        ];
    }

    /**
     * Atualiza os dados de identificacao do servidor vindos do heartbeat.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed> Campos efetivamente atualizados
     */
    public static function updateIdentity(int $serverId, array $payload): array
    {
        $system = \is_array($payload['system'] ?? null) ? $payload['system'] : $payload;

        $map = [
            'hostname'           => self::text($system['hostname'] ?? null, 190),
            'public_ip'          => self::ip($system['public_ip'] ?? $system['ip'] ?? null),
            'os_name'            => self::text($system['os_name'] ?? $system['os'] ?? null, 120),
            'os_version'         => self::text($system['os_version'] ?? null, 60),
            'arch'               => self::text($system['arch'] ?? null, 30),
            'kernel'             => self::text($system['kernel'] ?? null, 120),
            'cpu_model'          => self::text($system['cpu_model'] ?? null, 190),
            'cyberpanel_version' => self::text($system['cyberpanel_version'] ?? null, 40),
            'agent_version'      => self::text($payload['agent_version'] ?? null, 20),
        ];

        $cores  = self::positiveInt($system['cpu_cores'] ?? null);
        $uptime = self::positiveInt($system['uptime'] ?? $payload['uptime'] ?? null);

        if ($cores !== null && $cores <= 65535) {
            $map['cpu_cores'] = $cores;
        }

        if ($uptime !== null) {
            $map['uptime'] = $uptime;
        }

        $changes = array_filter($map, static fn ($v): bool => $v !== null);

        if ($changes !== []) {
            $changes['updated_at'] = now_string();
            Database::update('servers', $changes, ['id' => $serverId]);
        }

        return $changes;
    }

    /** Registra o heartbeat: marca o servidor como online. */
    public static function recordHeartbeat(int $serverId): void
    {
        Server::markSeen($serverId, Server::STATUS_ONLINE);
    }

    // -----------------------------------------------------------------
    // Normalizacao de valores
    // -----------------------------------------------------------------

    private static function percent(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round(max(0.0, min(100.0, (float) $value)), 2);
    }

    private static function bytes(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int < 0 ? null : $int;
    }

    private static function float(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        // DECIMAL(8,2): teto generoso o bastante para qualquer load real.
        return round(max(0.0, min(999999.99, (float) $value)), 2);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int < 0 ? null : $int;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    private static function ip(mixed $value): ?string
    {
        if (!\is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $value;
    }

    private static function ratio(?int $used, ?int $total): ?float
    {
        if ($used === null || $total === null || $total <= 0) {
            return null;
        }

        return round(min(100.0, ($used / $total) * 100), 2);
    }

    private static function subtract(?int $total, ?int $used): ?int
    {
        if ($total === null || $used === null) {
            return null;
        }

        return max(0, $total - $used);
    }

    private static function pickLoad(mixed $load, string $key, int $index): ?float
    {
        if (!\is_array($load)) {
            return null;
        }

        $value = $load[$key] ?? $load[$index] ?? null;

        return self::float($value);
    }
}
