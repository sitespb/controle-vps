<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\SslCertificate;

/**
 * Avaliacao dos certificados no painel central (secao 16 do PLAN).
 *
 * A LEITURA do certificado acontece no agente (agent/lib/SslService.php), que
 * tem acesso direto ao dominio. Aqui tratamos apenas do que o painel precisa:
 * normalizar o que chegou, calcular os dias restantes e classificar a cor.
 *
 *   Verde    (valid)    - mais de 30 dias
 *   Amarelo  (expiring) - vence em ate 30 dias
 *   Vermelho (expired)  - expirado
 *   Cinza    (unknown)  - nao foi possivel verificar
 */
final class SslService
{
    /**
     * Normaliza o bloco `ssl` enviado pelo agente.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed> Pronto para SslCertificate::upsert()
     */
    public static function normalize(array $payload): array
    {
        $validFrom  = self::parseDate($payload['valid_from'] ?? null);
        $validUntil = self::parseDate($payload['valid_until'] ?? null);
        $error      = isset($payload['error']) ? mb_substr((string) $payload['error'], 0, 255) : null;

        $daysRemaining = null;

        if ($validUntil !== null) {
            $daysRemaining = self::daysUntil($validUntil);
        } elseif (isset($payload['days_remaining']) && is_numeric($payload['days_remaining'])) {
            $daysRemaining = (int) $payload['days_remaining'];
        }

        $warningDays = (int) Config::get('monitoring.ssl.warning', 30);
        $status      = SslCertificate::classify($daysRemaining, $warningDays);

        // Sem data e sem erro explicito ainda e "desconhecido".
        if ($validUntil === null && $daysRemaining === null) {
            $status = SslCertificate::STATUS_UNKNOWN;
        }

        return [
            'issuer'         => isset($payload['issuer']) ? mb_substr((string) $payload['issuer'], 0, 190) : null,
            'subject'        => isset($payload['subject']) ? mb_substr((string) $payload['subject'], 0, 190) : null,
            'valid_from'     => $validFrom,
            'valid_until'    => $validUntil,
            'days_remaining' => $daysRemaining,
            'status'         => $status,
            'error'          => $error,
            'checked_at'     => now_string(),
        ];
    }

    /** Dias inteiros entre hoje e a data (negativo quando ja passou). */
    public static function daysUntil(string $date): ?int
    {
        $target = strtotime($date . ' 23:59:59');

        if ($target === false) {
            return null;
        }

        $today = strtotime(date('Y-m-d') . ' 00:00:00');

        return (int) floor(($target - $today) / 86400);
    }

    /** Aceita ISO, timestamp e formatos comuns de openssl. Devolve Y-m-d. */
    private static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d', (int) $value);
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * Classe Tailwind do badge de SSL - DESIGN.md secao 8.
     */
    public static function badgeClass(?string $status): string
    {
        return match ($status) {
            SslCertificate::STATUS_VALID    => 'bg-green-100 text-green-800',
            SslCertificate::STATUS_EXPIRING => 'bg-yellow-100 text-yellow-800',
            SslCertificate::STATUS_EXPIRED  => 'bg-red-100 text-red-800',
            default                         => 'bg-gray-100 text-gray-600',
        };
    }

    public static function label(?string $status, ?int $daysRemaining = null): string
    {
        return match ($status) {
            SslCertificate::STATUS_VALID    => 'Valido',
            SslCertificate::STATUS_EXPIRING => $daysRemaining === null
                ? 'Vencendo'
                : sprintf('Vence em %d d', $daysRemaining),
            SslCertificate::STATUS_EXPIRED  => $daysRemaining === null
                ? 'Expirado'
                : sprintf('Expirado ha %d d', abs($daysRemaining)),
            default                         => 'Sem dados',
        };
    }

    /**
     * Recalcula todos os certificados e reavalia os alertas correspondentes.
     * Chamado uma vez por dia pelo cron.
     *
     * @return array{recalculated:int,evaluated:int}
     */
    public static function refreshAll(): array
    {
        $warningDays  = (int) Config::get('monitoring.ssl.warning', 30);
        $criticalDays = (int) Config::get('monitoring.ssl.critical', 7);

        $recalculated = SslCertificate::recalculateAll($warningDays, $criticalDays);

        $evaluated = 0;

        foreach (SslCertificate::needingAttention() as $cert) {
            AlertService::evaluateSsl(
                (int) $cert['site_id'],
                (int) $cert['server_id'],
                (string) $cert['domain'],
                $cert['days_remaining'] === null ? null : (int) $cert['days_remaining']
            );
            $evaluated++;
        }

        return ['recalculated' => $recalculated, 'evaluated' => $evaluated];
    }
}
