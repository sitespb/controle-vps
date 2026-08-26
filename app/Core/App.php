<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AgentAuthMiddleware;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;
use Throwable;

/**
 * Bootstrap e ciclo de vida da aplicacao.
 *
 * bootstrap()  -> carrega .env, config, timezone, logger, view e handlers.
 *                 Serve tanto para HTTP quanto para CLI (crons e console).
 * run()        -> monta o roteador, despacha a requisicao e envia a resposta.
 */
final class App
{
    public const VERSION = '1.0.0';

    private static bool $booted = false;

    private static string $basePath = '';

    public function __construct(string $basePath)
    {
        self::bootstrap($basePath);
    }

    public static function bootstrap(string $basePath): void
    {
        if (self::$booted) {
            return;
        }

        self::$basePath = rtrim($basePath, '/\\');
        self::$booted   = true;

        if (!\defined('BASE_PATH')) {
            \define('BASE_PATH', self::$basePath);
        }

        require_once self::$basePath . '/app/Helpers/functions.php';

        Env::load(self::$basePath . '/.env');
        Config::loadFrom(self::$basePath . '/config');

        date_default_timezone_set((string) Config::get('app.timezone', 'America/Sao_Paulo'));
        mb_internal_encoding('UTF-8');

        Logger::configure(self::$basePath . '/storage/logs');
        View::configure(self::$basePath . '/resources/views');

        self::registerErrorHandlers();
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public function run(): void
    {
        Session::start();

        $request  = Request::capture();
        $response = $this->handle($request);

        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router()->dispatch($request);
        } catch (ValidationRedirect $e) {
            return Response::redirect($e->target());
        } catch (HttpException $e) {
            return $this->renderHttpException($request, $e);
        } catch (Throwable $e) {
            Logger::exception($e, ['path' => $request->path(), 'method' => $request->method()]);

            return $this->renderThrowable($request, $e);
        }
    }

    private function router(): Router
    {
        $router = new Router();

        $router->registerMiddleware([
            'auth'    => AuthMiddleware::class,
            'guest'   => GuestMiddleware::class,
            'role'    => RoleMiddleware::class,
            'csrf'    => CsrfMiddleware::class,
            'agent'   => AgentAuthMiddleware::class,
            'api'     => ApiAuthMiddleware::class,
            'throttle' => RateLimitMiddleware::class,
        ]);

        $loadRoutes = static function (string $file) use ($router): void {
            if (is_file($file)) {
                (static function (string $__file, Router $router): void {
                    require $__file;
                })($file, $router);
            }
        };

        $loadRoutes(self::$basePath . '/routes/web.php');
        $loadRoutes(self::$basePath . '/routes/api.php');

        return $router;
    }

    private function renderHttpException(Request $request, HttpException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::apiError($e->getMessage(), $e->statusCode(), '', $e->details());
        }

        // 401 no navegador significa "faca login".
        if ($e->statusCode() === 401) {
            Session::flash('warning', 'Entre na sua conta para continuar.');

            return Response::redirect(url('/login'));
        }

        return Response::html(
            View::render('errors/generic', [
                'title'   => 'Erro ' . $e->statusCode(),
                'status'  => $e->statusCode(),
                'message' => $e->getMessage(),
            ], 'layouts/blank'),
            $e->statusCode()
        );
    }

    private function renderThrowable(Request $request, Throwable $e): Response
    {
        $debug = (bool) Config::get('app.debug', false);

        if ($request->wantsJson()) {
            return Response::apiError(
                $debug ? $e->getMessage() : 'Erro interno do servidor.',
                500,
                'server_error',
                $debug ? ['file' => $e->getFile() . ':' . $e->getLine()] : []
            );
        }

        return Response::html(
            View::render('errors/generic', [
                'title'     => 'Erro 500',
                'status'    => 500,
                'message'   => $debug ? $e->getMessage() : 'Ocorreu um erro interno. Consulte storage/logs/.',
                'exception' => $debug ? $e : null,
            ], 'layouts/blank'),
            500
        );
    }

    private static function registerErrorHandlers(): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_reporting(E_ALL);
        ini_set('display_errors', $debug && \PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            Logger::exception($e);

            if (\PHP_SAPI === 'cli') {
                fwrite(\STDERR, 'ERRO: ' . $e->getMessage() . \PHP_EOL);
                fwrite(\STDERR, $e->getFile() . ':' . $e->getLine() . \PHP_EOL);
                exit(1);
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }

            echo '<h1>Erro interno</h1><p>Consulte storage/logs/ para detalhes.</p>';
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !\in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::error('Erro fatal: ' . $error['message'], [
                'file' => $error['file'] . ':' . $error['line'],
            ]);
        });
    }
}
