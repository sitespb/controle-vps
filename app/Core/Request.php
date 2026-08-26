<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Encapsula a requisicao HTTP.
 *
 * Nenhum controller le $_GET/$_POST/$_SERVER diretamente - tudo passa por
 * aqui, o que centraliza a normalizacao e facilita os testes.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $body;

    /** @var array<string,string> */
    private array $headers;

    /** @var array<string,string> Parametros extraidos da rota ({id} etc). */
    private array $routeParams = [];

    /** @var array<string,mixed> Dados anexados pelos middlewares (ex.: servidor autenticado). */
    private array $attributes = [];

    private string $method;

    private string $path;

    private string $rawBody;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        string $rawBody = ''
    ) {
        $this->method  = strtoupper($method);
        $this->path    = $path;
        $this->query   = $query;
        $this->body    = $body;
        $this->headers = $headers;
        $this->rawBody = $rawBody;
    }

    public static function capture(): self
    {
        $method  = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawBody = (string) file_get_contents('php://input');
        $headers = self::readHeaders();

        $body = $_POST;

        // Corpo JSON (usado pelos agentes e pelas chamadas fetch do painel).
        $contentType = strtolower($headers['content-type'] ?? '');
        if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (\is_array($decoded)) {
                $body = $decoded;
            }
        }

        // Suporte a _method para formularios HTML (DELETE/PUT).
        if ($method === 'POST' && isset($body['_method'])) {
            $override = strtoupper((string) $body['_method']);
            if (\in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self($method, self::detectPath(), $_GET, $body, $headers, $rawBody);
    }

    /**
     * Caminho da rota, ja sem o prefixo de instalacao (APP_URL) e sem
     * querystring. Sempre comeca com "/" e nunca termina com "/" (exceto raiz).
     */
    private static function detectPath(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = \is_string($path) ? $path : '/';
        $path = rawurldecode($path);

        // Remove o prefixo de instalacao (ex.: /controle-vps/public).
        $base = base_path_url();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, \strlen($base));
        }

        // Quando o vhost aponta para a raiz do projeto o rewrite injeta
        // "/public" no caminho; ele nao faz parte da rota.
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        } elseif ($path === '/public') {
            $path = '/';
        }

        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /** @return array<string,string> */
    private static function readHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server])) {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function wantsJson(): bool
    {
        if (str_starts_with($this->path, '/api/')) {
            return true;
        }

        $accept = strtolower($this->header('accept', '') ?? '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return strtolower($this->header('x-requested-with', '') ?? '') === 'xmlhttprequest';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return \is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);

        if ($value === null) {
            return $default;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body + $this->query;
    }

    /**
     * @param  array<int,string> $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->input($key);
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->body) || \array_key_exists($key, $this->query);
    }

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeInt(string $key): int
    {
        return (int) ($this->routeParams[$key] ?? 0);
    }

    /** @return array<string,string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Anexa um dado resolvido por middleware (ex.: o servidor autenticado
     * pelo AgentAuthMiddleware). Evita variaveis globais entre camadas.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * IP do cliente. Confia em X-Forwarded-For apenas quando a aplicacao
     * declara estar atras de proxy, evitando spoofing do rate limit.
     */
    public function ip(): string
    {
        if (Config::get('app.trust_proxy', false) === true) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent', '') ?? '', 0, 250);
    }

    public function fullPath(): string
    {
        $qs = $this->query === [] ? '' : '?' . http_build_query($this->query);

        return $this->path . $qs;
    }
}
