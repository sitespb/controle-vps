<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Excecao que carrega um status HTTP. O handler central converte em pagina
 * de erro (navegador) ou JSON (API).
 */
class HttpException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        private int $statusCode,
        string $message = '',
        private array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($statusCode), $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message);
    }

    /** @param array<string,mixed> $errors */
    public static function validation(array $errors, string $message = ''): self
    {
        return new self(422, $message !== '' ? $message : 'Os dados enviados são inválidos.', $errors);
    }

    public static function tooManyRequests(string $message = ''): self
    {
        return new self(429, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => 'Requisição inválida.',
            401     => 'Autenticação necessária.',
            403     => 'Você não tem permissão para acessar este recurso.',
            404     => 'Página não encontrada.',
            405     => 'Metodo não permitido.',
            419     => 'Sessão expirada. Recarregue a página e tente novamente.',
            422     => 'Os dados enviados são inválidos.',
            429     => 'Muitas requisições. Aguarde alguns instantes.',
            503     => 'Serviço temporariamente indisponível.',
            default => 'Ocorreu um erro inesperado.',
        };
    }
}
