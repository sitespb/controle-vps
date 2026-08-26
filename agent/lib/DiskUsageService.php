<?php

declare(strict_types=1);

namespace Agent;

/**
 * Mede o espaco em disco ocupado pelo document_root de cada site.
 *
 * `du -sb` em arvores grandes (WordPress com milhares de arquivos) e caro em
 * I/O. Medir a cada ciclo de 5 minutos seria desperdicio de leitura de disco.
 * Por isso medimos UMA VEZ POR HORA e reaproveitamos o valor em cache nos
 * ciclos intermediarios.
 *
 * O cache e um arquivo JSON simples em agent/cache/disk-usage.json, sem banco
 * e sem dependencia. Se o agente nao puder escrever la, degrada com seguranca:
 * passa a medir a cada ciclo (sem cache) em vez de falhar a coleta.
 */
final class DiskUsageService
{
    private const CACHE_FILE = __DIR__ . '/../cache/disk-usage.json';

    /** Intervalo minimo entre medicoes, em segundos (1 hora). */
    private const INTERVAL_SECONDS = 3600;

    /** Entradas mais velhas que isto sao descartadas do cache. */
    private const MAX_AGE_SECONDS = 7 * 86400;

    /** @var array<string,array{bytes:int,at:int}> */
    private array $cache = [];

    private bool $cacheLoaded = false;

    public function __construct(private Logger $logger)
    {
    }

    /**
     * Acrescenta `disk_usage` (bytes) em cada site.
     *
     * @param  array<int,array<string,mixed>> $sites
     * @return array<int,array<string,mixed>>
     */
    public function enrich(array $sites): array
    {
        $cache = $this->loadCache();
        $now   = time();

        foreach ($sites as $index => $site) {
            $domain = (string) ($site['domain'] ?? '');

            $sites[$index]['disk_usage'] = $this->measure(
                $domain,
                $site['document_root'] ?? null,
                $cache,
                $now
            );
        }

        $this->saveCache($cache);

        return $sites;
    }

    /**
     * @param  array<string,array{bytes:int,at:int}> $cache
     */
    private function measure(string $domain, mixed $root, array &$cache, int $now): ?int
    {
        if (!\is_string($root) || $root === '' || !is_dir($root)) {
            // Sem document_root nao ha o que medir; preserva o ultimo valor.
            return $cache[$domain]['bytes'] ?? null;
        }

        $cached = $cache[$domain] ?? null;

        if ($cached !== null && ($now - $cached['at']) < self::INTERVAL_SECONDS) {
            return $cached['bytes'];
        }

        $bytes = $this->duBytes($root);

        if ($bytes === null) {
            // Falha na medicao (permissao, du ausente): reaproveita o ultimo
            // valor conhecido em vez de descartar a melhor estimativa.
            return $cached['bytes'] ?? null;
        }

        $cache[$domain] = ['bytes' => $bytes, 'at' => $now];

        return $bytes;
    }

    /** Soma em bytes do document_root via `du -sb`. */
    private function duBytes(string $root): ?int
    {
        if (!Shell::isAvailable('du')) {
            return null;
        }

        // Timeout alto de proposito: arvore de WordPress pode demorar.
        $output = Shell::run('du', ['-sb', $root], 120);

        if ($output === null) {
            return null;
        }

        // Saida do `du -sb`: "<bytes>\t<path>". Interessa so o numero.
        $parts = preg_split('/\s+/', trim($output)) ?: [];

        if (!isset($parts[0]) || !ctype_digit($parts[0])) {
            return null;
        }

        return (int) $parts[0];
    }

    /** @return array<string,array{bytes:int,at:int}> */
    private function loadCache(): array
    {
        if ($this->cacheLoaded) {
            return $this->cache;
        }

        $this->cacheLoaded = true;
        $content = Shell::readFile(self::CACHE_FILE, 1048576);

        if ($content === null) {
            return $this->cache = [];
        }

        $decoded = json_decode($content, true);

        if (!\is_array($decoded) || !\is_array($decoded['domains'] ?? null)) {
            return $this->cache = [];
        }

        $now = time();

        foreach ($decoded['domains'] as $domain => $entry) {
            if (
                !\is_string($domain)
                || !\is_array($entry)
                || !is_numeric($entry['bytes'] ?? null)
                || !is_numeric($entry['at'] ?? null)
            ) {
                continue;
            }

            $bytes = (int) $entry['bytes'];
            $at    = (int) $entry['at'];

            if ($bytes < 0 || $at <= 0 || ($now - $at) > self::MAX_AGE_SECONDS) {
                continue;
            }

            $this->cache[$domain] = ['bytes' => $bytes, 'at' => $at];
        }

        return $this->cache;
    }

    /**
     * @param array<string,array{bytes:int,at:int}> $cache
     */
    private function saveCache(array $cache): void
    {
        // Descarta entradas velhas para o arquivo nao crescer sem limite.
        // Dominios que somem por um ciclo preservam o valor, evitando uma nova
        // varredura cara quando reaparecerem.
        $now = time();

        foreach ($cache as $domain => $entry) {
            if (($now - $entry['at']) > self::MAX_AGE_SECONDS) {
                unset($cache[$domain]);
            }
        }

        if ($cache === []) {
            return;
        }

        $dir = \dirname(self::CACHE_FILE);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            $this->logger->warning('Cache de espaco em disco indisponivel; medindo a cada ciclo.');
            return;
        }

        @file_put_contents(
            self::CACHE_FILE,
            (string) json_encode(['domains' => $cache], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
