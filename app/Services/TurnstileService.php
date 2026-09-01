<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\SecureSetting;

/**
 * Cloudflare Turnstile - o captcha da tela de login.
 *
 * ---------------------------------------------------------------------------
 * COMO FUNCIONA
 * ---------------------------------------------------------------------------
 * A chave PUBLICA (site key) vai no HTML e produz um token no navegador. A
 * chave SECRETA fica no servidor e valida esse token contra a Cloudflare. Sem
 * a validacao no servidor o widget e decorativo: qualquer um posta o
 * formulario direto, sem passar pelo navegador.
 *
 * ---------------------------------------------------------------------------
 * O QUE ACONTECE QUANDO A CLOUDFLARE ESTA FORA DO AR
 * ---------------------------------------------------------------------------
 * Esta e a decisao mais importante do arquivo, e ela e deliberada: uma falha
 * de REDE ao falar com a Cloudflare NAO bloqueia o login.
 *
 * O captcha aqui protege contra forca bruta - e para isso ja existem duas
 * camadas independentes: o rate limit por IP e a contagem de tentativas por
 * usuario. Se um problema na Cloudflare trancasse o painel, o administrador
 * ficaria de fora justamente quando talvez precise investigar uma queda. Um
 * token invalido ou ausente continua sendo recusado normalmente; o que
 * liberamos e apenas o caso "nao consegui perguntar".
 */
