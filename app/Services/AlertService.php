<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteCheck;

/**
 * Motor de alertas (secoes 18, 19, 28 e 29 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * DEDUPLICACAO E RESOLUCAO AUTOMATICA
 * ---------------------------------------------------------------------------
 * A ideia central e o `fingerprint`: sha1(tipo|server_id|site_id). Enquanto o
 * problema persistir existe UM unico alerta aberto com aquele fingerprint;
 * cada nova deteccao apenas incrementa `occurrences` e move `last_seen_at`.
 * E isso que cumpre "nao gerar dezenas de alertas iguais".
 *
 * Quando a condicao desaparece, resolve() localiza o mesmo fingerprint,
 * marca resolved e grava o evento - a resolucao e automatica, sem intervencao.
 */
final class AlertService
{
    /**
     * Alertas cujo alvo e um dominio - os unicos que fazem sentido encerrar
     * quando o site deixa de existir no servidor.
     *
     * Os tipos de servidor (CPU, RAM, disco, offline) ficam DE FORA de
     * proposito: o servidor continua existindo depois que um site e removido.
     *
     * @var array<int,string>
     */
    public const SITE_ALERT_TYPES = [
        Alert::TYPE_SITE_OFFLINE,
        Alert::TYPE_SSL_EXPIRING,
        Alert::TYPE_SSL_EXPIRED,
    ];

