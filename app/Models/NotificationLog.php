<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Historico de avisos enviados - e a fonte do limite de envio.
 *
 * O limite tem duas camadas, e as duas importam:
 *
 *   POR DOMINIO   um site instavel que oscila a cada coleta geraria um aviso
 *                 a cada 5 minutos. A janela por dominio corta isso.
 *
 *   GLOBAL        quando o servidor inteiro cai, TODOS os dominios dele ficam
 *                 offline no mesmo ciclo. Sem teto global, seriam dezenas de
 *                 mensagens em segundos - o caminho mais curto para o Gmail
 *                 classificar a conta como spam ou a RyzeAPI bloquear.
 *
 * As duas perguntas sao respondidas por indice; nenhuma varre a tabela.
 */
final class NotificationLog
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const EVENT_SITE_OFFLINE = 'site_offline';

    public const EVENT_TEST = 'teste';

    /**
     * @param array{channel:string,event:string,site_id?:?int,domain?:?string,recipient:string,status:string,error?:?string} $data
     */
    public static function record(array $data): int
    {
        return Database::insert('notification_log', [
            'channel'    => $data['channel'],
            'event'      => $data['event'],
            'site_id'    => $data['site_id'] ?? null,
            'domain'     => $data['domain'] ?? null,
            'recipient'  => mb_substr($data['recipient'], 0, 255),
            'status'     => $data['status'],
            'error'      => isset($data['error']) && $data['error'] !== null
                ? mb_substr($data['error'], 0, 255)
                : null,
            'created_at' => now_string(),
        ]);
    }

    /**
     * Este dominio ja foi avisado neste canal dentro da janela?
     *
     * Conta apenas envios BEM-SUCEDIDOS: se a tentativa anterior falhou, o
     * operador nao foi avisado de nada, e bloquear a proxima tentativa
     * transformaria uma falha de SMTP em silencio permanente.
     */
    public static function sentRecentlyFor(string $channel, string $domain, int $windowMinutes): bool
    {
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_log
             WHERE channel = ? AND domain = ? AND event = ? AND status = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$channel, $domain, self::EVENT_SITE_OFFLINE, self::STATUS_SENT, max(1, $windowMinutes)]
        );

        return $count > 0;
    }

    /** Quantas mensagens sairam neste canal na ultima hora. */
    public static function sentInLastHour(string $channel): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_log
             WHERE channel = ? AND status = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$channel, self::STATUS_SENT]
        );
    }

    /** @return array<int,array<string,mixed>> Ultimos envios, para a tela de Avisos. */
    public static function recent(int $limit = 20): array
    {
        return Database::select(
            'SELECT * FROM notification_log ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    /** Expurgo pela rotina de retencao. */
    public static function purgeOlderThan(int $days): int
    {
        return Database::statement(
            'DELETE FROM notification_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, $days)]
        );
    }
}
