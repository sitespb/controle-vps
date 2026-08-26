<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\AuditLog;

/**
 * Registro de eventos administrativos (secao 31 do PLAN).
 *
 * Grava em duas frentes:
 *  - tabela `audit_logs`, consultavel pela tela Logs;
 *  - arquivo em storage/logs/, util quando o banco esta indisponivel.
 *
 * O `context` passa por Logger::redact() antes de ser gravado, entao senhas,
 * tokens e assinaturas jamais chegam ao banco em texto legivel - mesmo que o
 * chamador esqueca de filtrar.
 *
 * Falha ao gravar auditoria NUNCA derruba a operacao principal (secao 32).
 */
final class AuditService
{
    /**
     * @param array{
     *     user_id?:?int, actor?:?string, entity_type?:?string, entity_id?:?int,
     *     level?:string, ip?:?string, user_agent?:?string, context?:array<string,mixed>
     * } $options
     */
    public static function log(string $action, string $description, array $options = []): void
    {
        $userId = $options['user_id'] ?? AuthService::id();
        $actor  = $options['actor'] ?? null;

        if ($actor === null && $userId !== null) {
            $user  = AuthService::user();
            $actor = $user['name'] ?? null;
        }

        $context = $options['context'] ?? [];
        $context = $context === [] ? null : Logger::redact($context);

        $level = $options['level'] ?? 'info';

        try {
            Database::insert('audit_logs', [
                'user_id'     => $userId,
                'actor'       => $actor === null ? null : mb_substr($actor, 0, 120),
                'action'      => mb_substr($action, 0, 60),
                'entity_type' => $options['entity_type'] ?? null,
                'entity_id'   => $options['entity_id'] ?? null,
                'description' => mb_substr($description, 0, 255),
                'level'       => \in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info',
                'ip'          => $options['ip'] ?? self::currentIp(),
                'user_agent'  => $options['user_agent'] ?? self::currentUserAgent(),
                'context'     => $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at'  => now_string(),
            ]);
        } catch (\Throwable $e) {
            // Banco fora do ar nao pode impedir a acao do usuario.
            Logger::error('Falha ao gravar audit_log: ' . $e->getMessage(), [
                'action'      => $action,
                'description' => $description,
            ]);
        }

        // Espelho em arquivo.
        $logMethod = match ($level) {
            'error'   => 'error',
            'warning' => 'warning',
            default   => 'info',
        };

        Logger::{$logMethod}('[' . $action . '] ' . $description, $context ?? []);
    }

    /** Atalhos semanticos usados pelos controllers. */
    public static function serverCreated(int $serverId, string $name): void
    {
        self::log('server.created', sprintf('Servidor "%s" cadastrado.', $name), [
            'entity_type' => 'server',
            'entity_id'   => $serverId,
        ]);
    }

    public static function serverUpdated(int $serverId, string $name, array $changes = []): void
    {
        self::log('server.updated', sprintf('Servidor "%s" atualizado.', $name), [
            'entity_type' => 'server',
            'entity_id'   => $serverId,
            'context'     => $changes === [] ? [] : ['campos' => array_keys($changes)],
        ]);
    }

    public static function serverDeleted(int $serverId, string $name): void
    {
        self::log('server.deleted', sprintf('Servidor "%s" excluido.', $name), [
            'entity_type' => 'server',
            'entity_id'   => $serverId,
            'level'       => 'warning',
        ]);
    }

    public static function tokenRegenerated(int $serverId, string $name, string $prefix): void
    {
        // Apenas o prefixo - nunca o token completo (secao 31).
        self::log('token.regenerated', sprintf('Novo token gerado para o servidor "%s".', $name), [
            'entity_type' => 'server',
            'entity_id'   => $serverId,
            'level'       => 'warning',
            'context'     => ['token_prefix' => $prefix],
        ]);
    }

    public static function agentCommunication(int $serverId, string $endpoint, array $context = []): void
    {
        self::log('agent.' . $endpoint, sprintf('Agente do servidor #%d enviou %s.', $serverId, $endpoint), [
            'entity_type' => 'server',
            'entity_id'   => $serverId,
            'user_id'     => null,
            'actor'       => 'agente',
            'context'     => $context,
        ]);
    }

    public static function apiError(string $message, array $context = []): void
    {
        self::log('api.error', $message, ['level' => 'error', 'context' => $context, 'user_id' => null]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentForServer(int $serverId, int $limit = 10): array
    {
        return AuditLog::recentForEntity('server', $serverId, $limit);
    }

    private static function currentIp(): ?string
    {
        if (\PHP_SAPI === 'cli') {
            return null;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return \is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private static function currentUserAgent(): ?string
    {
        if (\PHP_SAPI === 'cli') {
            return 'cli';
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return \is_string($ua) ? mb_substr($ua, 0, 250) : null;
    }
}
