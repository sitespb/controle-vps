<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Leitor do arquivo .env.
 *
 * Formato suportado:
 *   CHAVE=valor
 *   CHAVE="valor com espaco"
 *   CHAVE='valor literal'
 *   # comentario de linha inteira
 *
 * Os valores ficam apenas em memoria (nao vao para $_ENV/putenv) para nao
 * vazarem em phpinfo() ou em variaveis de ambiente de processos filhos.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Remove aspas envolventes preservando o conteudo interno.
            $len = \strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last  = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$vars[$key] = $value;
        }
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, self::$vars)) {
            return $default;
        }

        $value = self::$vars[$key];

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, null);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, null);

        if (\is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function has(string $key): bool
    {
        return \array_key_exists($key, self::$vars);
    }
}
