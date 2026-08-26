<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Repositorio de configuracao.
 *
 * Cada arquivo em config/ vira um "namespace" acessivel por notacao de ponto:
 *   config/app.php  ->  Config::get('app.name')
 *   config/monitoring.php -> Config::get('monitoring.thresholds.cpu.warning')
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function loadFrom(string $configDir): void
    {
        if (self::$loaded) {
            return;
        }

        foreach (glob(rtrim($configDir, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            /** @psalm-suppress UnresolvableInclude */
            $data = require $file;

            if (\is_array($data)) {
                self::$items[$key] = $data;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Sobrescreve um valor em memoria (usado pelos testes e pelas configuracoes
     * carregadas da tabela `settings`).
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref      = &self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === \count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }

            if (!isset($ref[$segment]) || !\is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
