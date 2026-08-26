<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class AlertEvent extends Model
{
    protected static string $table = 'alert_events';

    protected static bool $timestamps = false;

    protected static array $fillable = ['alert_id', 'event', 'message', 'user_id', 'created_at'];

    public const CREATED      = 'created';
    public const RECURRED     = 'recurred';
    public const ACKNOWLEDGED = 'acknowledged';
    public const RESOLVED     = 'resolved';
    public const REOPENED     = 'reopened';

    public static function record(int $alertId, string $event, ?string $message = null, ?int $userId = null): void
    {
        Database::insert('alert_events', [
            'alert_id'   => $alertId,
            'event'      => $event,
            'message'    => $message,
            'user_id'    => $userId,
            'created_at' => now_string(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forAlert(int $alertId, int $limit = 30): array
    {
        return Database::select(
            'SELECT e.*, u.name AS user_name
             FROM alert_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.alert_id = ?
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT ' . max(1, $limit),
            [$alertId]
        );
    }

    public static function eventLabel(string $event): string
    {
        return match ($event) {
            self::CREATED      => 'Alerta criado',
            self::RECURRED     => 'Problema persiste',
            self::ACKNOWLEDGED => 'Reconhecido',
            self::RESOLVED     => 'Resolvido',
            self::REOPENED     => 'Reaberto',
            default            => ucfirst($event),
        };
    }
}
