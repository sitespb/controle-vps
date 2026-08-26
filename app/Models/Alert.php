<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Alert extends Model
{
    protected static string $table = 'alerts';

    protected static array $fillable = [
        'server_id', 'site_id', 'type', 'severity', 'title', 'message', 'metric_value',
        'status', 'fingerprint', 'occurrences', 'first_seen_at', 'last_seen_at',
        'acknowledged_at', 'acknowledged_by', 'resolved_at',
    ];

    // Tipos previstos na secao 18 do PLAN.
    public const TYPE_SERVER_OFFLINE     = 'server_offline';
    public const TYPE_SERVER_DISK_HIGH   = 'server_disk_high';
    public const TYPE_SERVER_MEMORY_HIGH = 'server_memory_high';
    public const TYPE_SERVER_CPU_HIGH    = 'server_cpu_high';
    public const TYPE_SITE_OFFLINE       = 'site_offline';
    public const TYPE_SSL_EXPIRING       = 'ssl_expiring';
    public const TYPE_SSL_EXPIRED        = 'ssl_expired';

    public const STATUS_OPEN         = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED     = 'resolved';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /** @return array<string,string> */
    public static function types(): array
    {
        return [
            self::TYPE_SERVER_OFFLINE     => 'Servidor offline',
            self::TYPE_SERVER_CPU_HIGH    => 'CPU alta',
            self::TYPE_SERVER_MEMORY_HIGH => 'Memoria alta',
            self::TYPE_SERVER_DISK_HIGH   => 'Disco cheio',
            self::TYPE_SITE_OFFLINE       => 'Site offline',
            self::TYPE_SSL_EXPIRING       => 'SSL vencendo',
            self::TYPE_SSL_EXPIRED        => 'SSL expirado',
        ];
    }

    public static function typeLabel(string $type): string
    {
        return self::types()[$type] ?? $type;
    }

    /**
     * Chave de deduplicacao. Mesmo problema, no mesmo alvo => mesmo
     * fingerprint => um unico alerta aberto.
     */
    public static function fingerprint(string $type, ?int $serverId, ?int $siteId): string
    {
        return sha1(sprintf('%s|%d|%d', $type, $serverId ?? 0, $siteId ?? 0));
    }

    /** @return array<string,mixed>|null */
    public static function findOpenByFingerprint(string $fingerprint): ?array
    {
        return Database::selectOne(
            "SELECT * FROM alerts
             WHERE fingerprint = ? AND status IN ('open','acknowledged')
             ORDER BY id DESC LIMIT 1",
            [$fingerprint]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function openAlerts(int $limit = 50): array
    {
        return Database::select(
            "SELECT a.*, srv.name AS server_name, s.domain AS site_domain
             FROM alerts a
             LEFT JOIN servers srv ON srv.id = a.server_id
             LEFT JOIN sites s ON s.id = a.site_id
             WHERE a.status IN ('open','acknowledged')
             ORDER BY FIELD(a.severity,'critical','warning','info'), a.last_seen_at DESC
             LIMIT " . max(1, $limit)
        );
    }

    public static function countOpen(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM alerts WHERE status IN ('open','acknowledged')"
        );
    }

    /** @return array<string,int> severidade => quantidade em aberto */
    public static function countOpenBySeverity(): array
    {
        $rows = Database::select(
            "SELECT severity, COUNT(*) AS total
             FROM alerts
             WHERE status IN ('open','acknowledged')
             GROUP BY severity"
        );

        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['severity']] = (int) $row['total'];
        }

        return $counts;
    }

    public static function pruneResolvedOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return Database::statement(
            "DELETE FROM alerts
             WHERE status = 'resolved' AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }
}
