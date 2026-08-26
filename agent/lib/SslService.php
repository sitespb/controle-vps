<?php

declare(strict_types=1);

namespace Agent;

/**
 * Leitura de certificados TLS (secao 16 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * QUANDO ESTE SERVICO ENTRA
 * ---------------------------------------------------------------------------
 * O caminho normal e o CURLOPT_CERTINFO do HttpCheckService, que traz o
 * certificado de graca junto com a verificacao HTTP. Mas CERTINFO depende do
 * cURL estar compilado com OpenSSL - em builds com GnuTLS ou NSS ele volta
 * vazio.
 *
 * Este servico e o plano B: abre um socket TLS direto e le o certificado com
 * openssl_x509_parse(). Custa uma conexao a mais por dominio, por isso so e
 * usado nos dominios em que o caminho principal nao trouxe dado.
 *
 * Como no HttpCheckService, a verificacao do peer fica DESLIGADA: precisamos
 * inspecionar justamente os certificados invalidos, expirados ou auto
 * assinados - abortar o handshake esconderia o problema que queremos relatar.
 */
final class SslService
{
    public function __construct(
        private Logger $logger,
        private int $timeout = 6
    ) {
    }

    /**
     * Le o certificado de um dominio.
     *
     * @return array<string,mixed>|null null quando nao foi possivel obter
     */
    public function inspect(string $domain, int $port = 443): ?array
    {
        if (!\function_exists('openssl_x509_parse')) {
            return null;
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                // SNI: sem isso um servidor com varios dominios devolve o
                // certificado errado.
                'SNI_enabled'       => true,
                'peer_name'         => $domain,
            ],
        ]);

        $errno  = 0;
        $errstr = '';

        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $domain, $port),
            $errno,
            $errstr,
            $this->timeout,
            \STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            $this->logger->debug("SSL: nao foi possivel conectar em {$domain}:{$port}", [
                'erro' => $errstr !== '' ? $errstr : "errno {$errno}",
            ]);

            return ['error' => mb_substr($errstr !== '' ? $errstr : 'Falha na conexao TLS', 0, 255)];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($cert === null) {
            return ['error' => 'Certificado nao apresentado pelo servidor'];
        }

        $parsed = @openssl_x509_parse($cert);

        if (!\is_array($parsed)) {
            return ['error' => 'Nao foi possivel interpretar o certificado'];
        }

        return [
            'issuer'      => $this->pickName($parsed['issuer'] ?? [], ['O', 'CN']),
            'subject'     => $this->pickName($parsed['subject'] ?? [], ['CN', 'O']),
            'valid_from'  => isset($parsed['validFrom_time_t']) ? date('Y-m-d', (int) $parsed['validFrom_time_t']) : null,
            'valid_until' => isset($parsed['validTo_time_t']) ? date('Y-m-d', (int) $parsed['validTo_time_t']) : null,
        ];
    }

    /**
     * Completa os dominios que ficaram sem dado de certificado.
     *
     * @param  array<int,array<string,mixed>> $sites
     * @return array<int,array<string,mixed>>
     */
    public function fillMissing(array $sites, int $limit = 100): array
    {
        $filled = 0;

        foreach ($sites as $index => $site) {
            if ($filled >= $limit) {
                // Teto de seguranca: em servidores com centenas de dominios
                // sem TLS, o fallback sequencial nao pode estourar o ciclo do
                // cron. Os restantes ficam para a proxima execucao.
                $this->logger->warning(
                    "Limite de {$limit} inspecoes TLS de fallback atingido; os demais ficam para o proximo ciclo."
                );
                break;
            }

            // Ja tem certificado utilizavel? Nada a fazer.
            if (isset($site['ssl']['valid_until']) && $site['ssl']['valid_until'] !== null) {
                continue;
            }

            // Dominio que nem respondeu em HTTPS nao tem o que inspecionar.
            if (empty($site['https_available'])) {
                continue;
            }

            $result = $this->inspect((string) $site['domain']);

            if ($result !== null) {
                $sites[$index]['ssl'] = $result;
                $filled++;
            }
        }

        if ($filled > 0) {
            $this->logger->info("SSL: {$filled} certificado(s) obtido(s) pelo caminho alternativo.");
        }

        return $sites;
    }

    /**
     * @param  array<string,mixed> $dn
     * @param  array<int,string>   $preferred
     */
    private function pickName(array $dn, array $preferred): ?string
    {
        foreach ($preferred as $key) {
            if (!isset($dn[$key])) {
                continue;
            }

            $value = $dn[$key];

            // Campos repetidos vem como array.
            if (\is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (\is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 190);
            }
        }

        return null;
    }
}
