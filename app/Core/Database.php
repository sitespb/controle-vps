<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Conexao PDO unica da aplicacao.
 *
 * Toda consulta do sistema passa por aqui e obrigatoriamente usa prepared
 * statements com bind de parametros - nao existe concatenacao de valores
 * dentro de SQL em nenhum ponto do projeto.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Ultimo erro de conexao, para telas de diagnostico. */
    private static ?string $lastError = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('database.host', '127.0.0.1');
        $port    = (int) Config::get('database.port', 3306);
        $name    = (string) Config::get('database.database', '');
        $charset = (string) Config::get('database.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) Config::get('database.username', 'root'),
                (string) Config::get('database.password', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Prepares reais no servidor: a melhor barreira contra SQL injection.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            throw new RuntimeException(
                'Não foi possível conectar ao banco de dados: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return self::$pdo;
    }

    /**
     * Conecta sem selecionar banco - usado pelo instalador/migrations para
     * poder criar o schema quando ele ainda nao existe.
     */
    public static function serverConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            (string) Config::get('database.host', '127.0.0.1'),
            (int) Config::get('database.port', 3306),
            (string) Config::get('database.charset', 'utf8mb4')
        );

        return new PDO(
            $dsn,
            (string) Config::get('database.username', 'root'),
            (string) Config::get('database.password', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Verifica se o banco esta acessivel sem lancar excecao.
     * Usado pelo health check e pelos crons (secao 32 do PLAN: painel sem
     * acesso ao banco nao pode derrubar o processo inteiro).
     */
    public static function isAvailable(): bool
    {
        try {
            self::connection()->query('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();

            return false;
        }
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /** @param array<string|int,mixed> $bindings */
    public static function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    /**
     * @param  array<string|int,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $bindings */
    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int,mixed> $bindings */
    public static function statement(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );

        self::run($sql, self::bindKeys($data));

        return (int) self::connection()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            return 0;
        }

        $set = implode(', ', array_map(
            static fn (string $c): string => sprintf('`%s` = :set_%s', $c, $c),
            array_keys($data)
        ));

        $conditions = implode(' AND ', array_map(
            static fn (string $c): string => sprintf('`%s` = :where_%s', $c, $c),
            array_keys($where)
        ));

        $bindings = [];
        foreach ($data as $k => $v) {
            $bindings[':set_' . $k] = $v;
        }
        foreach ($where as $k => $v) {
            $bindings[':where_' . $k] = $v;
        }

        return self::statement(
            sprintf('UPDATE `%s` SET %s WHERE %s', $table, $set, $conditions),
            $bindings
        );
    }

    /** @param array<string,mixed> $where */
    public static function delete(string $table, array $where): int
    {
        if ($where === []) {
            return 0;
        }

        $conditions = implode(' AND ', array_map(
            static fn (string $c): string => sprintf('`%s` = :%s', $c, $c),
            array_keys($where)
        ));

        return self::statement(
            sprintf('DELETE FROM `%s` WHERE %s', $table, $conditions),
            self::bindKeys($where)
        );
    }

    /**
     * Executa o callback dentro de uma transacao.
     *
     * REENTRANTE: se ja houver transacao aberta, o callback roda dentro dela
     * em vez de tentar abrir outra. Sem isso, um servico transacional que
     * chama outro servico transacional (ServerProvisionService::create ->
     * TokenService::generateFor) estouraria com "There is already an active
     * transaction" - e, pior, deixaria a transacao externa pendurada, porque
     * a excecao do beginTransaction acontece antes do try/rollback.
     *
     * O commit e o rollback pertencem sempre ao chamador mais externo, que e
     * quem define o limite real da unidade de trabalho.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function tableExists(string $table): bool
    {
        $count = self::scalar(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) $count > 0;
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function bindKeys(array $data): array
    {
        $bindings = [];
        foreach ($data as $key => $value) {
            $bindings[':' . $key] = $value;
        }

        return $bindings;
    }

    /** Usado pelos testes para injetar uma conexao propria. */
    public static function swap(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
