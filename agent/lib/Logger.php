<?php

declare(strict_types=1);

namespace Agent;

/**
 * Log local do agente.
 *
 * Grava em logs/agent-YYYY-MM-DD.log e, com --verbose, tambem na saida
 * padrao. Faz rotacao simples por data e limpa arquivos antigos.
 *
 * Secao 32 do PLAN: quando o painel esta fora do ar, o erro precisa ficar
 * registrado LOCALMENTE para diagnostico posterior - e o agente segue vivo
 * para tentar de novo no proximo ciclo.
 *
 * Nunca grava o token: apenas os primeiros caracteres, quando necessario.
 */
final class Logger
{
    private string $directory;

    private bool $verbose;

    private int $keepDays;

    public function __construct(string $directory, bool $verbose = false, int $keepDays = 14)
    {
        $this->directory = rtrim($directory, '/');
        $this->verbose   = $verbose;
        $this->keepDays  = $keepDays;

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->verbose) {
            $this->write('DEBUG', $message, $context);
        }
    }

    /** Saida direta para o operador (sempre visivel com --verbose). */
    public function line(string $message): void
    {
        if ($this->verbose) {
            fwrite(\STDOUT, $message . \PHP_EOL);
        }
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            '[%s] %-5s %s%s',
            date('Y-m-d H:i:s'),
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $context === [] ? '' : ' ' . $this->encode($context)
        );

        if ($this->verbose) {
            fwrite($level === 'ERROR' ? \STDERR : \STDOUT, $line . \PHP_EOL);
        }

        $file = $this->directory . '/agent-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line . \PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function encode(array $context): string
    {
        // Mascara qualquer chave que pareca segredo.
        foreach ($context as $key => $value) {
            if (preg_match('/token|secret|signature|password/i', (string) $key) === 1 && \is_string($value)) {
                $context[$key] = substr($value, 0, 8) . '...';
            }
        }

        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{}' : $json;
    }

    /** Remove logs antigos para nao encher o disco do VPS monitorado. */
    public function prune(): int
    {
        if ($this->keepDays <= 0 || !is_dir($this->directory)) {
            return 0;
        }

        $cutoff  = time() - ($this->keepDays * 86400);
        $removed = 0;

        foreach (glob($this->directory . '/agent-*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
