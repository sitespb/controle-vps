<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ServerService extends Model
{
    protected static string $table = 'server_services';

    protected static array $fillable = [
        'server_id', 'name', 'label', 'status', 'version', 'detail', 'checked_at',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function forServer(int $serverId): array
    {
        return Database::select(
            'SELECT * FROM server_services WHERE server_id = ? ORDER BY name ASC',
            [$serverId]
        );
    }

    /**
     * Grava o estado de um servico (insere ou atualiza).
     *
     * ON DUPLICATE KEY UPDATE apoia-se na chave unica (server_id, name):
     * a cada coleta o agente reenvia a lista inteira e o banco resolve.
     */
    public static function upsert(int $serverId, string $name, array $data): void
    {
        Database::statement(
            'INSERT INTO server_services
                (server_id, name, label, status, version, detail, checked_at, created_at, updated_at)
             VALUES (:server_id, :name, :label, :status, :version, :detail, :checked_at, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                label      = VALUES(label),
                status     = VALUES(status),
                version    = VALUES(version),
                detail     = VALUES(detail),
                checked_at = VALUES(checked_at),
                updated_at = VALUES(updated_at)',
            // Placeholders distintos para created_at e updated_at: com
            // prepares nativos (ATTR_EMULATE_PREPARES = false) o MySQL nao
            // aceita o mesmo parametro nomeado repetido na consulta.
            [
                ':server_id'  => $serverId,
                ':name'       => $name,
                ':label'      => $data['label'] ?? null,
                ':status'     => $data['status'] ?? 'unknown',
                ':version'    => $data['version'] ?? null,
                ':detail'     => $data['detail'] ?? null,
                ':checked_at' => $data['checked_at'] ?? now_string(),
                ':created_at' => now_string(),
                ':updated_at' => now_string(),
            ]
        );
    }

    /**
     * Versao de um servico especifico, usada na tela do servidor (PHP e
     * OpenLiteSpeed aparecem no bloco de informacoes).
     */
    public static function versionOf(int $serverId, string $name): ?string
    {
        $value = Database::scalar(
            'SELECT version FROM server_services WHERE server_id = ? AND name = ? LIMIT 1',
            [$serverId, $name]
        );

        return $value === null ? null : (string) $value;
    }
}
