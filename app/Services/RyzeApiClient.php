<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Cliente da RyzeAPI (WhatsApp).
 *
 * Documentacao de referencia: .agents/ryzeapi-full.txt
 *
 * Superficie usada aqui - de proposito, a menor possivel:
 *
 *   POST /api/message/text/{instancia}   envia o aviso
 *   GET  /api/instance/list?instanceName={instancia}   confere o estado
 *
 * Autenticacao: cabecalho `token` com o TokenInstance (o TokenAccount tambem
 * e aceito pela API, mas o de instancia da menos poder a quem obtiver o valor
 * - se vazar, compromete uma instancia, nao a conta inteira).
 *
 * Erros da API vem como {"success": false, "error": {"message": "..."}}.
 * Traduzimos isso para uma mensagem unica, porque quem le e o operador na tela
 * de teste, nao um programa.
 */
final class RyzeApiClient
{
    private const TIMEOUT = 15;

    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private string $instance,
        private string $token
    ) {
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : 'https://ryzeapi.cloud', '/');
    }

    /** @param array<string,string> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['base_url'] ?? 'https://ryzeapi.cloud'),
            (string) ($config['instance'] ?? ''),
            (string) ($config['token'] ?? '')
        );
    }

    /**
     * Envia uma mensagem de texto.
     *
     * @return array{ok:bool,error:?string,message_id:?string}
     */
    public function sendText(string $number, string $message): array
    {
        if ($this->instance === '' || $this->token === '') {
            return ['ok' => false, 'error' => 'Instancia ou token nao configurados.', 'message_id' => null];
        }

        $number = preg_replace('/\D/', '', $number) ?? '';

        if ($number === '') {
            return ['ok' => false, 'error' => 'Numero de destino invalido.', 'message_id' => null];
        }

        $response = $this->request(
            'POST',
            '/api/message/text/' . rawurlencode($this->instance),
            ['number' => $number, 'message' => $message]
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'], 'message_id' => null];
        }

        $data = $response['data'];

        return [
            'ok'         => true,
            'error'      => null,
            'message_id' => isset($data['data']['messageId']) ? (string) $data['data']['messageId'] : null,
        ];
    }

    /**
     * Estado da instancia - usado pelo botao de teste.
     *
     * Consultar antes de enviar responde a pergunta util ("a instancia esta
     * conectada?") sem gastar uma mensagem de WhatsApp de verdade.
     *
     * IMPORTANTE: o filtro `?instanceName=` da API so funciona com
     * TokenAccount. Com TokenInstance - que e o que recomendamos - ele e
     * IGNORADO, e a resposta traz a instancia dona do token, qualquer que
     * seja o nome pedido. Por isso devolvemos tambem o nome REAL: sem
     * compara-lo, um nome digitado errado passaria no teste como "conectado"
     * e so falharia no primeiro envio de verdade, com "Instance not found".
     *
     * @return array{ok:bool,error:?string,state:?string,name:?string,connected:bool}
     */
    public function instanceState(): array
    {
        if ($this->instance === '' || $this->token === '') {
            return [
                'ok'        => false,
                'error'     => 'Instancia ou token nao configurados.',
                'state'     => null,
                'name'      => null,
                'connected' => false,
            ];
        }

        $response = $this->request(
            'GET',
            '/api/instance/list?instanceName=' . rawurlencode($this->instance)
        );

        if (!$response['ok']) {
            return [
                'ok'        => false,
                'error'     => $response['error'],
                'state'     => null,
                'name'      => null,
                'connected' => false,
            ];
        }

        $node  = $this->extractInstance($response['data']);
        $state = null;
        $name  = null;

        if ($node !== null) {
            foreach (['connection.state', 'state', 'status'] as $caminho) {
                $valor = $caminho === 'connection.state'
                    ? ($node['connection']['state'] ?? null)
                    : ($node[$caminho] ?? null);

                if (\is_string($valor) && $valor !== '') {
                    $state = strtolower($valor);
                    break;
                }
            }

            // `name` do no da instancia - NAO `connection.name`, que e o nome
            // do perfil do WhatsApp e nada tem a ver com a instancia.
            if (isset($node['name']) && \is_string($node['name'])) {
                $name = $node['name'];
            }
        }

        return [
            'ok'        => true,
            'error'     => null,
            'state'     => $state,
            'name'      => $name,
            'connected' => $state === 'connected',
        ];
    }

    /**
     * Localiza o no da instancia na resposta.
     *
     * O envelope varia entre versoes (lista direta, dentro de `data`, objeto
     * unico). Procurar o no em vez de assumir um caminho fixo evita que uma
     * mudanca de casca quebre o teste.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function extractInstance(array $payload): ?array
    {
        $candidates = [];

        foreach (['data', 'instances', 'instance'] as $wrapper) {
            if (!isset($payload[$wrapper]) || !\is_array($payload[$wrapper])) {
                continue;
            }

            if (isset($payload[$wrapper][0]) && \is_array($payload[$wrapper][0])) {
                $candidates[] = $payload[$wrapper][0];
            }

            $candidates[] = $payload[$wrapper];
        }

        $candidates[] = $payload;

        foreach ($candidates as $node) {
            if (isset($node['connection']) || isset($node['name']) || isset($node['state'])) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null $body
     * @return array{ok:bool,error:?string,data:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);

        if ($ch === false) {
            return ['ok' => false, 'error' => 'Nao foi possivel iniciar a requisicao.', 'data' => []];
        }

        $headers = ['token: ' . $this->token, 'Accept: application/json'];

        $options = [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => self::TIMEOUT,
            \CURLOPT_CONNECTTIMEOUT => 8,
            \CURLOPT_CUSTOMREQUEST  => $method,
            \CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $headers[]                    = 'Content-Type: application/json';
            $options[\CURLOPT_POSTFIELDS] = json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        $options[\CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);

        curl_close($ch);

        if ($raw === false) {
            Logger::error('RyzeAPI inacessivel: ' . $err);

            return ['ok' => false, 'error' => 'Nao foi possivel falar com a RyzeAPI: ' . $err, 'data' => []];
        }

        $decoded = json_decode((string) $raw, true);

        if (!\is_array($decoded)) {
            return [
                'ok'    => false,
                'error' => sprintf('Resposta invalida da RyzeAPI (HTTP %d).', $status),
                'data'  => [],
            ];
        }

        if ($status >= 400 || ($decoded['success'] ?? true) === false) {
            $message = $decoded['error']['message']
                ?? $decoded['message']
                ?? sprintf('HTTP %d', $status);

            return ['ok' => false, 'error' => mb_substr((string) $message, 0, 255), 'data' => $decoded];
        }

        return ['ok' => true, 'error' => null, 'data' => $decoded];
    }
}