final class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const TIMEOUT = 8;

    /** Nome do campo que o widget cria no formulario. */
    public const FIELD = 'cf-turnstile-response';

    /**
     * O captcha esta ativo e utilizavel?
     *
     * Ligado sem as duas chaves nao conta como ativo: exibir um widget que
     * nunca valida so impediria o login sem proteger nada.
     */
    public static function isEnabled(): bool
    {
        $config = SecureSetting::all(SecureSetting::SCOPE_TURNSTILE);

        return ($config['enabled'] ?? '0') === '1'
            && trim($config['site_key'] ?? '') !== ''
            && trim($config['secret_key'] ?? '') !== '';
    }

    /** Chave publica, para o HTML. */
    public static function siteKey(): string
    {
        return trim(SecureSetting::get(SecureSetting::SCOPE_TURNSTILE, 'site_key'));
    }

    /**
     * Valida o token enviado pelo formulario.
     *
     * @return array{ok:bool,error:?string}
     */
    public static function verify(?string $token, ?string $ip = null): array
    {
        if (!self::isEnabled()) {
            return ['ok' => true, 'error' => null];
        }

        $token = trim((string) $token);

        if ($token === '') {
            return [
                'ok'    => false,
                'error' => 'Confirme que você não é um robô antes de entrar.',
            ];
        }

        $secret   = SecureSetting::get(SecureSetting::SCOPE_TURNSTILE, 'secret_key');
        $resposta = self::ask($secret, $token, $ip);

        if ($resposta['network_error'] !== null) {
            // Ver o cabecalho da classe: indisponibilidade da Cloudflare nao
            // pode trancar o painel. Registramos e seguimos.
            Logger::warning('Turnstile inacessivel; login liberado sem o captcha.', [
                'erro' => $resposta['network_error'],
            ]);

            return ['ok' => true, 'error' => null];
        }

        if ($resposta['success']) {
            return ['ok' => true, 'error' => null];
        }

        return [
            'ok'    => false,
            'error' => self::describe($resposta['codes']),
        ];
    }

    /**
     * Testa as chaves sem precisar resolver um captcha.
     *
     * O truque: mandamos a chave secreta com um token propositalmente
     * invalido. A Cloudflare valida o SEGREDO primeiro - se ele estiver
     * errado, responde `invalid-input-secret`; se estiver certo, a reclamacao
     * passa a ser sobre o token (`invalid-input-response`). Ou seja, o erro
     * que voltar diz exatamente qual das duas coisas esta errada, e o
     * operador confirma a configuracao sem abrir a tela de login.
     *
     * @return array{ok:bool,error:?string,detail:array<int,string>}
     */
    public static function testKeys(): array
    {
        $config = SecureSetting::all(SecureSetting::SCOPE_TURNSTILE);

        $siteKey = trim($config['site_key'] ?? '');
        $secret  = trim($config['secret_key'] ?? '');

        if ($siteKey === '' || $secret === '') {
            return [
                'ok'     => false,
                'error'  => 'Preencha e salve a chave do site e a chave secreta antes de testar.',
                'detail' => [],
            ];
        }

        $resposta = self::ask($secret, 'token-de-teste-invalido-de-proposito', null);

        if ($resposta['network_error'] !== null) {
            return [
                'ok'     => false,
                'error'  => 'Não foi possível falar com a Cloudflare: ' . $resposta['network_error'],
                'detail' => [],
            ];
        }

        $codes = $resposta['codes'];

        if (\in_array('invalid-input-secret', $codes, true) || \in_array('missing-input-secret', $codes, true)) {
            return [
                'ok'     => false,
                'error'  => 'A chave secreta foi recusada pela Cloudflare. Confira se copiou a Secret Key '
                    . '(e não a Site Key) do widget correto.',
                'detail' => ['Resposta da Cloudflare: ' . implode(', ', $codes)],
            ];
        }

        // Rejeitou o token, nao o segredo: a chave secreta esta correta.
        return [
            'ok'     => true,
            'error'  => null,
            'detail' => [
                'Chave secreta aceita pela Cloudflare.',
                'Chave do site: ' . mb_substr($siteKey, 0, 12) . '...',
                'O widget aparece na tela de login assim que o captcha for ativado.',
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Comunicacao
    // -----------------------------------------------------------------

    /**
     * @return array{success:bool,codes:array<int,string>,network_error:?string}
     */
    private static function ask(string $secret, string $token, ?string $ip): array
    {
        $campos = ['secret' => $secret, 'response' => $token];

        if ($ip !== null && $ip !== '') {
            $campos['remoteip'] = $ip;
        }

        $ch = curl_init(self::VERIFY_URL);

        if ($ch === false) {
            return ['success' => false, 'codes' => [], 'network_error' => 'curl indisponível'];
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => http_build_query($campos),
            \CURLOPT_TIMEOUT        => self::TIMEOUT,
            \CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);

        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'codes' => [], 'network_error' => $err !== '' ? $err : 'sem resposta'];
        }

        $decoded = json_decode((string) $raw, true);

        if (!\is_array($decoded)) {
            return ['success' => false, 'codes' => [], 'network_error' => 'resposta inválida da Cloudflare'];
        }

        $codes = $decoded['error-codes'] ?? [];

        return [
            'success'       => ($decoded['success'] ?? false) === true,
            'codes'         => \is_array($codes) ? array_map('strval', $codes) : [],
            'network_error' => null,
        ];
    }

    /**
     * Traduz os codigos da Cloudflare para algo acionavel.
     *
     * Quem le esta mensagem esta na tela de login e nao tem acesso a
     * configuracao - por isso os textos falam de "tente de novo", e o detalhe
     * tecnico vai para o log.
     *
     * @param array<int,string> $codes
     */
    private static function describe(array $codes): string
    {
        if (\in_array('timeout-or-duplicate', $codes, true)) {
            return 'A verificação expirou. Recarregue a página e tente de novo.';
        }

        if (\in_array('invalid-input-secret', $codes, true) || \in_array('missing-input-secret', $codes, true)) {
            // Erro de CONFIGURACAO, nao do visitante. Registrar e essencial:
            // sem isso, ninguem consegue entrar e a tela nao diz o porque.
            Logger::error('Turnstile mal configurado: a chave secreta foi recusada pela Cloudflare.');

            return 'A verificação de segurança está mal configurada. Avise o administrador.';
        }

        return 'A verificação de segurança falhou. Tente de novo.';
    }
}
