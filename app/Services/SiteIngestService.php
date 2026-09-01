<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Site;
use App\Models\SslCertificate;

/**
 * Recepcao dos sites descobertos pelo agente (secoes 7, 8, 15, 16 e 29).
 *
 * O operador nao cadastra dominio nenhum: o agente descobre no CyberPanel /
 * OpenLiteSpeed e envia a lista completa a cada ciclo. Aqui fazemos o upsert,
 * gravamos o historico da verificacao, atualizamos o certificado e avaliamos
 * os alertas.
 *
 * TRATAMENTO DE ERRO (secao 32): cada dominio e processado dentro do seu
 * proprio try/catch. Um site com dados corrompidos nao pode impedir que os
 * outros 183 sejam gravados.
 */
final class SiteIngestService
{
    /**
     * @param  array<int,array<string,mixed>> $sites
     * @param  bool                           $finalize         true apenas no ultimo lote da coleta
     * @param  array<int,string>              $completeDomains  lista completa de dominios do ciclo
     * @return array{received:int,created:int,updated:int,skipped:int,offline:int,undiscovered:int,alerts_resolved:int,errors:array<int,string>}
     */
    public static function store(int $serverId, array $sites, bool $finalize = true, array $completeDomains = []): array
    {
        $maxSites = (int) Config::get('monitoring.agent_api.max_sites', 500);
        $sites    = \array_slice($sites, 0, $maxSites);

        $result = [
            'received'         => \count($sites),
            'created'          => 0,
            'updated'          => 0,
            'skipped'          => 0,
            'offline'          => 0,
            'undiscovered'     => 0,
            'alerts_resolved'  => 0,
            'errors'           => [],
        ];

        $seenDomains = [];

        foreach ($sites as $raw) {
            if (!\is_array($raw)) {
                $result['skipped']++;
                continue;
            }

            $domain = self::normalizeDomain($raw['domain'] ?? '');

            if ($domain === null) {
                $result['skipped']++;
                continue;
            }

            try {
                $outcome = self::storeOne($serverId, $domain, $raw);

                $seenDomains[] = $domain;
                $result[$outcome['action']]++;

                if ($outcome['status'] === Site::STATUS_OFFLINE) {
                    $result['offline']++;
                }
            } catch (\Throwable $e) {
                // Um dominio problematico nao derruba a coleta inteira.
                $result['skipped']++;
                $result['errors'][] = $domain . ': ' . $e->getMessage();

                Logger::error('Falha ao gravar site na coleta: ' . $e->getMessage(), [
                    'server_id' => $serverId,
                    'domain'    => $domain,
                ]);
            }
        }

        // Dominios que sumiram deixam de contar, mas nao sao apagados.
        // So finalizamos no ultimo lote (finalize), usando a lista completa:
        // finalizar a cada lote invalidaria os dominios dos lotes seguintes.
        if ($finalize) {
            $keepDomains = $completeDomains !== [] ? $completeDomains : $seenDomains;

            $normalized = [];
            foreach ($keepDomains as $domain) {
                $normalizedDomain = self::normalizeDomain($domain);

                if ($normalizedDomain !== null) {
                    $normalized[] = $normalizedDomain;
                }
            }

            if ($normalized !== []) {
                $missing = Site::markMissingAsUndiscovered($serverId, $normalized);

                $result['undiscovered']    = \count($missing);
                $result['alerts_resolved'] = self::closeAlertsOfRemovedSites($serverId, $missing);
            }
        }

        return $result;
    }

    /**
     * Encerra os alertas dos dominios que sairam do servidor.
     *
     * Marcar `discovered = 0` tira o site das telas, mas nao toca nos alertas
     * ja abertos - e nenhuma consulta de alerta filtra por `discovered`. Sem
     * este passo, um dominio excluido do CyberPanel continua eternamente na
     * tela de alertas com "SSL expirado".
     *
     * Falhar aqui NAO pode derrubar a coleta: o upsert dos sites ja terminou
     * e e o dado que realmente importa. Por isso o try/catch por site, no
     * mesmo espirito do resto da classe.
     *
     * @param  array<int,array{id:int,domain:string}> $missing
     * @return int quantidade de alertas encerrados
     */
    private static function closeAlertsOfRemovedSites(int $serverId, array $missing): int
    {
        $closed = 0;

        foreach ($missing as $site) {
            try {
                $closed += AlertService::resolveForUndiscoveredSite($serverId, $site['id'], $site['domain']);
            } catch (\Throwable $e) {
                Logger::error('Falha ao encerrar alertas de site removido: ' . $e->getMessage(), [
                    'server_id' => $serverId,
                    'site_id'   => $site['id'],
                    'domain'    => $site['domain'],
                ]);
            }
        }

        if ($closed > 0) {
            Logger::info(sprintf(
                '%d alerta(s) encerrado(s): %d domínio(s) não estao mais no servidor.',
                $closed,
                \count($missing)
            ), ['server_id' => $serverId]);
        }

        return $closed;
    }

