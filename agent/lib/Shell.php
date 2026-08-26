<?php

declare(strict_types=1);

namespace Agent;

/**
 * Execucao controlada de comandos do sistema.
 *
 * ---------------------------------------------------------------------------
 * REGRA DE SEGURANCA (secao 5 e 33 do PLAN)
 * ---------------------------------------------------------------------------
 * Todo comando executado pelo agente e um LITERAL escrito neste repositorio.
 * Nada que venha da rede - resposta da API, conteudo de site, nome de dominio
 * descoberto - chega aqui como comando. Argumentos dinamicos, quando existem,
 * passam obrigatoriamente por escapeshellarg().
 *
 * O agente NAO possui, e nao deve ganhar, nenhum caminho de codigo que
 * execute string recebida do painel.
 */
final class Shell
{
    /** Comandos que o agente pode executar. Lista fixa, verificavel. */
    private const ALLOWED = [
        'hostname', 'uname', 'nproc', 'uptime', 'systemctl', 'service',
        'pgrep', 'pidof', 'ps', 'ip', 'df', 'free', 'lsb_release',
        'mysql', 'mysqld', 'mariadbd', 'redis-server', 'redis-cli',
        'php', 'openssl', 'cat', 'ss', 'netstat', 'du',
    ];

    /**
     * Executa um comando e devolve a saida, ou null em caso de falha.
     *
     * @param string            $binary Nome do executavel (precisa estar em ALLOWED)
     * @param array<int,string> $args   Argumentos - escapados individualmente
     */
    public static function run(string $binary, array $args = [], int $timeoutSeconds = 5): ?string
    {
        if (!\in_array($binary, self::ALLOWED, true)) {
            // Erro de programacao, nao de runtime: falha alto e cedo.
            throw new \InvalidArgumentException("Comando nao permitido no agente: {$binary}");
        }

        if (!self::isAvailable($binary)) {
            return null;
        }

        $command = escapeshellcmd($binary);

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg((string) $arg);
        }

        // timeout garante que um comando travado nao segure o cron.
        if (self::isAvailable('timeout')) {
            $command = 'timeout ' . max(1, $timeoutSeconds) . ' ' . $command;
        }

        $output = @shell_exec($command . ' 2>/dev/null');

        if ($output === null || $output === false) {
            return null;
        }

        $output = trim((string) $output);

        return $output === '' ? null : $output;
    }

    /** Primeira linha da saida - atalho comum na deteccao de versao. */
    public static function firstLine(string $binary, array $args = [], int $timeoutSeconds = 5): ?string
    {
        $output = self::run($binary, $args, $timeoutSeconds);

        if ($output === null) {
            return null;
        }

        $lines = preg_split('/\R/', $output) ?: [];

        return $lines === [] ? null : trim((string) $lines[0]);
    }

    /** O executavel existe no PATH? Resultado memoizado. */
    public static function isAvailable(string $binary): bool
    {
        static $cache = [];

        if (isset($cache[$binary])) {
            return $cache[$binary];
        }

        if (!self::canExecute()) {
            return $cache[$binary] = false;
        }

        $safe   = escapeshellarg($binary);
        $result = @shell_exec("command -v {$safe} 2>/dev/null");

        return $cache[$binary] = ($result !== null && trim((string) $result) !== '');
    }

    /**
     * shell_exec pode estar desabilitado por disable_functions - situacao
     * comum em hospedagem compartilhada. O agente continua funcionando com o
     * que conseguir ler de /proc.
     */
    public static function canExecute(): bool
    {
        static $can = null;

        if ($can !== null) {
            return $can;
        }

        // O agente e feito para VPS Linux. Em outros sistemas os comandos
        // (`command -v`, `systemctl`, `2>/dev/null`) nao existem e so
        // produziriam ruido de erro - entao nem tentamos. As leituras de
        // /proc e as funcoes nativas do PHP continuam funcionando.
        if (\PHP_OS_FAMILY !== 'Linux') {
            return $can = false;
        }

        if (!\function_exists('shell_exec')) {
            return $can = false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return $can = !\in_array('shell_exec', $disabled, true);
    }

    /**
     * Um servico esta ativo? Tenta systemctl, depois pgrep. Retorna null
     * quando nao foi possivel determinar - e a diferenca entre "parado" e
     * "nao sei", que importa para nao gerar alerta falso.
     */
    public static function serviceIsActive(string $unit, array $processNames = []): ?bool
    {
        if (self::isAvailable('systemctl')) {
            $state = self::run('systemctl', ['is-active', $unit], 4);

            if ($state !== null) {
                $state = trim($state);

                if ($state === 'active') {
                    return true;
                }

                if (\in_array($state, ['inactive', 'failed', 'deactivating'], true)) {
                    return false;
                }
                // "unknown" ou unidade inexistente: cai para o pgrep abaixo.
            }
        }

        foreach ($processNames as $process) {
            if (self::isAvailable('pgrep') && self::run('pgrep', ['-x', $process], 4) !== null) {
                return true;
            }
        }

        return null;
    }

    /** Le um arquivo do sistema com tratamento de erro silencioso. */
    public static function readFile(string $path, int $maxBytes = 262144): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path, false, null, 0, $maxBytes);

        return $content === false ? null : $content;
    }
}
