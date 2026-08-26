<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ServerToken extends Model
{
    protected static string $table = 'server_tokens';

    protected static bool $timestamps = false;

    protected static array $fillable = [
        'server_id', 'token_hash', 'token_prefix', 'created_by', 'created_at',
        'last_used_at', 'last_used_ip', 'revoked_at',
    ];

    /** Token ativo (nao revogado) de um servidor. @return array<string,mixed>|null */
    public static function activeFor(int $serverId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM server_tokens
             WHERE server_id = ? AND revoked_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$serverId]
        );
    }

    /**
     * Busca pelo hash. E o caminho usado na autenticacao do agente: o hash
     * chega calculado, entao a consulta usa o indice unico.
     *
     * @return array<string,mixed>|null
     */
    public static function findActiveByHash(string $hash): ?array
    {
        return Database::selectOne(
            'SELECT * FROM server_tokens WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1',
            [$hash]
        );
    }

    public static function revokeAllFor(int $serverId): int
    {
        return Database::statement(
            'UPDATE server_tokens SET revoked_at = ? WHERE server_id = ? AND revoked_at IS NULL',
            [now_string(), $serverId]
        );
    }

    public static function touchUsage(int $tokenId, string $ip): void
    {
        Database::statement(
            'UPDATE server_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ?',
            [now_string(), $ip, $tokenId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function historyFor(int $serverId): array
    {
        return Database::select(
            'SELECT t.*, u.name AS created_by_name
             FROM server_tokens t
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.server_id = ?
             ORDER BY t.id DESC',
            [$serverId]
        );
    }
}
