<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Log em arquivo, um arquivo por dia em storage/logs/.
 *
 * Regra da secao 31 do PLAN: nunca gravar senhas, tokens completos ou dados
 * sensiveis. O metodo redact() cuida disso automaticamente para as chaves
 * conhecidas, mas o chamador continua responsavel por nao passar segredos.
 */
final class Logger
{
    private const LEVELS = [
        'debug'   => 10,
        'info'    => 20,
        'warning' => 30,
        'error'   => 40,
    ];

    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'password_confirmation', 'senha',
        'token', 'token_hash', 'server_token', 'secret', 'signature',
        'authorization', 'api_key', 'csrf', '_token',
    ];

    private static ?string $directory = null;

    private static string $channel = 'app';

    public static function configure(string $directory): void
    {
        self::$directory = rtrim($directory, '/\\');
    }

    public static function channel(string $channel): void
    {
        self::$channel = preg_replace('/[^a-z0-9\-_]/i', '', $channel) ?: 'app';
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(\Throwable $e, array $context = []): void
    {
        self::write('error', $e->getMessage(), $context + [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $minimum = self::LEVELS[strtolower((string) Config::get('log.level', 'debug'))] ?? 10;

        if ((self::LEVELS[$level] ?? 0) < $minimum) {
            return;
        }

        $directory = self::$directory ?? (base_dir() . '/storage/logs');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $file = $directory . DIRECTORY_SEPARATOR . self::$channel . '-' . date('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s.%s: %s%s%s",
            date('Y-m-d H:i:s'),
            self::$channel,
            strtoupper($level),
            self::sanitizeMessage($message),
            $context === [] ? '' : ' ' . self::encodeContext($context),
            \PHP_EOL
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    private static function encodeContext(array $context): string
    {
        $json = json_encode(
            self::redact($context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? '{}' : $json;
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $out[$key] = \is_string($value) && $value !== ''
                    ? mask_secret($value)
                    : '[REDACTED]';
                continue;
            }

            $out[$key] = \is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }

    /** Remove quebras de linha para manter uma entrada por linha. */
    private static function sanitizeMessage(string $message): string
    {
        return str_replace(["\r", "\n"], ' ', $message);
    }

    /**
     * Remove arquivos de log mais antigos que N dias. Chamado pelo cron de
     * limpeza.
     */
    public static function prune(int $keepDays): int
    {
        $directory = self::$directory ?? (base_dir() . '/storage/logs');

        if (!is_dir($directory) || $keepDays <= 0) {
            return 0;
        }

        $cutoff  = time() - ($keepDays * 86400);
        $removed = 0;

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
