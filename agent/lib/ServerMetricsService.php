<?php

declare(strict_types=1);

namespace Agent;

/**
 * Coleta de identificacao e recursos do servidor (secao 6 do PLAN).
 *
 * Estrategia: ler /proc e /sys sempre que possivel. E mais rapido, mais
 * confiavel e nao depende de shell_exec - que pode estar bloqueado por
 * disable_functions. Comandos externos sao apenas complemento.
 *
 * Cada coletor devolve null quando nao consegue determinar o valor. O painel
 * trata null como "sem dado", nunca como zero - a diferenca importa: 0% de
 * CPU e um fato, ausencia de leitura nao e.
 */
final class ServerMetricsService
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * Identificacao do sistema - enviada no heartbeat.
     *
     * @return array<string,mixed>
     */
    public function systemInfo(): array
    {
        $release = $this->osRelease();

        return [
            'hostname'   => $this->hostname(),
            'public_ip'  => $this->publicIp(),
            'os_name'    => $release['name'],
            'os_version' => $release['version'],
            'arch'       => php_uname('m'),
            'kernel'     => php_uname('r'),
            'cpu_cores'  => $this->cpuCores(),
            'cpu_model'  => $this->cpuModel(),
            'uptime'     => $this->uptime(),
        ];
    }

    /**
     * Amostra de recursos - enviada no endpoint de metricas.
     *
     * @return array<string,mixed>
     */
    public function metrics(): array
    {
        $memory = $this->memory();

        return [
            'cpu' => [
                'usage' => $this->cpuUsage(),
                'cores' => $this->cpuCores(),
            ],
            'memory'    => $memory['ram'],
            'swap'      => $memory['swap'],
            'disk'      => $this->disk(),
            'load'      => $this->loadAverage(),
            'uptime'    => $this->uptime(),
            'processes' => $this->processCount(),
        ];
    }

    // -----------------------------------------------------------------
    // Identificacao
    // -----------------------------------------------------------------

    private function hostname(): ?string
    {
        $hostname = Shell::firstLine('hostname', ['-f'], 3);

        if ($hostname === null || $hostname === '') {
            $hostname = gethostname();
        }

        return \is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    /** @return array{name:?string,version:?string} */
    private function osRelease(): array
    {
        $content = Shell::readFile('/etc/os-release');

        if ($content === null) {
            return ['name' => \PHP_OS_FAMILY, 'version' => null];
        }

        $values = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value]      = explode('=', $line, 2);
            $values[trim($key)] = trim(trim($value), '"\'');
        }

        return [
            'name'    => $values['NAME'] ?? null,
            'version' => $values['VERSION'] ?? $values['VERSION_ID'] ?? null,
        ];
    }

    /**
     * IP publico do servidor.
     *
     * DELIBERADAMENTE sem chamada a servico externo: o agente nao deve
     * depender de terceiros nem gerar trafego de saida a cada 5 minutos.
     * Usa a rota padrao de saida, que na pratica e o IP publico em um VPS.
     */
    private function publicIp(): ?string
    {
        $route = Shell::firstLine('ip', ['route', 'get', '1.1.1.1'], 3);

        if ($route !== null && preg_match('/\bsrc\s+([0-9a-fA-F:.]+)/', $route, $m) === 1) {
            if (filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
                return $m[1];
            }
        }

        // Fallback: primeiro IPv4 publico das interfaces.
        $addresses = Shell::run('hostname', ['-I'], 3);

        if ($addresses !== null) {
            foreach (preg_split('/\s+/', trim($addresses)) ?: [] as $address) {
                $isPublic = filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                );

                if ($isPublic !== false) {
                    return (string) $address;
                }
            }
        }

        return null;
    }

    private function cpuCores(): ?int
    {
        $content = Shell::readFile('/proc/cpuinfo');

        if ($content !== null) {
            $count = substr_count($content, 'processor');

            if ($count > 0) {
                return $count;
            }
        }

        $nproc = Shell::firstLine('nproc', [], 3);

        return $nproc !== null && ctype_digit($nproc) ? (int) $nproc : null;
    }

    private function cpuModel(): ?string
    {
        $content = Shell::readFile('/proc/cpuinfo');

        if ($content === null) {
            return null;
        }

        if (preg_match('/^model name\s*:\s*(.+)$/mi', $content, $m) === 1) {
            return trim($m[1]);
        }

        // ARM costuma usar "Hardware" em vez de "model name".
        if (preg_match('/^Hardware\s*:\s*(.+)$/mi', $content, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function uptime(): ?int
    {
        $content = Shell::readFile('/proc/uptime');

        if ($content === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($content)) ?: [];

        return isset($parts[0]) && is_numeric($parts[0]) ? (int) (float) $parts[0] : null;
    }

    // -----------------------------------------------------------------
    // Recursos
    // -----------------------------------------------------------------

    /**
     * Uso de CPU em percentual.
     *
     * Le /proc/stat duas vezes com 500 ms de intervalo e calcula a fracao de
     * tempo NAO ocioso. Uma leitura unica so daria a media desde o boot, que
     * nao serve para monitoramento.
     */
    private function cpuUsage(): ?float
    {
        $first = $this->readCpuTimes();

        if ($first === null) {
            return null;
        }

        usleep(500000);

        $second = $this->readCpuTimes();

        if ($second === null) {
            return null;
        }

        $totalDelta = $second['total'] - $first['total'];
        $idleDelta  = $second['idle'] - $first['idle'];

        if ($totalDelta <= 0) {
            return null;
        }

        $usage = (1 - ($idleDelta / $totalDelta)) * 100;

        return round(max(0.0, min(100.0, $usage)), 2);
    }

    /** @return array{total:float,idle:float}|null */
    private function readCpuTimes(): ?array
    {
        $content = Shell::readFile('/proc/stat', 4096);

        if ($content === null || preg_match('/^cpu\s+(.+)$/m', $content, $m) !== 1) {
            return null;
        }

        $values = array_map('floatval', preg_split('/\s+/', trim($m[1])) ?: []);

        if (\count($values) < 5) {
            return null;
        }

        // Campos: user nice system idle iowait irq softirq steal ...
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);

        return ['total' => array_sum($values), 'idle' => $idle];
    }

    /**
     * Memoria e swap, em bytes.
     *
     * Usa MemAvailable (kernel >= 3.14), que ja desconta cache reutilizavel -
     * e o numero que reflete a memoria realmente disponivel, ao contrario de
     * MemFree.
     *
     * @return array{ram:array<string,?int|float>,swap:array<string,?int|float>}
     */
    private function memory(): array
    {
        $content = Shell::readFile('/proc/meminfo', 8192);

        $empty = [
            'ram'  => ['total' => null, 'used' => null, 'available' => null, 'percent' => null],
            'swap' => ['total' => null, 'used' => null, 'percent' => null],
        ];

        if ($content === null) {
            return $empty;
        }

        $values = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/i', $line, $m) === 1) {
                $values[$m[1]] = (int) $m[2] * 1024;
            }
        }

        $total     = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;

        if ($available === null && isset($values['MemFree'])) {
            $available = $values['MemFree']
                + ($values['Buffers'] ?? 0)
                + ($values['Cached'] ?? 0);
        }

        $used        = ($total !== null && $available !== null) ? max(0, $total - $available) : null;
        $ramPercent  = ($total !== null && $total > 0 && $used !== null)
            ? round(($used / $total) * 100, 2)
            : null;

        $swapTotal = $values['SwapTotal'] ?? null;
        $swapFree  = $values['SwapFree'] ?? null;
        $swapUsed  = ($swapTotal !== null && $swapFree !== null) ? max(0, $swapTotal - $swapFree) : null;
        $swapPct   = ($swapTotal !== null && $swapTotal > 0 && $swapUsed !== null)
            ? round(($swapUsed / $swapTotal) * 100, 2)
            : null;

        return [
            'ram' => [
                'total'     => $total,
                'used'      => $used,
                'available' => $available,
                'percent'   => $ramPercent,
            ],
            'swap' => [
                'total'   => $swapTotal,
                'used'    => $swapUsed,
                'percent' => $swapPct,
            ],
        ];
    }

    /**
     * Espaco do sistema de arquivos raiz, em bytes.
     *
     * @return array<string,?int|float>
     */
    private function disk(string $mountPoint = '/'): array
    {
        $total = @disk_total_space($mountPoint);
        $free  = @disk_free_space($mountPoint);

        if ($total === false || $free === false || $total <= 0) {
            return ['total' => null, 'used' => null, 'free' => null, 'percent' => null];
        }

        $used = $total - $free;

        return [
            'total'   => (int) $total,
            'used'    => (int) $used,
            'free'    => (int) $free,
            'percent' => round(($used / $total) * 100, 2),
        ];
    }

    /** @return array<string,?float> */
    private function loadAverage(): array
    {
        $load = \function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        if ($load === false) {
            $content = Shell::readFile('/proc/loadavg', 256);

            if ($content === null) {
                return ['1' => null, '5' => null, '15' => null];
            }

            $parts = preg_split('/\s+/', trim($content)) ?: [];
            $load  = [
                (float) ($parts[0] ?? 0),
                (float) ($parts[1] ?? 0),
                (float) ($parts[2] ?? 0),
            ];
        }

        return [
            '1'  => round((float) ($load[0] ?? 0), 2),
            '5'  => round((float) ($load[1] ?? 0), 2),
            '15' => round((float) ($load[2] ?? 0), 2),
        ];
    }

    private function processCount(): ?int
    {
        $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR);

        return \is_array($dirs) && $dirs !== [] ? \count($dirs) : null;
    }
}
