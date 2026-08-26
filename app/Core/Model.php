<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base dos models: operacoes CRUD simples sobre uma tabela.
 *
 * Consultas mais elaboradas (joins, agregacoes, filtros) ficam nos
 * Repositories - o model cuida apenas do acesso basico a linha.
 */
abstract class Model
{
    /** Nome da tabela. */
    protected static string $table = '';

    /** Colunas que podem ser gravadas por create()/update(). */
    protected static array $fillable = [];

    /** A tabela possui created_at / updated_at? */
    protected static bool $timestamps = true;

    public static function table(): string
    {
        return static::$table;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE id = ? LIMIT 1', static::$table),
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findBy(string $column, mixed $value): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', static::$table, self::safeColumn($column)),
            [$value]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $orderBy = 'id', string $direction = 'ASC'): array
    {
        return Database::select(sprintf(
            'SELECT * FROM `%s` ORDER BY `%s` %s',
            static::$table,
            self::safeColumn($orderBy),
            self::safeDirection($direction)
        ));
    }

    /**
     * @param  array<string,mixed> $conditions
     * @return array<int,array<string,mixed>>
     */
    public static function where(array $conditions, string $orderBy = 'id', string $direction = 'ASC', ?int $limit = null): array
    {
        if ($conditions === []) {
            return static::all($orderBy, $direction);
        }

        $where = implode(' AND ', array_map(
            static fn (string $c): string => sprintf('`%s` = :%s', self::safeColumn($c), $c),
            array_keys($conditions)
        ));

        $bindings = [];
        foreach ($conditions as $k => $v) {
            $bindings[':' . $k] = $v;
        }

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE %s ORDER BY `%s` %s',
            static::$table,
            $where,
            self::safeColumn($orderBy),
            self::safeDirection($direction)
        );

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return Database::select($sql, $bindings);
    }

    public static function count(array $conditions = []): int
    {
        if ($conditions === []) {
            return (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s`', static::$table));
        }

        $where = implode(' AND ', array_map(
            static fn (string $c): string => sprintf('`%s` = :%s', self::safeColumn($c), $c),
            array_keys($conditions)
        ));

        $bindings = [];
        foreach ($conditions as $k => $v) {
            $bindings[':' . $k] = $v;
        }

        return (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', static::$table, $where),
            $bindings
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $data = static::filterFillable($data);

        if (static::$timestamps) {
            $now                = now_string();
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $data['updated_at'] ?? $now;
        }

        return Database::insert(static::$table, $data);
    }

    /** @param array<string,mixed> $data */
    public static function updateById(int $id, array $data): int
    {
        $data = static::filterFillable($data);

        if ($data === []) {
            return 0;
        }

        if (static::$timestamps) {
            $data['updated_at'] = now_string();
        }

        return Database::update(static::$table, $data, ['id' => $id]);
    }

    public static function deleteById(int $id): int
    {
        return Database::delete(static::$table, ['id' => $id]);
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected static function filterFillable(array $data): array
    {
        if (static::$fillable === []) {
            return $data;
        }

        $allowed = array_merge(static::$fillable, ['created_at', 'updated_at']);

        return array_intersect_key($data, array_flip($allowed));
    }

    /** Impede injecao de identificador em ORDER BY / colunas dinamicas. */
    protected static function safeColumn(string $column): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: 'id';
    }

    protected static function safeDirection(string $direction): string
    {
        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    }
}
