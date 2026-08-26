<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Setting extends Model
{
    protected static string $table = 'settings';

    protected static array $fillable = [
        'key', 'value', 'type', 'group', 'label', 'description',
        'unit', 'min_value', 'max_value', 'sort_order', 'updated_by',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function allOrdered(): array
    {
        return Database::select(
            'SELECT s.*, u.name AS updated_by_name
             FROM settings s
             LEFT JOIN users u ON u.id = s.updated_by
             ORDER BY s.`group` ASC, s.sort_order ASC'
        );
    }

    /** @return array<string,array<int,array<string,mixed>>> Agrupado por `group` */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::allOrdered() as $row) {
            $grouped[(string) $row['group']][] = $row;
        }

        return $grouped;
    }

    /** @return array<string,mixed>|null */
    public static function findByKey(string $key): ?array
    {
        return Database::selectOne('SELECT * FROM settings WHERE `key` = ? LIMIT 1', [$key]);
    }

    public static function updateValue(string $key, string $value, ?int $userId = null): int
    {
        return Database::statement(
            'UPDATE settings SET value = ?, updated_by = ?, updated_at = ? WHERE `key` = ?',
            [$value, $userId, now_string(), $key]
        );
    }

    /**
     * Converte o valor para o tipo declarado.
     */
    public static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'json'  => json_decode($value, true),
            default => $value,
        };
    }
}
