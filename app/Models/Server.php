<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Server extends Model
{
    protected static string $table = 'servers';

    protected static array $fillable = [
        'uid', 'name', 'provider', 'hostname', 'ip', 'description', 'status',
        'public_ip', 'os_name', 'os_version', 'arch', 'kernel', 'cpu_cores', 'cpu_model',
        'uptime', 'agent_version', 'cyberpanel_version',
        'last_seen_at', 'last_metric_at', 'is_demo',
    ];

    public const STATUS_ONLINE  = 'online';
    public const STATUS_WARNING = 'warning';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_UNKNOWN = 'unknown';

    /** @return array<string,mixed>|null */
    public static function findByUid(string $uid): ?array
    {
        return self::findBy('uid', $uid);
    }

    /** Lista enxuta para selects e filtros. @return array<int,array<string,mixed>> */
    public static function options(): array
    {
        return Database::select('SELECT id, name FROM servers ORDER BY name ASC');
    }

    public static function markSeen(int $id, string $status = self::STATUS_ONLINE): void
    {
        Database::statement(
            'UPDATE servers SET last_seen_at = ?, status = ?, updated_at = ? WHERE id = ?',
            [now_string(), $status, now_string(), $id]
        );
    }

    public static function updateStatus(int $id, string $status): int
    {
        return Database::statement(
            'UPDATE servers SET status = ?, updated_at = ? WHERE id = ? AND status <> ?',
            [$status, now_string(), $id, $status]
        );
    }

    /** Identificacao publica unica gerada no cadastro. */
    public static function generateUid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
