<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Server;

/**
 * Cadastro de servidores e ciclo de vida do token (secao 12 do PLAN).
 *
 * Ao salvar um servidor:
 *   1. gera identificacao unica (uid);
 *   2. gera token seguro;
 *   3. salva o servidor;
 *   4. devolve as instrucoes de instalacao do agente.
 *
 * O token em texto puro so existe no retorno deste metodo - dali ele vai
 * direto para a tela de instrucoes e nunca mais e recuperavel.
 */
final class ServerProvisionService
{
    /**
     * @param  array<string,mixed> $data
     * @return array{server_id:int,token:string,prefix:string}
     */
    public static function create(array $data, ?int $userId = null): array
    {
        return Database::transaction(static function () use ($data, $userId): array {
            $serverId = Database::insert('servers', [
                'uid'         => Server::generateUid(),
                'name'        => mb_substr(trim((string) $data['name']), 0, 120),
                'provider'    => self::nullable($data['provider'] ?? null, 120),
                'hostname'    => self::nullable($data['hostname'] ?? null, 190),
                'ip'          => self::nullable($data['ip'] ?? null, 45),
                'description' => self::nullable($data['description'] ?? null, 65535),
                'status'      => Server::STATUS_UNKNOWN,
                'is_demo'     => 0,
                'created_at'  => now_string(),
                'updated_at'  => now_string(),
            ]);

            $token = TokenService::generateFor($serverId, $userId);

            return [
                'server_id' => $serverId,
                'token'     => $token['token'],
                'prefix'    => $token['prefix'],
            ];
        });
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed> Campos alterados
     */
    public static function update(int $serverId, array $data): array
    {
        $changes = [
            'name'        => mb_substr(trim((string) $data['name']), 0, 120),
            'provider'    => self::nullable($data['provider'] ?? null, 120),
            'hostname'    => self::nullable($data['hostname'] ?? null, 190),
            'ip'          => self::nullable($data['ip'] ?? null, 45),
            'description' => self::nullable($data['description'] ?? null, 65535),
            'updated_at'  => now_string(),
        ];

        Database::update('servers', $changes, ['id' => $serverId]);

        return $changes;
    }

    /**
     * Regenera o token invalidando o anterior (secao 12).
     *
     * @return array{token:string,prefix:string}
     */
    public static function regenerateToken(int $serverId, ?int $userId = null): array
    {
        $token = TokenService::generateFor($serverId, $userId);

        return ['token' => $token['token'], 'prefix' => $token['prefix']];
    }

    /**
     * Exclui o servidor. As FKs com ON DELETE CASCADE removem metricas,
     * sites, checagens, certificados, alertas e tokens associados.
     */
    public static function delete(int $serverId): bool
    {
        return Database::delete('servers', ['id' => $serverId]) > 0;
    }

    /**
     * Monta as instrucoes de instalacao mostradas apos o cadastro.
     *
     * @return array{
     *     install_command:string, config_block:string, cron_line:string,
     *     api_url:string, interval:int, path:string
     * }
     */
    public static function installationInstructions(int $serverId, string $token): array
    {
        $apiUrl   = rtrim((string) Config::get('app.url', ''), '/') . '/api';
        $interval = (int) Config::get('monitoring.agent_interval', 300);
        $path     = '/opt/controle-vps-agent';

        $installCommand = implode("\n", [
            '# 1. Envie a pasta agent/ do painel para o servidor e execute:',
            sprintf('sudo bash %s/install.sh \\', $path),
            sprintf('    --server-id %d \\', $serverId),
            sprintf('    --token %s \\', $token),
            sprintf('    --url %s', $apiUrl),
        ]);

        $configBlock = implode("\n", [
            "SERVER_ID={$serverId}",
            "SERVER_TOKEN={$token}",
            "CENTRAL_URL={$apiUrl}",
            "INTERVAL={$interval}",
        ]);

        // CAMINHO_DO_PHP e um marcador PROPOSITAL, nao um esquecimento.
        //
        // O painel nao tem como saber qual binario existe no VPS: em
        // CyberPanel o PHP 8 fica em /usr/local/lsws/lsphp83/bin/php, em
        // aaPanel em /www/server/php/83/bin/php, e o `php` do PATH costuma
        // ser o do sistema (7.x), que o agente nem consegue executar.
        // Exibir um caminho fixo aqui produzia um cron que falha em silencio
        // a cada 5 minutos. Quem resolve o caminho e o install.sh, que
        // detecta o binario e registra a linha ja pronta.
        $cronLine = sprintf(
            '*/%d * * * * CAMINHO_DO_PHP %s/agent.php >> %s/logs/cron.log 2>&1',
            max(1, (int) round($interval / 60)),
            $path,
            $path
        );

        return [
            'install_command' => $installCommand,
            'config_block'    => $configBlock,
            'cron_line'       => $cronLine,
            'api_url'         => $apiUrl,
            'interval'        => $interval,
            'path'            => $path,
        ];
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
