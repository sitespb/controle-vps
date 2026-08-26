<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';

    protected static bool $timestamps = false;

    protected static array $fillable = [
        'user_id', 'actor', 'action', 'entity_type', 'entity_id',
        'description', 'level', 'ip', 'user_agent', 'context', 'created_at',
    ];

    /**
     * Consulta paginada com filtros. Retorna itens + total.
     *
     * @param  array{action?:string,level?:string,user_id?:int,search?:string,from?:string,to?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where    = [];
        $bindings = [];

        if (!empty($filters['action'])) {
            $where[]    = 'l.action = ?';
            $bindings[] = $filters['action'];
        }

        if (!empty($filters['level'])) {
            $where[]    = 'l.level = ?';
            $bindings[] = $filters['level'];
        }

        if (!empty($filters['user_id'])) {
            $where[]    = 'l.user_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $where[]    = '(l.description LIKE ? OR l.actor LIKE ?)';
            $term       = '%' . $filters['search'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (!empty($filters['from'])) {
            $where[]    = 'l.created_at >= ?';
            $bindings[] = $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['to'])) {
            $where[]    = 'l.created_at <= ?';
            $bindings[] = $filters['to'] . ' 23:59:59';
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM audit_logs l $whereSql",
            $bindings
        );

        $offset = max(0, ($page - 1) * $perPage);

        $items = Database::select(
            "SELECT l.*, u.name AS user_name
             FROM audit_logs l
             LEFT JOIN users u ON u.id = l.user_id
             $whereSql
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT " . max(1, $perPage) . ' OFFSET ' . $offset,
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,string> Acoes distintas, para popular o filtro. */
    public static function distinctActions(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['action'],
            Database::select('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC')
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentForEntity(string $type, int $id, int $limit = 10): array
    {
        return Database::select(
            'SELECT l.*, u.name AS user_name
             FROM audit_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = ? AND l.entity_id = ?
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT ' . max(1, $limit),
            [$type, $id]
        );
    }

    public static function pruneOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return Database::statement(
            'DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
    }
}
