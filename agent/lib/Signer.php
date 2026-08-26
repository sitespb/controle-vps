<?php

declare(strict_types=1);

namespace Agent;

/**
 * Assinatura das requisicoes enviadas ao painel (secao 5 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * O TOKEN NUNCA VAI PARA A REDE
 * ---------------------------------------------------------------------------
 * O agente deriva a chave HMAC do token com sha256 e assina cada requisicao.
 * O painel guarda exatamente essa mesma derivacao, entao consegue verificar
 * sem jamais ter recebido o segredo original.
 *
 *   chave     = sha256(token)
 *   canonical = serverId "\n" timestamp "\n" nonce "\n" sha256(corpo)
 *   assinatura = HMAC-SHA256(canonical, chave)
 *
 * Esta string canonica precisa ser IDENTICA a de
 * app/Services/TokenService::canonicalString(). Qualquer divergencia -
 * inclusive na ordem ou no separador - faz o painel recusar com
 * "signature_mismatch".
 */
final class Signer
{
    private string $key;

    public function __construct(
        private int $serverId,
        string $token
    ) {
        $this->key = hash('sha256', $token);
    }

    /**
     * Cabecalhos de autenticacao para um corpo especifico.
     *
     * @return array<int,string> Linhas prontas para CURLOPT_HTTPHEADER
     */
    public function headers(string $body): array
    {
        $timestamp = time();
        $nonce     = $this->nonce();
        $signature = $this->sign($timestamp, $nonce, $body);

        return [
            'X-Server-Id: ' . $this->serverId,
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $signature,
        ];
    }

    public function sign(int $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $this->canonical($timestamp, $nonce, $body), $this->key);
    }

    public function canonical(int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            (string) $this->serverId,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /**
     * Nonce aleatorio. Usa o CSPRNG do sistema; sem ele a protecao contra
     * replay perderia o sentido, entao a falha e fatal e nao silenciosa.
     */
    private function nonce(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Nao foi possivel gerar um nonce seguro: ' . $e->getMessage()
            );
        }
    }
}
