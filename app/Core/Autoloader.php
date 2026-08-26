<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autoloader PSR-4 minimo.
 *
 * O projeto nao depende do Composer para funcionar: se o vendor/autoload.php
 * existir ele e usado (permite adicionar bibliotecas no futuro), caso
 * contrario este autoloader resolve o namespace App\ sozinho.
 *
 * Mapeamento: App\Core\Router  ->  app/Core/Router.php
 */
final class Autoloader
{
    private const PREFIX = 'App\\';

    private static bool $registered = false;

    public static function register(?string $basePath = null): void
    {
        if (self::$registered) {
            return;
        }

        $basePath ??= \dirname(__DIR__, 2);
        $appDir   = $basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;

        // Composer, quando disponivel, tem precedencia.
        $composer = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($composer)) {
            require_once $composer;
        }

        spl_autoload_register(static function (string $class) use ($appDir): void {
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }

            $relative = substr($class, \strlen(self::PREFIX));
            $file     = $appDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}
