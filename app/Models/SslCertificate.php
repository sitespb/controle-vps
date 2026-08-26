<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class SslCertificate extends Model
{
    protected static string $table = 'ssl_certificates';

    protected static array $fillable = [
        'site_id', 'issuer', 'subject', 'valid_from', 'valid_until',
        'days_remaining', 'status', 'error', 'checked_at',
    ];

    public const STATUS_VALID    = 'valid';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_UNKNOWN  = 'unknown';

    /** @return array<string,mixed>|null */
    public static function forSite(int $siteId): ?array
    {
        return self::findBy('site_id', $siteId);
    }

    /** Insere ou atualiza o certificado do site (UNIQUE em site_id). */
    public static function upsert(int $siteId, array $data): void
    {
        Database::statement(
            'INSERT INTO ssl_certificates
                (site_id, issuer, subject, valid_from, valid_until, days_remaining,
                 status, error, checked_at, created_at, updated_at)
             VALUES
                (:site_id, :issuer, :subject, :valid_from, :valid_until, :days_remaining,
                 :status, :error, :checked_at, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                issuer         = VALUES(issuer),
                subject        = VALUES(subject),
                valid_from     = VALUES(valid_from),
                valid_until    = VALUES(valid_until),
                days_remaining = VALUES(days_remaining),
                status         = VALUES(status),
                error          = VALUES(error),
                checked_at     = VALUES(checked_at),
                updated_at     = VALUES(updated_at)',
            [
                ':site_id'        => $siteId,
                ':issuer'         => $data['issuer'] ?? null,
                ':subject'        => $data['subject'] ?? null,
                ':valid_from'     => $data['valid_from'] ?? null,
                ':valid_until'    => $data['valid_until'] ?? null,
                ':days_remaining' => $data['days_remaining'] ?? null,
                ':status'         => $data['status'] ?? self::STATUS_UNKNOWN,
                ':error'          => $data['error'] ?? null,
                ':checked_at'     => $data['checked_at'] ?? now_string(),
                // Placeholders distintos: prepares nativos do MySQL nao
                // aceitam o mesmo parametro nomeado repetido.
                ':created_at'     => now_string(),
                ':updated_at'     => now_string(),
            ]
        );
    }

    /**
     * Recalcula days_remaining e status de todos os certificados.
     *
     * Roda uma vez por dia no cron: sem isso um certificado coletado ha 20
     * dias continuaria mostrando os dias daquele momento.
     *
     * @return int Linhas atualizadas
     */
    public static function recalculateAll(int $warningDays, int $criticalDays): int
    {
        return Database::statement(
            'UPDATE ssl_certificates
             SET days_remaining = DATEDIFF(valid_until, CURDATE()),
                 status = CASE
                     WHEN valid_until IS NULL THEN ?
                     WHEN DATEDIFF(valid_until, CURDATE()) < 0  THEN ?
                     WHEN DATEDIFF(valid_until, CURDATE()) <= ? THEN ?
                     ELSE ?
                 END,
                 updated_at = ?
             WHERE valid_until IS NOT NULL',
            [
                self::STATUS_UNKNOWN,
                self::STATUS_EXPIRED,
                $warningDays,
                self::STATUS_EXPIRING,
                self::STATUS_VALID,
                now_string(),
            ]
        );
    }

    /**
     * Certificados que precisam de alerta (vencendo ou expirados).
     *
     * O filtro `s.discovered = 1` e obrigatorio: sem ele o cron de alertas
     * reabre, a cada 5 minutos, o alerta de SSL de um dominio que ja foi
     * removido do servidor - inclusive logo depois da coleta te-lo encerrado.
     * As consultas irmas de disponibilidade (offlineForAlerts /
     * onlineForAlerts) sempre filtraram; esta ficou de fora.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function needingAttention(): array
    {
        return Database::select(
            "SELECT cert.*, s.domain, s.server_id, srv.name AS server_name
             FROM ssl_certificates cert
             INNER JOIN sites s ON s.id = cert.site_id
             INNER JOIN servers srv ON srv.id = s.server_id
             WHERE cert.status IN ('expiring','expired')
               AND s.discovered = 1
             ORDER BY cert.days_remaining ASC"
        );
    }

    /**
     * Classifica o certificado conforme a secao 16 do PLAN.
     *
     * @return 'valid'|'expiring'|'expired'|'unknown'
     */
    public static function classify(?int $daysRemaining, int $warningDays = 30): string
    {
        if ($daysRemaining === null) {
            return self::STATUS_UNKNOWN;
        }

        if ($daysRemaining < 0) {
            return self::STATUS_EXPIRED;
        }

        return $daysRemaining <= $warningDays ? self::STATUS_EXPIRING : self::STATUS_VALID;
    }
}