    /**
     * @param  array<string,mixed> $raw
     * @return array{action:'created'|'updated',status:string,site_id:int}
     */
    private static function storeOne(int $serverId, string $domain, array $raw): array
    {
        $httpStatus   = self::intOrNull($raw['http_status'] ?? null, 0, 599);
        $responseTime = self::intOrNull($raw['response_time'] ?? null, 0, 600000);
        $error        = self::textOrNull($raw['error'] ?? null, 255);

        $status = HttpStatusService::classify($httpStatus, $responseTime, $error);

        $existing       = Site::findByServerAndDomain($serverId, $domain);
        $previousStatus = $existing['status'] ?? null;
        $now            = now_string();

        $data = [
            'server_id'          => $serverId,
            'domain'             => $domain,
            'url'                => self::normalizeUrl($raw['url'] ?? null, $domain, (bool) ($raw['https_available'] ?? false)),
            'status'             => $status,
            'http_status'        => $httpStatus,
            'response_time'      => $responseTime,
            'https_available'    => !empty($raw['https_available']) ? 1 : 0,
            'ip'                 => self::ipOrNull($raw['ip'] ?? null),
            'php_version'        => self::textOrNull($raw['php_version'] ?? null, 20),
            'wordpress_detected' => !empty($raw['wordpress_detected']) ? 1 : 0,
            'wordpress_version'  => self::textOrNull($raw['wordpress_version'] ?? null, 20),
            'document_root'      => self::textOrNull($raw['document_root'] ?? null, 255),
            'disk_usage'         => self::intOrNull($raw['disk_usage'] ?? null, 0, \PHP_INT_MAX),
            'last_error'         => $error,
            'last_check_at'      => $now,
            'discovered'         => 1,
            'updated_at'         => $now,
        ];

        if ($status === Site::STATUS_ONLINE) {
            $data['last_online_at'] = $now;
        }

        if ($existing === null) {
            $data['created_at'] = $now;
            $siteId             = Database::insert('sites', $data);
            $action             = 'created';
        } else {
            $siteId = (int) $existing['id'];
            Database::update('sites', $data, ['id' => $siteId]);
            $action = 'updated';
        }

        // Historico da verificacao.
        $statusChanged = $previousStatus !== null && $previousStatus !== $status;

        Database::insert('site_checks', [
            'site_id'        => $siteId,
            'status'         => $status,
            'http_status'    => $httpStatus,
            'response_time'  => $responseTime,
            'error'          => $error,
            'status_changed' => $statusChanged ? 1 : 0,
            'created_at'     => $now,
        ]);

        // Certificado.
        $daysRemaining = self::storeSsl($siteId, $raw);

        // Alertas de disponibilidade.
        try {
            if ($status === Site::STATUS_OFFLINE) {
                AlertService::siteWentOffline($siteId, $serverId, $domain, $httpStatus, $error);
            } else {
                AlertService::siteCameBack($siteId, $serverId, $domain);
            }

            AlertService::evaluateSsl($siteId, $serverId, $domain, $daysRemaining);
        } catch (\Throwable $e) {
            Logger::error('Falha ao avaliar alertas do site: ' . $e->getMessage(), [
                'site_id' => $siteId,
                'domain'  => $domain,
            ]);
        }

        return ['action' => $action, 'status' => $status, 'site_id' => $siteId];
    }

    /**
     * Grava o certificado quando o agente enviou o bloco `ssl`.
     *
     * @param  array<string,mixed> $raw
     * @return int|null Dias restantes, para a avaliacao de alerta
     */
    private static function storeSsl(int $siteId, array $raw): ?int
    {
        $ssl = $raw['ssl'] ?? null;

        if (!\is_array($ssl) || $ssl === []) {
            // Sem bloco SSL: preserva o que ja existia em vez de apagar.
            $existing = SslCertificate::forSite($siteId);

            return $existing === null || $existing['days_remaining'] === null
                ? null
                : (int) $existing['days_remaining'];
        }

        $normalized = SslService::normalize($ssl);

        SslCertificate::upsert($siteId, $normalized);

        return $normalized['days_remaining'];
    }

    // -----------------------------------------------------------------
    // Normalizacao
    // -----------------------------------------------------------------

    /** Aceita "example.com", "www.example.com", "https://example.com/". */
    public static function normalizeDomain(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $domain = mb_strtolower(trim($value));

        if ($domain === '') {
            return null;
        }

        // Remove esquema, caminho, porta e usuario.
        if (str_contains($domain, '://')) {
            $host   = parse_url($domain, PHP_URL_HOST);
            $domain = \is_string($host) ? $host : $domain;
        }

        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = rtrim($domain, '.');

        if ($domain === '' || mb_strlen($domain) > 190) {
            return null;
        }

        // Aceita dominio, subdominio e IDN ja convertido em punycode.
        if (preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9_-]{1,63}(?<!-)(\.(?!-)[a-z0-9_-]{1,63}(?<!-))+$/', $domain) !== 1) {
            return null;
        }

        return $domain;
    }

    private static function normalizeUrl(mixed $url, string $domain, bool $https): ?string
    {
        if (\is_string($url) && $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return mb_substr($url, 0, 255);
        }

        return ($https ? 'https://' : 'http://') . $domain;
    }

    private static function intOrNull(mixed $value, int $min, int $max): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return ($int < $min || $int > $max) ? null : $int;
    }

    private static function textOrNull(mixed $value, int $max): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    private static function ipOrNull(mixed $value): ?string
    {
        if (!\is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $value;
    }
}
