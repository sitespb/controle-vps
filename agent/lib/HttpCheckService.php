<?php

declare(strict_types=1);

namespace Agent;

/**
 * Verificacao HTTP e SSL dos dominios (secoes 7, 16 e 17 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * POR QUE curl_multi
 * ---------------------------------------------------------------------------
 * Um servidor com 200 dominios e comum. Verificados em sequencia, com timeout
 * de 10 s, o pior caso passaria de meia hora - inviavel para um cron de 5
 * minutos. Com curl_multi e concorrencia controlada, o mesmo lote termina em
 * segundos e o VPS monitorado nem percebe.
 *
 * ---------------------------------------------------------------------------
 * SSL SEM SEGUNDA CONEXAO
 * ---------------------------------------------------------------------------
 * CURLOPT_CERTINFO devolve emissor, inicio e fim da validade do certificado
 * na MESMA requisicao que ja fizemos para o HTTP. Abrir um segundo socket TLS
 * por dominio so para ler a data de expiracao seria o dobro do trabalho pelo
 * mesmo dado.
 *
 * O certificado e lido MESMO quando ele esta expirado ou invalido: a
 * verificacao do peer fica desligada de proposito nesta sonda. O objetivo
 * aqui e justamente DIAGNOSTICAR o certificado ruim - abortar o handshake
 * esconderia a informacao que queremos reportar.
 */
final class HttpCheckService
{
    public function __construct(
        private Logger $logger,
        private int $concurrency = 10,
        private int $timeout = 10,
        private int $connectTimeout = 5,
        private string $userAgent = 'ControleVPS-Agent/1.0 (+monitoramento)'
    ) {
        $this->concurrency = max(1, min(32, $concurrency));
    }

    /**
     * Verifica uma lista de dominios.
     *
     * @param  array<int,array<string,mixed>> $sites Cada item precisa de 'domain'
     * @return array<int,array<string,mixed>> Os mesmos itens, enriquecidos
     */
    public function checkAll(array $sites): array
    {
        if ($sites === []) {
            return [];
        }

        $results = [];
        $chunks  = array_chunk($sites, $this->concurrency);
        $total   = \count($sites);
        $done    = 0;

        foreach ($chunks as $chunk) {
            foreach ($this->checkBatch($chunk) as $result) {
                $results[] = $result;
            }

            $done += \count($chunk);
            $this->logger->debug("Verificacao HTTP: {$done}/{$total} dominio(s).");
        }

        return $results;
    }

    /**
     * @param  array<int,array<string,mixed>> $sites
     * @return array<int,array<string,mixed>>
     */
    private function checkBatch(array $sites): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($sites as $index => $site) {
            $domain = (string) $site['domain'];
            $url    = 'https://' . $domain . '/';

            $ch = $this->createHandle($url);

            if ($ch === null) {
                continue;
            }

            $handles[$index] = ['handle' => $ch, 'site' => $site, 'scheme' => 'https'];
            curl_multi_add_handle($multi, $ch);
        }

        $this->execute($multi);

        $results  = [];
        $retryTry = [];

        foreach ($handles as $index => $entry) {
            $result = $this->readHandle($entry['handle'], $entry['site'], true);

            curl_multi_remove_handle($multi, $entry['handle']);
            curl_close($entry['handle']);

            // Sem resposta em HTTPS: tenta HTTP simples. Um site legitimamente
            // sem TLS nao pode ser reportado como offline.
            if ($result['http_status'] === null) {
                $retryTry[$index] = $entry['site'];
                continue;
            }

            $results[$index] = $result;
        }

        if ($retryTry !== []) {
            $plainHandles = [];

            foreach ($retryTry as $index => $site) {
                $ch = $this->createHandle('http://' . $site['domain'] . '/');

                if ($ch === null) {
                    continue;
                }

                $plainHandles[$index] = ['handle' => $ch, 'site' => $site];
                curl_multi_add_handle($multi, $ch);
            }

            $this->execute($multi);

            foreach ($plainHandles as $index => $entry) {
                $results[$index] = $this->readHandle($entry['handle'], $entry['site'], false);

                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
        }

        curl_multi_close($multi);

        ksort($results);

        return array_values($results);
    }