    /**
     * Abre (ou reforca) um alerta.
     *
     * @param  array{server_id?:?int,site_id?:?int,severity?:string,value?:?float} $options
     * @return int ID do alerta
     */
    public static function raise(string $type, string $title, string $message, array $options = []): int
    {
        $serverId = $options['server_id'] ?? null;
        $siteId   = $options['site_id'] ?? null;
        $severity = $options['severity'] ?? Alert::SEVERITY_WARNING;
        $value    = $options['value'] ?? null;

        $fingerprint = Alert::fingerprint($type, $serverId, $siteId);
        $existing    = Alert::findOpenByFingerprint($fingerprint);
        $now         = now_string();

        if ($existing !== null) {
            $id = (int) $existing['id'];

            // O problema continua: atualiza sem criar linha nova.
            Database::statement(
                'UPDATE alerts
                 SET occurrences = occurrences + 1,
                     last_seen_at = ?,
                     severity = ?,
                     title = ?,
                     message = ?,
                     metric_value = ?,
                     updated_at = ?
                 WHERE id = ?',
                [$now, $severity, $title, $message, $value, $now, $id]
            );

            // A cada 12 reincidencias registra um marco na linha do tempo,
            // para nao poluir alert_events a cada ciclo de 5 minutos.
            if (((int) $existing['occurrences'] + 1) % 12 === 0) {
                AlertEvent::record($id, AlertEvent::RECURRED, 'Problema persiste desde ' . format_datetime((string) $existing['first_seen_at']));
            }

            return $id;
        }

        $id = Database::insert('alerts', [
            'server_id'     => $serverId,
            'site_id'       => $siteId,
            'type'          => $type,
            'severity'      => $severity,
            'title'         => mb_substr($title, 0, 190),
            'message'       => $message,
            'metric_value'  => $value,
            'status'        => Alert::STATUS_OPEN,
            'fingerprint'   => $fingerprint,
            'occurrences'   => 1,
            'first_seen_at' => $now,
            'last_seen_at'  => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        AlertEvent::record($id, AlertEvent::CREATED, $message);

        Logger::warning('Alerta aberto: ' . $title, [
            'type'      => $type,
            'severity'  => $severity,
            'server_id' => $serverId,
            'site_id'   => $siteId,
        ]);

        return $id;
    }

    /**
     * Resolve automaticamente o alerta correspondente, se estiver aberto.
     *
     * @return bool true quando algo foi de fato resolvido
     */
    public static function resolve(string $type, ?int $serverId, ?int $siteId, string $reason = ''): bool
    {
        $fingerprint = Alert::fingerprint($type, $serverId, $siteId);
        $existing    = Alert::findOpenByFingerprint($fingerprint);

        if ($existing === null) {
            return false;
        }

        $id  = (int) $existing['id'];
        $now = now_string();

        Database::statement(
            'UPDATE alerts SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
            [Alert::STATUS_RESOLVED, $now, $now, $id]
        );

        AlertEvent::record($id, AlertEvent::RESOLVED, $reason !== '' ? $reason : 'Condição normalizada.');

        Logger::info('Alerta resolvido automaticamente: ' . $existing['title'], [
            'alert_id' => $id,
            'type'     => $type,
        ]);

        return true;
    }

    /** Reconhecimento manual pelo operador. */
    public static function acknowledge(int $alertId, int $userId): bool
    {
        $alert = Alert::find($alertId);

        if ($alert === null || $alert['status'] !== Alert::STATUS_OPEN) {
            return false;
        }

        $now = now_string();

        Database::statement(
            'UPDATE alerts SET status = ?, acknowledged_at = ?, acknowledged_by = ?, updated_at = ? WHERE id = ?',
            [Alert::STATUS_ACKNOWLEDGED, $now, $userId, $now, $alertId]
        );

        AlertEvent::record($alertId, AlertEvent::ACKNOWLEDGED, 'Reconhecido pelo operador.', $userId);

        return true;
    }

    /** Resolucao manual pelo operador. */
    public static function resolveManually(int $alertId, int $userId): bool
    {
        $alert = Alert::find($alertId);

        if ($alert === null || $alert['status'] === Alert::STATUS_RESOLVED) {
            return false;
        }

        $now = now_string();

        Database::statement(
            'UPDATE alerts SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
            [Alert::STATUS_RESOLVED, $now, $now, $alertId]
        );

        AlertEvent::record($alertId, AlertEvent::RESOLVED, 'Resolvido manualmente pelo operador.', $userId);

        return true;
    }

    // -----------------------------------------------------------------
    // Regras de negocio (secao 19 do PLAN)
    // -----------------------------------------------------------------

    /**
     * Avalia CPU / RAM / disco de um servidor e abre ou resolve os alertas
     * correspondentes.
     *
     * @param  array<string,mixed> $metric Ultima amostra do servidor
     * @return array<int,string>   Tipos de alerta abertos nesta avaliacao
     */
    public static function evaluateServerMetrics(int $serverId, string $serverName, array $metric): array
    {
        $raised = [];

        $checks = [
            Alert::TYPE_SERVER_CPU_HIGH => [
                'metric' => 'cpu',
                'value'  => isset($metric['cpu_usage']) ? (float) $metric['cpu_usage'] : null,
                'label'  => 'CPU',
            ],
            Alert::TYPE_SERVER_MEMORY_HIGH => [
                'metric' => 'ram',
                'value'  => isset($metric['ram_percent']) ? (float) $metric['ram_percent'] : null,
                'label'  => 'memória RAM',
            ],
            Alert::TYPE_SERVER_DISK_HIGH => [
                'metric' => 'disk',
                'value'  => isset($metric['disk_percent']) ? (float) $metric['disk_percent'] : null,
                'label'  => 'disco',
            ],
        ];

        foreach ($checks as $type => $check) {
            $value = $check['value'];

            if ($value === null) {
                continue;
            }

            $level = threshold_level($value, $check['metric']);

            if ($level === 'normal' || $level === 'unknown') {
                self::resolve($type, $serverId, null, sprintf(
                    'Uso de %s voltou a %s.',
                    $check['label'],
                    format_percent($value, 1)
                ));
                continue;
            }

            // A CPU exige amostras consecutivas; RAM e disco nao.
            //
            // RAM e disco sao estados: o que a amostra le e o que esta valendo
            // agora e daqui a um minuto. CPU e uma taxa medida em 500 ms a
            // cada 5 minutos, e um pico nesse meio segundo nao diz nada sobre
            // o servidor. Sem este portao o painel abriu e fechou cinco
            // alertas de CPU numa noite em que a carga real nunca passou de
            // metade dos nucleos.
            if ($check['metric'] === 'cpu') {
                $confirmacoes = max(1, (int) Config::get('monitoring.cpu.confirmations', 3));
                $limite       = (float) Config::get('monitoring.thresholds.cpu.warning', 80.0);

                if (!ServerMetric::cpuHighConfirmed($serverId, $confirmacoes, $limite)) {
                    continue;
                }
            }

            $severity = $level === 'critical' ? Alert::SEVERITY_CRITICAL : Alert::SEVERITY_WARNING;

            self::raise(
                $type,
                sprintf('%s: %s em %s', $serverName, ucfirst($check['label']), format_percent($value, 1)),
                sprintf(
                    'O servidor %s está utilizando %s de %s%s.',
                    $serverName,
                    format_percent($value, 1),
                    $check['label'],
                    $check['metric'] === 'cpu' ? self::loadSuffix($serverId, $metric) : ''
                ),
                ['server_id' => $serverId, 'severity' => $severity, 'value' => $value]
            );

            $raised[] = $type;
        }

        return $raised;
    }

    /**
     * Complemento da mensagem de CPU com a carga real do servidor.
     *
     * O percentual sozinho engana quem le: "96%" de meio segundo assusta e nao
     * informa. A carga por nucleo diz se o servidor esta apenas ocupado ou se
     * ha fila de processos esperando CPU - que e o que derruba sites. Sem os
     * nucleos nao ha como normalizar, entao o texto omite em vez de sugerir
     * uma leitura errada.
     */
    private static function loadSuffix(int $serverId, array $metric): string
    {
        $load = $metric['load_1'] ?? null;

        if ($load === null) {
            return '';
        }

        $load   = (float) $load;
        $server = Server::find($serverId);
        $cores  = (int) ($server['cpu_cores'] ?? 0);

        if ($cores < 1) {
            return sprintf(' (carga de 1 min: %s)', number_format($load, 2, ',', '.'));
        }

        return sprintf(
            ' (carga de 1 min: %s em %d núcleos, %s por núcleo)',
            number_format($load, 2, ',', '.'),
            $cores,
            number_format($load / $cores, 2, ',', '.')
        );
    }

    /** Servidor deixou de responder (secao 28). */
    public static function serverWentOffline(int $serverId, string $serverName, ?string $lastSeen): void
    {
        self::raise(
            Alert::TYPE_SERVER_OFFLINE,
            sprintf('Servidor %s offline', $serverName),
            sprintf(
                'O servidor %s não envia dados desde %s.',
                $serverName,
                $lastSeen === null ? 'o cadastro' : format_datetime($lastSeen)
            ),
            ['server_id' => $serverId, 'severity' => Alert::SEVERITY_CRITICAL]
        );
    }

    /** Servidor voltou: resolve o alerta de offline e os de recurso obsoletos. */
    public static function serverCameBack(int $serverId, string $serverName): void
    {
        self::resolve(
            Alert::TYPE_SERVER_OFFLINE,
            $serverId,
            null,
            sprintf('O servidor %s voltou a se comunicar.', $serverName)
        );
    }

    /** Site indisponivel (secao 29). */
    public static function siteWentOffline(int $siteId, int $serverId, string $domain, ?int $httpStatus, ?string $error): void
    {
        // ------------------------------------------------------------------
        // CONFIRMACAO: uma falha isolada nao vira alerta
        // ------------------------------------------------------------------
        // Um pico de latencia, um redirecionamento lento ou um segundo de
        // perda de pacote fazem o agente registrar offline num ciclo e online
        // no seguinte. Avisar em cima disso gera falso alarme - e falso
        // alarme corroi a confianca no monitoramento inteiro: quem recebe
        // tres avisos errados para de olhar o quarto, que e o de verdade.
        //
        // O portao fica AQUI, e nao em quem chama, porque sao dois caminhos
        // independentes (a ingestao da coleta e o cron de alertas) e ambos
        // precisam da mesma regra. Duplicar a condicao seria criar a chance
        // de um deles escapar numa alteracao futura.
        //
        // O STATUS do site continua mudando na hora: a tela mostra a
        // realidade agora, e so o aviso espera confirmacao.
        $confirmations = max(1, (int) Config::get('monitoring.http.offline_confirmations', 3));

        if (!SiteCheck::offlineConfirmed($siteId, $confirmations)) {
            return;
        }

        $detail = $httpStatus !== null
            ? sprintf('retornou HTTP %d', $httpStatus)
            : sprintf('não respondeu (%s)', $error ?? 'sem resposta');

        self::raise(
            Alert::TYPE_SITE_OFFLINE,
            sprintf('%s offline', $domain),
            sprintf('O site %s %s.', $domain, $detail),
            [
                'server_id' => $serverId,
                'site_id'   => $siteId,
                'severity'  => Alert::SEVERITY_CRITICAL,
                'value'     => $httpStatus === null ? null : (float) $httpStatus,
            ]
        );

        // Avisa o operador fora do painel. Quem decide se a mensagem sai de
        // fato e o NotificationService: canal ligado, dominio nao silenciado,
        // janela e teto respeitados.
        //
        // O try/catch e essencial: um SMTP fora do ar nao pode impedir que o
        // alerta - que ja foi gravado acima - conte como registrado.
        try {
            NotificationService::siteOffline($siteId, $domain, $httpStatus, $error);
        } catch (\Throwable $e) {
            Logger::error('Falha ao enviar aviso de site offline: ' . $e->getMessage(), [
                'site_id' => $siteId,
                'domain'  => $domain,
            ]);
        }
    }

    /** @return bool true quando havia um alerta aberto e ele foi resolvido. */
    public static function siteCameBack(int $siteId, int $serverId, string $domain): bool
    {
        // O "ciente" existe para calar um problema conhecido. Com o site de
        // volta, o problema acabou - manter o silencio faria a proxima queda
        // passar despercebida.
        Site::clearNotifyMuted($siteId);

        return self::resolve(
            Alert::TYPE_SITE_OFFLINE,
            $serverId,
            $siteId,
            sprintf('O site %s voltou a responder.', $domain)
        );
    }

    /**
     * Avalia o certificado de um site (secao 19: > 30 normal, 8-30 atencao,
     * <= 7 critico, expirado critico).
     */
    public static function evaluateSsl(int $siteId, int $serverId, string $domain, ?int $daysRemaining): void
    {
        // Sem dados: nao gera alerta (status cinza), mas limpa os antigos.
        if ($daysRemaining === null) {
            self::resolve(Alert::TYPE_SSL_EXPIRING, $serverId, $siteId, 'Certificado sem dados para avaliar.');
            self::resolve(Alert::TYPE_SSL_EXPIRED, $serverId, $siteId, 'Certificado sem dados para avaliar.');

            return;
        }

        $warningDays  = (int) Config::get('monitoring.ssl.warning', 30);
        $criticalDays = (int) Config::get('monitoring.ssl.critical', 7);

        if ($daysRemaining < 0) {
            self::resolve(Alert::TYPE_SSL_EXPIRING, $serverId, $siteId, 'Certificado expirou.');
            self::raise(
                Alert::TYPE_SSL_EXPIRED,
                sprintf('%s: SSL expirado', $domain),
                sprintf('O certificado de %s expirou ha %d dia(s).', $domain, abs($daysRemaining)),
                [
                    'server_id' => $serverId,
                    'site_id'   => $siteId,
                    'severity'  => Alert::SEVERITY_CRITICAL,
                    'value'     => (float) $daysRemaining,
                ]
            );

            return;
        }

        self::resolve(Alert::TYPE_SSL_EXPIRED, $serverId, $siteId, 'Certificado renovado.');

        if ($daysRemaining <= $warningDays) {
            $severity = $daysRemaining <= $criticalDays ? Alert::SEVERITY_CRITICAL : Alert::SEVERITY_WARNING;

            self::raise(
                Alert::TYPE_SSL_EXPIRING,
                sprintf('%s: SSL vence em %d dia(s)', $domain, $daysRemaining),
                sprintf('O certificado de %s vence em %d dia(s).', $domain, $daysRemaining),
                [
                    'server_id' => $serverId,
                    'site_id'   => $siteId,
                    'severity'  => $severity,
                    'value'     => (float) $daysRemaining,
                ]
            );

            return;
        }

        self::resolve(
            Alert::TYPE_SSL_EXPIRING,
            $serverId,
            $siteId,
            sprintf('Certificado renovado: %d dias restantes.', $daysRemaining)
        );
    }

    /**
     * Fecha todos os alertas abertos de um servidor. Chamado quando o
     * servidor e excluido ou quando os dados ficam obsoletos.
     */
    public static function resolveAllForServer(int $serverId, string $reason): int
    {
        $ids = Database::select(
            "SELECT id FROM alerts WHERE server_id = ? AND status IN ('open','acknowledged')",
            [$serverId]
        );

        foreach ($ids as $row) {
            $id  = (int) $row['id'];
            $now = now_string();

            Database::statement(
                'UPDATE alerts SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
                [Alert::STATUS_RESOLVED, $now, $now, $id]
            );

            AlertEvent::record($id, AlertEvent::RESOLVED, $reason);
        }

        return \count($ids);
    }

    /**
     * Fecha os alertas de um dominio que nao existe mais no servidor.
     *
     * Sem isto, um site removido do CyberPanel deixa "SSL expirado" e "site
     * offline" abertos para sempre: o painel marca o site como nao
     * descoberto e para de checa-lo, mas nada resolve o alerta que ficou -
     * as consultas de alerta nao filtram por `discovered`.
     *
     * Age APENAS sobre os tipos de SITE_ALERT_TYPES e apenas naquele
     * server_id/site_id, via o mesmo fingerprint que abriu o alerta. Se o
     * dominio voltar a ser descoberto, o proximo ciclo reabre normalmente.
     *
     * @return int quantidade de alertas fechados
     */
    public static function resolveForUndiscoveredSite(int $serverId, int $siteId, string $domain): int
    {
        $reason = sprintf(
            'Domínio %s não foi mais encontrado no servidor: alerta encerrado automaticamente.',
            $domain
        );

        $closed = 0;

        foreach (self::SITE_ALERT_TYPES as $type) {
            if (self::resolve($type, $serverId, $siteId, $reason)) {
                $closed++;
            }
        }

        return $closed;
    }
}
