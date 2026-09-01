<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Analisa um dominio hospedado em mais de um servidor.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA
 * ---------------------------------------------------------------------------
 * O mesmo dominio existindo em dois servidores quase sempre significa que um
 * deles e sobra: uma migracao que nao foi concluida, um site recriado no lugar
 * errado, uma restauracao de backup esquecida. O espaco fica ocupado, o backup
 * inclui lixo, e um dia alguem edita a copia que ninguem ve.
 *
 * ---------------------------------------------------------------------------
 * COMO SABEMOS QUAL ESTA NO AR
 * ---------------------------------------------------------------------------
 * Nao da para saber pelo status HTTP: cada agente faz `curl` no dominio, o DNS
 * resolve para o mesmo lugar, e os DOIS servidores reportam o site como online
 * - mesmo aquele que so tem arquivos parados no disco.
 *
 * O sinal certo e o `sites.ip`, que vem de CURLINFO_PRIMARY_IP: o IP em que a
 * requisicao DE FATO conectou, ou seja, o resultado do DNS. Comparando com o
 * IP do proprio servidor, sabemos quem serve:
 *
 *     sites.ip == servers.ip   ->  este servidor e quem responde
 *     sites.ip != servers.ip   ->  esta copia esta obsoleta
 *
 * ---------------------------------------------------------------------------
 * QUANDO NAO DA PARA AFIRMAR
 * ---------------------------------------------------------------------------
 * Com Cloudflare (ou qualquer proxy) na frente, o IP conectado e o do proxy e
 * nao bate com servidor nenhum. Nesse caso a resposta e "nao sei", e a tela
 * precisa dizer isso.
 *
 * Apontar o servidor errado seria pior do que nao apontar nenhum: o operador
 * apagaria a copia que esta no ar.
 */
final class DuplicateSiteService
{
    public const SERVING_THIS = 'this';

    public const SERVING_OTHER = 'other';

    public const SERVING_UNKNOWN = 'unknown';

    /**
     * @param  array<string,mixed>            $site   copia sendo exibida (com server_ip)
     * @param  array<int,array<string,mixed>> $others as demais copias
     * @return array{
     *     serving:string,
     *     resolved_ip:?string,
     *     serving_server:?string,
     *     copies:array<int,array<string,mixed>>
     * }
     */
    public static function analyse(array $site, array $others): array
    {
        if ($others === []) {
            return [
                'serving'        => self::SERVING_UNKNOWN,
                'resolved_ip'    => null,
                'serving_server' => null,
                'copies'         => [],
            ];
        }

        // Todas as copias resolvem o mesmo DNS, entao qualquer IP nao nulo
        // serve. Pegamos o primeiro disponivel, comecando pela copia atual.
        $resolvedIp = self::firstIp(array_merge([$site], $others));

        $servingThis = self::isServing($site);
        $servingName = $servingThis ? (string) ($site['server_name'] ?? '') : null;

        $copies = [];

        foreach ($others as $other) {
            $serves = self::isServing($other);

            if ($serves && $servingName === null) {
                $servingName = (string) ($other['server_name'] ?? '');
            }

            $copies[] = $other + ['is_serving' => $serves];
        }

        if ($servingThis) {
            $serving = self::SERVING_THIS;
        } elseif ($servingName !== null) {
            $serving = self::SERVING_OTHER;
        } else {
            // Ninguem bateu: proxy na frente, ou o DNS aponta para um terceiro
            // servidor que nem esta no painel.
            $serving = self::SERVING_UNKNOWN;
        }

        return [
            'serving'        => $serving,
            'resolved_ip'    => $resolvedIp,
            'serving_server' => $servingName,
            'copies'         => $copies,
        ];
    }

    /**
     * O IP em que o agente conectou e o IP do proprio servidor sao o mesmo?
     *
     * @param array<string,mixed> $copy
     */
    private static function isServing(array $copy): bool
    {
        $connected = (string) ($copy['ip'] ?? '');
        $serverIp  = (string) ($copy['server_ip'] ?? '');

        return $connected !== '' && $serverIp !== '' && $connected === $serverIp;
    }

    /** @param array<int,array<string,mixed>> $copies */
    private static function firstIp(array $copies): ?string
    {
        foreach ($copies as $copy) {
            $ip = (string) ($copy['ip'] ?? '');

            if ($ip !== '') {
                return $ip;
            }
        }

        return null;
    }
}