    /** @return \CurlHandle|null */
    private function createHandle(string $url): ?object
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => 'gzip',
            // Ver o comentario no topo: precisamos LER o certificado ruim,
            // nao recusar a conexao por causa dele.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CERTINFO       => true,
            // Baixa apenas o suficiente para inspecionar o <head>.
            CURLOPT_RANGE          => '0-65535',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Cache-Control: no-cache'],
        ]);

        return $ch;
    }

    /** Roda o multi handler ate todas as transferencias terminarem. */
    private function execute(\CurlMultiHandle $multi): void
    {
        $running = null;

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                // Bloqueia ate haver atividade: evita busy-wait consumindo CPU
                // do servidor monitorado.
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === \CURLM_OK);
    }

    /**
     * @param  array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function readHandle(object $ch, array $site, bool $https): array
    {
        $body     = curl_multi_getcontent($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $totalMs  = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $primary  = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error    = curl_error($ch);

        $result = $site;

        $result['http_status']     = $status > 0 ? $status : null;
        $result['response_time']   = $status > 0 ? $totalMs : null;
        $result['https_available'] = $https && $status > 0;
        $result['ip']              = filter_var($primary, FILTER_VALIDATE_IP) !== false ? $primary : null;
        $result['url']             = $finalUrl !== '' ? $finalUrl : (($https ? 'https://' : 'http://') . $site['domain']);
        $result['error']           = $status > 0 ? null : ($error !== '' ? $error : 'Sem resposta do dominio');

        // Certificado, quando a conexao TLS aconteceu.
        if ($https) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            $ssl      = $this->parseCertificate(\is_array($certInfo) ? $certInfo : []);

            if ($ssl !== null) {
                $result['ssl'] = $ssl;
            } elseif ($status === 0) {
                $result['ssl'] = [
                    'error' => $error !== '' ? mb_substr($error, 0, 255) : 'Handshake TLS nao concluido',
                ];
            }
        }

        // WordPress pelo HTML, apenas como complemento: a deteccao principal
        // e no disco (SiteDiscoveryService::detectWordPress).
        if (empty($result['wordpress_detected']) && \is_string($body) && $body !== '') {
            $wp = $this->detectWordPressInHtml($body);

            if ($wp['detected']) {
                $result['wordpress_detected'] = true;
                $result['wordpress_version'] ??= $wp['version'];
            }
        }

        return $result;
    }

    /**
     * Extrai emissor e validade do certificado folha.
     *
     * @param  array<int,array<string,string>> $certInfo
     * @return array<string,mixed>|null
     */
    private function parseCertificate(array $certInfo): ?array
    {
        if ($certInfo === []) {
            return null;
        }

        // O primeiro da cadeia e o certificado do proprio dominio.
        $leaf = $certInfo[0];

        $validFrom  = $this->parseCertDate($leaf['Start date'] ?? $leaf['Start Date'] ?? null);
        $validUntil = $this->parseCertDate($leaf['Expire date'] ?? $leaf['Expire Date'] ?? null);

        if ($validUntil === null) {
            return null;
        }

        return [
            'issuer'      => $this->extractDnField($leaf['Issuer'] ?? '', ['O', 'CN']),
            'subject'     => $this->extractDnField($leaf['Subject'] ?? '', ['CN', 'O']),
            'valid_from'  => $validFrom,
            'valid_until' => $validUntil,
        ];
    }

    /**
     * O cURL entrega datas como "Sep 18 12:00:00 2026 GMT". strtotime lida
     * bem com esse formato; devolvemos ISO para o painel.
     */
    private function parseCertDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * Le um campo do Distinguished Name.
     * Formato tipico: "C = US, O = Let's Encrypt, CN = R11"
     *
     * @param array<int,string> $preferredKeys
     */
    private function extractDnField(string $dn, array $preferredKeys): ?string
    {
        if (trim($dn) === '') {
            return null;
        }

        $fields = [];

        foreach (preg_split('/,\s*(?=[A-Za-z]+\s*=)/', $dn) ?: [] as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value]      = explode('=', $part, 2);
            $fields[trim($key)] = trim($value);
        }

        foreach ($preferredKeys as $key) {
            if (!empty($fields[$key])) {
                return mb_substr($fields[$key], 0, 190);
            }
        }

        return null;
    }

    /** @return array{detected:bool,version:?string} */
    private function detectWordPressInHtml(string $html): array
    {
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress\s*([0-9.]*)/i', $html, $m) === 1) {
            return ['detected' => true, 'version' => ($m[1] ?? '') !== '' ? $m[1] : null];
        }

        if (
            str_contains($html, '/wp-content/')
            || str_contains($html, '/wp-includes/')
        ) {
            return ['detected' => true, 'version' => null];
        }

        return ['detected' => false, 'version' => null];
    }
}
