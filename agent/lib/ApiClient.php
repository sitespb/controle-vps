<?php

declare(strict_types=1);

namespace Agent;

/**
 * Cliente HTTP do agente (secoes 9 e 32 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * A RESPOSTA DO PAINEL NUNCA VIRA ACAO
 * ---------------------------------------------------------------------------
 * O que o agente faz com a resposta e exatamente isto: ler o campo numerico
 * `next_interval` e registrar o resto no log. Nenhum campo da resposta e
 * interpretado como comando, caminho de arquivo ou codigo. Esta e a garantia
 * central da V1 - ver secao 5 do PLAN.
 *
 * TRATAMENTO DE FALHA: painel fora do ar, DNS quebrado ou TLS invalido geram
 * um erro registrado localmente e o metodo devolve um resultado de falha. O
 * agente segue para a proxima etapa e tenta de novo no ciclo seguinte.
 */
final class ApiClient
{
    private int $timeout;

    private int $connectTimeout;

    private int $retries;

    public function __construct(
        private string $baseUrl,
        private Signer $signer,
        private Logger $logger,
        private string $agentVersion,
        int $timeout = 20,
        int $connectTimeout = 8,
        int $retries = 2,
        private bool $verifyTls = true
    ) {
        $this->baseUrl        = rtrim($baseUrl, '/');
        $this->timeout        = max(5, $timeout);
        $this->connectTimeout = max(2, $connectTimeout);
        $this->retries        = max(0, $retries);
    }

    /**
     * POST autenticado.
     *
     * @param  array<string,mixed> $payload
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:?string}
     */
    public function post(string $path, array $payload): array
    {
        $url  = $this->baseUrl . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $error = 'Falha ao serializar o payload: ' . json_last_error_msg();
            $this->logger->error($error, ['path' => $path]);

            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $error];
        }

        $attempt   = 0;
        $lastError = null;

        while ($attempt <= $this->retries) {
            $attempt++;

            // Cada tentativa e assinada de novo: timestamp e nonce precisam
            // ser novos, senao o painel recusa como replay.
            $result = $this->send($url, $body);

            if ($result['ok']) {
                if ($attempt > 1) {
                    $this->logger->info("Sucesso na tentativa {$attempt}.", ['path' => $path]);
                }

                return $result;
            }

            $lastError = $result['error'];

            // Erros definitivos nao valem retentativa: assinatura invalida,
            // token revogado, servidor inexistente ou payload malformado
            // continuariam falhando igual.
            if (\in_array($result['status'], [400, 401, 403, 404, 409, 413, 422], true)) {
                $this->logger->error("Recusado pelo painel (HTTP {$result['status']}): {$lastError}", [
                    'path' => $path,
                ]);

                return $result;
            }

            if ($attempt <= $this->retries) {
                $wait = $attempt * 2;
                $this->logger->warning("Falha ao enviar ({$lastError}). Nova tentativa em {$wait}s.", [
                    'path'      => $path,
                    'tentativa' => $attempt,
                ]);
                sleep($wait);
            }
        }

        $this->logger->error("Nao foi possivel enviar apos {$attempt} tentativa(s): {$lastError}", [
            'path' => $path,
        ]);

        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $lastError];
    }

    /**
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:?string}
     */
    private function send(string $url, string $body): array
    {
        $headers = array_merge(
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ControleVPS-Agent/' . $this->agentVersion,
            ],
            $this->signer->headers($body)
        );

        $ch = curl_init($url);

        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Nao foi possivel iniciar o cURL.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_ENCODING       => 'gzip',
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            return [
                'ok'     => false,
                'status' => 0,
                'data'   => [],
                'error'  => $curlErr !== '' ? $curlErr : 'Falha de conexao com o painel.',
            ];
        }

        $decoded = json_decode((string) $response, true);

        if (!\is_array($decoded)) {
            return [
                'ok'     => false,
                'status' => $status,
                'data'   => [],
                'error'  => "Resposta nao e JSON valido (HTTP {$status}).",
            ];
        }

        if ($status >= 200 && $status < 300 && ($decoded['ok'] ?? false) === true) {
            return [
                'ok'     => true,
                'status' => $status,
                'data'   => \is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
                'error'  => null,
            ];
        }

        $message = $decoded['error']['message']
            ?? $decoded['message']
            ?? "Erro HTTP {$status}";

        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => (string) $message];
    }

    /**
     * Verifica se o painel esta acessivel, sem autenticacao.
     * Usado pelo install.sh e pelo modo --test.
     */
    public function health(): bool
    {
        $ch = curl_init($this->baseUrl . '/v1/health');

        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $response !== false && $status === 200;
    }
}
