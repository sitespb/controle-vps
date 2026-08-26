<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resposta HTTP. Nada e enviado ao navegador antes de send().
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(
        private string $content = '',
        private int $status = 200,
        array $headers = []
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public static function make(string $content = '', int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return new self(
            $json === false ? '{"ok":false,"error":"encoding_error"}' : $json,
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers
        );
    }

    /** Resposta de sucesso padrao da API. */
    public static function apiOk(mixed $data = null, int $status = 200): self
    {
        $payload = ['ok' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return self::json($payload, $status);
    }

    /** Resposta de erro padrao da API. */
    public static function apiError(string $message, int $status = 400, string $code = '', array $details = []): self
    {
        $payload = [
            'ok'    => false,
            'error' => [
                'code'    => $code !== '' ? $code : self::codeForStatus($status),
                'message' => $message,
            ],
        ];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return self::json($payload, $status);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header(self::normalizeHeaderName($name) . ': ' . $value, true);
            }
        }

        echo $this->content;
    }

    private static function normalizeHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', $name)
        ));
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400     => 'bad_request',
            401     => 'unauthorized',
            403     => 'forbidden',
            404     => 'not_found',
            405     => 'method_not_allowed',
            409     => 'conflict',
            422     => 'validation_failed',
            429     => 'too_many_requests',
            500     => 'server_error',
            503     => 'service_unavailable',
            default => 'error',
        };
    }
}
