<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Models\Site;

final class SiteCheck extends Model
{
    protected static string $table = 'site_checks';

    protected static bool $timestamps = false;

    protected static array $fillable = [
        'site_id', 'status', 'http_status', 'response_time', 'error', 'status_changed', 'created_at',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function recentFor(int $siteId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM site_checks WHERE site_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$siteId]
        );
    }

    /** Apenas as mudancas de estado - a linha do tempo do site. @return array<int,array<string,mixed>> */
    public static function changesFor(int $siteId, int $limit = 20): array
    {
        return Database::select(
            'SELECT * FROM site_checks
             WHERE site_id = ? AND status_changed = 1
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, $limit),
            [$siteId]
        );
    }

    /**
     * Serie de tempo de resposta para o grafico da pagina do site.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function responseSeries(int $siteId, int $hours = 24, int $maxPoints = 120): array
    {
        $rows = Database::select(
            'SELECT created_at, response_time, http_status, status
             FROM site_checks
             WHERE site_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at ASC',
            [$siteId, $hours]
        );

        $total = \count($rows);
        if ($total <= $maxPoints) {
            return $rows;
        }

        $step   = $total / $maxPoints;
        $result = [];
        for ($i = 0; $i < $maxPoints; $i++) {
            $result[] = $rows[(int) floor($i * $step)];
        }
        $result[$maxPoints - 1] = $rows[$total - 1];

        return $result;
    }

    /**
     * Disponibilidade (%) no periodo - exibida na pagina individual do site.
     */
    public static function uptimePercent(int $siteId, int $hours = 24): ?float
    {
        $row = Database::selectOne(
            "SELECT
                 COUNT(*) AS total,
                 SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online
             FROM site_checks
             WHERE site_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$siteId, $hours]
        );

        $total = (int) ($row['total'] ?? 0);

        if ($total === 0) {
            return null;
        }

        return round(((int) $row['online'] / $total) * 100, 2);
    }

    /**
     * As ultimas N verificacoes deste site foram TODAS offline?
     *
     * ---------------------------------------------------------------------
     * POR QUE ISTO EXISTE
     * ---------------------------------------------------------------------
     * Uma unica verificacao que falha nao prova que o site caiu. Um pico de
     * latencia, um redirecionamento lento, um segundo de perda de pacote - e
     * o agente registra offline num ciclo e online no seguinte. Avisar em
     * cima disso gera falso alarme, e falso alarme corroi a confianca no
     * monitoramento inteiro: quem recebe tres avisos errados para de olhar o
     * quarto, que e o de verdade.
     *
     * Confirmar em ciclos CONSECUTIVOS e melhor do que repetir a requisicao
     * na hora: as coletas sao espacadas em minutos, entao duas falhas
     * seguidas dizem "esta fora ha um tempo", enquanto tres tentativas
     * separadas por milissegundos so repetiriam o mesmo instante ruim.
     *
     * Menos de N verificacoes no historico devolve false: sem base para
     * afirmar, nao afirmamos.
     */
    public static function offlineConfirmed(int $siteId, int $confirmations): bool
    {
        $confirmations = max(1, $confirmations);

        $rows = Database::select(
            'SELECT status FROM site_checks WHERE site_id = ? ORDER BY id DESC LIMIT ' . $confirmations,
            [$siteId]
        );

        if (\count($rows) < $confirmations) {
            return false;
        }

        foreach ($rows as $row) {
            if ((string) $row['status'] !== Site::STATUS_OFFLINE) {
                return false;
            }
        }

        return true;
    }

    /** Quantas verificacoes seguidas, a partir da mais recente, estao offline. */
    public static function consecutiveOffline(int $siteId, int $lookback = 10): int
    {
        $rows = Database::select(
            'SELECT status FROM site_checks WHERE site_id = ? ORDER BY id DESC LIMIT ' . max(1, $lookback),
            [$siteId]
        );

        $total = 0;

        foreach ($rows as $row) {
            if ((string) $row['status'] !== Site::STATUS_OFFLINE) {
                break;
            }

            $total++;
        }

        return $total;
    }

    public static function pruneOlderThan(int $days, int $batchSize = 5000): int
    {
        if ($days <= 0) {
            return 0;
        }

        $totalRemoved = 0;

        do {
            $removed = Database::statement(
                'DELETE FROM site_checks
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                 LIMIT ' . max(100, $batchSize),
                [$days]
            );
            $totalRemoved += $removed;
        } while ($removed > 0);

        return $totalRemoved;
    }
}
