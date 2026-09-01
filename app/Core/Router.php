<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Roteador com suporte a parametros, grupos e middleware.
 *
 *   $router->get('/servidores/{id}', [ServerController::class, 'show']);
 *   $router->group(['middleware' => ['auth']], function (Router $r) { ... });
 *
 * Parametros aceitam restricao inline:  {id:\d+}
 */
final class Router
{
    /** @var array<string,array<int,array{pattern:string,params:array<int,string>,action:mixed,middleware:array<int,string>,name:string}>> */
    private array $routes = [];

    /** @var array<int,array{prefix:string,middleware:array<int,string>}> */
    private array $groupStack = [];

    /** @var array<string,class-string> */
    private array $middlewareAliases = [];

    /** @param array<string,class-string> $aliases */
    public function registerMiddleware(array $aliases): void
    {
        $this->middlewareAliases = $aliases + $this->middlewareAliases;
    }

    public function get(string $uri, mixed $action, array $middleware = []): void
    {
        $this->addRoute('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, mixed $action, array $middleware = []): void
    {
        $this->addRoute('POST', $uri, $action, $middleware);
    }

    public function put(string $uri, mixed $action, array $middleware = []): void
    {
        $this->addRoute('PUT', $uri, $action, $middleware);
    }

    public function patch(string $uri, mixed $action, array $middleware = []): void
    {
        $this->addRoute('PATCH', $uri, $action, $middleware);
    }

    public function delete(string $uri, mixed $action, array $middleware = []): void
    {
        $this->addRoute('DELETE', $uri, $action, $middleware);
    }

    /**
     * @param array{prefix?:string,middleware?:array<int,string>} $attributes
     */
    public function group(array $attributes, Closure $callback): void
    {
        $this->groupStack[] = [
            'prefix'     => $attributes['prefix'] ?? '',
            'middleware' => $attributes['middleware'] ?? [],
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    private function addRoute(string $method, string $uri, mixed $action, array $middleware): void
    {
        $prefix          = '';
        $groupMiddleware = [];

        foreach ($this->groupStack as $group) {
            $prefix         .= $group['prefix'];
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware']);
        }

        $uri = '/' . trim($prefix . $uri, '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

        $params  = [];
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '(' . ($m[2] ?? '[^/]+') . ')';
            },
            $uri
        );

        $this->routes[$method][] = [
            'pattern'    => '#^' . $pattern . '$#',
            'params'     => $params,
            'action'     => $action,
            'middleware' => array_values(array_unique(array_merge($groupMiddleware, $middleware))),
            'name'       => $method . ' ' . $uri,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path   = $request->path();

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i] ?? '';
            }
            $request->setRouteParams($params);

            return $this->runPipeline($request, $route['middleware'], fn (Request $r): Response =>
                $this->callAction($route['action'], $r));
        }

        // Caminho existe em outro metodo? Entao e 405, nao 404.
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path) === 1) {
                    throw new HttpException(405);
                }
            }
        }

        throw HttpException::notFound();
    }

    /**
     * Encadeia os middlewares em torno do controller (padrao pipeline).
     *
     * @param array<int,string> $middleware
     */
    private function runPipeline(Request $request, array $middleware, Closure $destination): Response
    {
        $next = $destination;

        foreach (array_reverse($middleware) as $alias) {
            $current = $next;
            $next    = function (Request $r) use ($alias, $current): Response {
                [$name, $param] = array_pad(explode(':', $alias, 2), 2, null);

                $class = $this->middlewareAliases[$name] ?? null;
                if ($class === null || !class_exists($class)) {
                    throw new \RuntimeException('Middleware não registrado: ' . $name);
                }

                /** @var \App\Middleware\MiddlewareInterface $instance */
                $instance = new $class();

                return $instance->handle($r, $current, $param);
            };
        }

        return $next($request);
    }

    private function callAction(mixed $action, Request $request): Response
    {
        if ($action instanceof Closure) {
            $result = $action($request);
        } elseif (\is_array($action) && \count($action) === 2) {
            [$class, $method] = $action;

            if (!class_exists($class)) {
                throw new \RuntimeException('Controller inexistente: ' . (string) $class);
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException(sprintf('Ação %s::%s inexistente.', $class, (string) $method));
            }

            $result = $controller->{$method}($request);
        } else {
            throw new \RuntimeException('Definicao de rota inválida.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (\is_string($result)) {
            return Response::html($result);
        }

        if (\is_array($result)) {
            return Response::json($result);
        }

        return Response::noContent();
    }

    /** @return array<string,int> Quantidade de rotas por metodo (diagnostico). */
    public function summary(): array
    {
        $out = [];
        foreach ($this->routes as $method => $routes) {
            $out[$method] = \count($routes);
        }

        return $out;
    }
}
