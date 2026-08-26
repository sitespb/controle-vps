<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\ServerService;

/**
 * Recepcao do estado dos servicos do VPS (secao 6 do PLAN).
 *
 * Ponto importante: a AUSENCIA de um servico nao e erro critico. Servidores
 * diferentes tem configuracoes diferentes - um VPS sem Redis e perfeitamente
 * valido. Por isso `not_installed` e um estado legitimo e nenhum status daqui
 * gera alerta na V1.
 */
final class ServiceIngestService
{
    private const VALID_STATUSES = ['running', 'stopped', 'unknown', 'not_installed'];

    /**
     * @param  array<int|string,mixed> $services
     * @return array{received:int,stored:int,skipped:int}
     */
    public static function store(int $serverId, array $services): array
    {
        $known  = Config::get('monitoring.services', []);
        $result = ['received' => \count($services), 'stored' => 0, 'skipped' => 0];

        foreach ($services as $key => $raw) {
            // Aceita lista de objetos ou mapa nome => dados.
            if (\is_array($raw) && isset($raw['name'])) {
                $name = (string) $raw['name'];
                $data = $raw;
            } elseif (\is_string($key)) {
                $name = $key;
                $data = \is_array($raw) ? $raw : ['status' => (string) $raw];
            } else {
                $result['skipped']++;
                continue;
            }

            $name = self::normalizeName($name);

            if ($name === null) {
                $result['skipped']++;
                continue;
            }

            $status = strtolower((string) ($data['status'] ?? 'unknown'));

            if (!\in_array($status, self::VALID_STATUSES, true)) {
                $status = 'unknown';
            }

            try {
                ServerService::upsert($serverId, $name, [
                    'label'      => self::text($data['label'] ?? ($known[$name] ?? ucfirst($name)), 80),
                    'status'     => $status,
                    'version'    => self::text($data['version'] ?? null, 60),
                    'detail'     => self::text($data['detail'] ?? null, 190),
                    'checked_at' => now_string(),
                ]);

                $result['stored']++;
            } catch (\Throwable $e) {
                $result['skipped']++;
                Logger::error('Falha ao gravar servico: ' . $e->getMessage(), [
                    'server_id' => $serverId,
                    'service'   => $name,
                ]);
            }
        }

        // A versao do PHP e do OpenLiteSpeed aparecem no bloco de informacoes
        // do servidor; a origem continua sendo a tabela de servicos.
        self::syncCyberPanelVersion($serverId);

        return $result;
    }

    private static function syncCyberPanelVersion(int $serverId): void
    {
        $version = ServerService::versionOf($serverId, 'cyberpanel');

        if ($version === null || $version === '') {
            return;
        }

        Database::statement(
            'UPDATE servers SET cyberpanel_version = ?, updated_at = ? WHERE id = ?',
            [mb_substr($version, 0, 40), now_string(), $serverId]
        );
    }

    private static function normalizeName(string $name): ?string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_\-]/', '', $name) ?? '';

        return $name === '' ? null : mb_substr($name, 0, 60);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
