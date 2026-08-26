<?php

declare(strict_types=1);

namespace Agent;

use RuntimeException;

/**
 * Configuracao do agente.
 *
 * Lida do arquivo config.php ao lado do agent.php. O formato e um array PHP
 * simples - sem parser, sem dependencia, sem surpresa.
 *
 * O arquivo contem o token do servidor e por isso deve ficar com permissao
 * 600 (o install.sh cuida disso).
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException(
                "Arquivo de configuracao nao encontrado: {$file}\n" .
                "Copie config.example.php para config.php e preencha SERVER_ID, SERVER_TOKEN e CENTRAL_URL."
            );
        }

        if (!is_readable($file)) {
            throw new RuntimeException("Sem permissao de leitura em {$file}.");
        }

        /** @psalm-suppress UnresolvableInclude */
        $values = require $file;

        if (!\is_array($values)) {
            throw new RuntimeException("O arquivo {$file} deve retornar um array PHP.");
        }

        $config = new self($values);
        $config->validate();

        return $config;
    }

    private function validate(): void
    {
        $serverId = (int) ($this->values['SERVER_ID'] ?? 0);
        $token    = (string) ($this->values['SERVER_TOKEN'] ?? '');
        $url      = (string) ($this->values['CENTRAL_URL'] ?? '');

        if ($serverId <= 0) {
            throw new RuntimeException('SERVER_ID ausente ou invalido no config.php.');
        }

        if ($token === '' || str_contains($token, 'SEU_TOKEN')) {
            throw new RuntimeException('SERVER_TOKEN nao configurado no config.php.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('CENTRAL_URL ausente ou invalida no config.php.');
        }

        // Aviso, nao erro: durante a homologacao local o painel pode estar em
        // http. Em producao a secao 4 do PLAN exige HTTPS.
        if (!str_starts_with($url, 'https://') && !$this->bool('ALLOW_INSECURE', false)) {
            fwrite(
                \STDERR,
                "AVISO: CENTRAL_URL nao usa HTTPS. Em producao configure TLS ou defina ALLOW_INSECURE => true conscientemente.\n"
            );
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;

        return \is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;

        return \is_bool($value) ? $value : \in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    public function serverId(): int
    {
        return $this->int('SERVER_ID');
    }

    public function token(): string
    {
        return $this->string('SERVER_TOKEN');
    }

    public function centralUrl(): string
    {
        return rtrim($this->string('CENTRAL_URL'), '/');
    }
}
