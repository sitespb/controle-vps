<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\ServerToken;

/**
 * Geracao e verificacao das credenciais dos agentes (secao 5 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * MODELO DE SEGURANCA
 * ---------------------------------------------------------------------------
 * O token e gerado com random_bytes() (CSPRNG) no formato:
 *
 *     cvps_<serverId>_<64 hex>
 *
 * Ele e exibido UMA UNICA VEZ, no momento do cadastro/regeneracao. No banco
 * guardamos apenas sha256(token).
 *
 * O agente NAO envia o token nas requisicoes. Ele calcula sha256(token)
 * localmente e usa esse valor como chave HMAC para assinar cada chamada:
 *
 *     canonical = serverId "\n" timestamp "\n" nonce "\n" sha256(corpo)
 *     signature = HMAC-SHA256(canonical, sha256(token))
 *
 * Consequencias praticas:
 *  - o segredo nunca trafega na rede, nem mesmo dentro do TLS;
 *  - alterar um byte do corpo invalida a assinatura (integridade);
 *  - o timestamp limita a janela de uso da assinatura;
 *  - o nonce, com chave unica no banco, impede replay dentro dessa janela.
 *
 * Limitacao assumida e documentada: a chave HMAC fica no banco. Quem tem
 * acesso de leitura ao banco consegue forjar chamadas de agente. Isso e o
 * mesmo modelo de qualquer API de chave compartilhada e e aceitavel porque a
 * V1 e somente leitura - um atacante nesse nivel injetaria metricas falsas,
 * nunca comandos: o agente jamais executa nada vindo do painel.
 */
final class TokenService
{
    private const PREFIX = 'cvps';

    /** Tamanho da parte aleatoria em bytes (32 bytes = 64 chars hex). */
    private const RANDOM_BYTES = 32;

    /**
     * Gera um token novo, revoga o anterior e persiste o hash.
     *
     * @return array{token:string,prefix:string,hash:string,id:int}
     */
    public static function generateFor(int $serverId, ?int $userId = null): array
    {
        $plain  = self::buildToken($serverId);
        $hash   = self::hash($plain);
        $prefix = substr($plain, 0, 20);

        return Database::transaction(static function () use ($serverId, $plain, $hash, $prefix, $userId): array {
            // Revogar antes de inserir garante que so exista um token valido.
            ServerToken::revokeAllFor($serverId);

            $id = Database::insert('server_tokens', [
                'server_id'    => $serverId,
                'token_hash'   => $hash,
                'token_prefix' => $prefix,
                'created_by'   => $userId,
                'created_at'   => now_string(),
            ]);

            return ['token' => $plain, 'prefix' => $prefix, 'hash' => $hash, 'id' => $id];
        });
    }

    private static function buildToken(int $serverId): string
    {
        return sprintf('%s_%d_%s', self::PREFIX, $serverId, bin2hex(random_bytes(self::RANDOM_BYTES)));
    }

    /** Hash do token - tambem e a chave HMAC compartilhada com o agente. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Monta a string canonica assinada. Precisa ser identica no agente
     * (agent/lib/Signer.php) - qualquer divergencia invalida a assinatura.
     */
    public static function canonicalString(int $serverId, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            (string) $serverId,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $canonical, string $key): string
    {
        return hash_hmac('sha256', $canonical, $key);
    }

    /**
     * Valida uma requisicao de agente.
     *
     * @return array{ok:bool,error:string,code:string,status:int,token:?array<string,mixed>}
     */
    public static function verifyRequest(
        int $serverId,
        int $timestamp,
        string $nonce,
        string $signature,
        string $body
    ): array {
        $fail = static fn (string $error, string $code, int $status = 401): array => [
            'ok' => false, 'error' => $error, 'code' => $code, 'status' => $status, 'token' => null,
        ];

        if ($serverId <= 0) {
            return $fail('Identificação de servidor inválida.', 'invalid_server', 400);
        }

        if ($nonce === '' || \strlen($nonce) > 64 || preg_match('/^[A-Za-z0-9_\-]+$/', $nonce) !== 1) {
            return $fail('Nonce ausente ou em formato inválido.', 'invalid_nonce', 400);
        }

        if ($signature === '' || preg_match('/^[a-f0-9]{64}$/i', $signature) !== 1) {
            return $fail('Assinatura ausente ou em formato inválido.', 'invalid_signature', 400);
        }

        // 1) Janela temporal
        $skew = (int) Config::get('monitoring.agent_api.clock_skew', 300);
        $diff = abs(time() - $timestamp);

        if ($timestamp <= 0 || $diff > $skew) {
            return $fail(
                sprintf('Timestamp fora da janela permitida (%d s). Verifique o relogio do servidor.', $skew),
                'stale_timestamp'
            );
        }

        // 2) Token ativo do servidor
        $token = ServerToken::activeFor($serverId);

        if ($token === null) {
            return $fail('Servidor sem token ativo. Gere um novo token no painel.', 'no_active_token');
        }

        // 3) Assinatura
        $expected = self::sign(
            self::canonicalString($serverId, $timestamp, $nonce, $body),
            (string) $token['token_hash']
        );

        if (!hash_equals($expected, strtolower($signature))) {
            return $fail('Assinatura inválida.', 'signature_mismatch');
        }

        // 4) Replay: a chave unica (server_id, nonce) rejeita a repeticao no
        //    proprio banco, sem janela de corrida no PHP.
        if (!self::consumeNonce($serverId, $nonce)) {
            return $fail('Requisição repetida (nonce já utilizado).', 'replay_detected', 409);
        }

        return ['ok' => true, 'error' => '', 'code' => '', 'status' => 200, 'token' => $token];
    }

    private static function consumeNonce(int $serverId, string $nonce): bool
    {
        try {
            Database::insert('agent_nonces', [
                'server_id'  => $serverId,
                'nonce'      => $nonce,
                'created_at' => now_string(),
            ]);

            return true;
        } catch (\PDOException $e) {
            // 23000 = violacao de chave unica => nonce ja usado.
            if ($e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }
    }

    /** Limpeza dos nonces antigos (cron). */
    public static function pruneNonces(int $days = 1): int
    {
        return Database::statement(
            'DELETE FROM agent_nonces WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, $days)]
        );
    }

    /**
     * Extrai o server id embutido no token, sem consultar o banco.
     * Util nas mensagens de diagnostico do instalador do agente.
     */
    public static function serverIdFromToken(string $token): ?int
    {
        if (preg_match('/^' . self::PREFIX . '_(\d+)_[a-f0-9]{64}$/', $token, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public static function looksValid(string $token): bool
    {
        return self::serverIdFromToken($token) !== null;
    }
}
