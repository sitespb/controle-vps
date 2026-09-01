<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Executor de migrations em SQL puro.
 *
 * Cada arquivo de database/migrations/*.sql roda uma unica vez; o controle
 * fica na tabela `migrations`. Arquivos sao aplicados em ordem alfabetica,
 * por isso o prefixo numerico (001_, 002_, ...).
 *
 * LIMITACAO CONHECIDA: a separacao de comandos usa ";" no fim da linha. Isso
 * atende a DDL e INSERT, que e todo o conteudo do projeto. Se um dia for
 * preciso criar TRIGGER ou PROCEDURE (com ";" interno), este divisor precisa
 * evoluir - ou o arquivo deve virar uma migration em PHP.
 */
final class Migrator
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_dir('database/migrations');
    }

    /** Cria a tabela de controle, se ainda nao existir. */
    public function ensureTable(): void
    {
        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS `migrations` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(190) NOT NULL,
                `batch`     INT UNSIGNED NOT NULL DEFAULT 1,
                `ran_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_migrations_name` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<int,string> */
    public function applied(): array
    {
        $this->ensureTable();

        return array_map(
            static fn (array $r): string => (string) $r['migration'],
            Database::select('SELECT migration FROM migrations ORDER BY id ASC')
        );
    }

    /** @return array<int,string> Arquivos disponiveis, em ordem. */
    public function available(): array
    {
        $files = glob($this->path . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $f): string => basename($f), $files);
    }

    /** @return array<int,string> Ainda nao executadas. */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * Executa as migrations pendentes.
     *
     * @param  callable(string,string):void|null $onProgress fn(nome, status)
     * @return array{executed:array<int,string>,skipped:int,errors:array<string,string>}
     */
    public function run(?callable $onProgress = null): array
    {
        $this->ensureTable();

        $applied  = $this->applied();
        $batch    = (int) Database::scalar('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');
        $executed = [];
        $errors   = [];
        $skipped  = 0;

        foreach ($this->available() as $name) {
            if (\in_array($name, $applied, true)) {
                $skipped++;
                continue;
            }

            $file = $this->path . DIRECTORY_SEPARATOR . $name;
            $sql  = (string) file_get_contents($file);

            try {
                foreach (self::splitStatements($sql) as $statement) {
                    Database::connection()->exec($statement);
                }

                Database::insert('migrations', [
                    'migration' => $name,
                    'batch'     => $batch,
                    'ran_at'    => now_string(),
                ]);

                $executed[] = $name;

                if ($onProgress !== null) {
                    $onProgress($name, 'ok');
                }
            } catch (\Throwable $e) {
                $errors[$name] = $e->getMessage();

                if ($onProgress !== null) {
                    $onProgress($name, 'erro: ' . $e->getMessage());
                }

                // Para no primeiro erro: continuar poderia deixar o schema
                // em estado inconsistente.
                break;
            }
        }

        return ['executed' => $executed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Apaga TODAS as tabelas do banco e roda as migrations do zero.
     * Destrutivo por definicao - o console pede confirmacao antes.
     */
    public function fresh(?callable $onProgress = null): array
    {
        $pdo = Database::connection();

        $tables = array_map(
            static fn (array $r): string => (string) array_values($r)[0],
            Database::select('SHOW TABLES')
        );

        if ($tables !== []) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                // O nome vem do proprio banco, nunca de entrada do usuario.
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');

                if ($onProgress !== null) {
                    $onProgress($table, 'removida');
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $this->run($onProgress);
    }

    /**
     * Divide um arquivo SQL em comandos individuais, removendo comentarios de
     * linha inteira.
     *
     * @return array<int,string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept  = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $kept[] = $line;
        }

        $clean = implode("\n", $kept);

        $statements = [];
        foreach (explode(";\n", $clean . "\n") as $chunk) {
            $chunk = trim(rtrim(trim($chunk), ';'));

            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }

        return $statements;
    }

    /** Cria o banco caso ainda nao exista (usado pelo comando db:create). */
    public static function createDatabase(): bool
    {
        $name    = (string) Config::get('database.database', '');
        $charset = (string) Config::get('database.charset', 'utf8mb4');

        if ($name === '' || preg_match('/^[A-Za-z0-9_\-]+$/', $name) !== 1) {
            throw new \RuntimeException('Nome de banco inválido em DB_DATABASE: ' . $name);
        }

        $pdo = Database::serverConnection();
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci',
            $name,
            $charset,
            $charset
        ));

        return true;
    }
}
