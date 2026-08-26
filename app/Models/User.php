<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class User extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = [
        'name', 'email', 'password_hash', 'role', 'status', 'last_login_at', 'last_login_ip',
    ];

    public const ROLE_ADMIN    = 'admin';
    public const ROLE_OPERATOR = 'operator';

    /** @return array<string,string> */
    public static function roles(): array
    {
        return [
            self::ROLE_ADMIN    => 'Administrador',
            self::ROLE_OPERATOR => 'Operador',
        ];
    }

    public static function roleLabel(string $role): string
    {
        return self::roles()[$role] ?? ucfirst($role);
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [mb_strtolower(trim($email))]
        );
    }

    public static function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $sql      = 'SELECT COUNT(*) FROM users WHERE email = ?';
        $bindings = [mb_strtolower(trim($email))];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $bindings[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $bindings) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listAll(): array
    {
        return Database::select(
            'SELECT id, name, email, role, status, last_login_at, last_login_ip, created_at
             FROM users
             ORDER BY name ASC'
        );
    }

    public static function countAdmins(bool $activeOnly = true): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE role = 'admin'";

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        return (int) Database::scalar($sql);
    }

    public static function touchLogin(int $id, string $ip): void
    {
        Database::statement(
            'UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ?',
            [now_string(), $ip, $id]
        );
    }

    /** Gera o hash com o algoritmo padrao do PHP. Nunca guardamos texto puro. */
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}
