<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Criptografia simetrica dos segredos guardados no banco.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE
 * ---------------------------------------------------------------------------
 * A senha do SMTP e o token da RyzeAPI sao credenciais de terceiros: quem as
 * obtem passa a enviar e-mail e WhatsApp em nome do operador. Guardar em texto
 * puro significaria que um dump do banco - um backup vazado, um `SELECT` de um
 * usuario com acesso de leitura - entrega essas credenciais prontas.
 *
 * A chave NAO fica no banco: vem do APP_KEY do .env, que tem permissao 640 e
 * nunca e versionado. Banco e chave em lugares diferentes, que e o ponto.
 *
 * ---------------------------------------------------------------------------
 * ESCOLHAS
 * ---------------------------------------------------------------------------
 * AES-256-GCM: alem de cifrar, autentica. Um valor adulterado no banco falha
 * na verificacao da tag em vez de decifrar em lixo silencioso.
 *
 * O IV e sorteado a cada gravacao e viaja junto do texto cifrado. Reaproveitar
 * IV em GCM quebra a cifra por completo, entao ele nunca e derivado nem fixo.
 *
 * Formato guardado:  enc:v1:<base64(iv|tag|ciphertext)>
 * O prefixo permite reconhecer o que ja esta cifrado e, no futuro, trocar de
 * algoritmo sem ambiguidade.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private const CIPHER = 'aes-256-gcm';

    private const IV_LEN = 12;

    private const TAG_LEN = 16;

    /**
     * Cifra um valor. String vazia continua vazia - nao ha segredo a proteger,
     * e cifrar o vazio so produziria ruido no banco.
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $key = self::key();
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new \RuntimeException('Falha ao cifrar o valor.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decifra. Valor sem o prefixo e devolvido como veio: e o caso de uma
     * linha gravada antes desta funcionalidade, ou de alguem que editou o
     * banco na mao. Melhor funcionar do que quebrar a tela de configuracao.
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }

        $raw = base64_decode(substr($value, \strlen(self::PREFIX)), true);

        if ($raw === false || \strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Valor cifrado corrompido.');
        }

        $iv     = substr($raw, 0, self::IV_LEN);
        $tag    = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), \OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            // Acontece quando a APP_KEY mudou. Dizer isso e mais util do que
            // "erro ao decifrar": o operador precisa regravar os segredos.
            throw new \RuntimeException(
                'Nao foi possivel decifrar o valor. A APP_KEY mudou? Regrave a senha/token na tela de Avisos.'
            );
        }

        return $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** Chave binaria de 32 bytes derivada do APP_KEY. */
    private static function key(): string
    {
        $appKey = (string) Config::get('app.key', '');

        if ($appKey === '') {
            throw new \RuntimeException(
                'APP_KEY nao configurada. Rode: php bin/console.php key:generate'
            );
        }

        $decoded = base64_decode($appKey, true);

        // O console gera base64 de 32 bytes. Uma chave colocada na mao pode
        // vir em qualquer formato - o hash normaliza para 32 bytes sem
        // recusar o valor do operador.
        if ($decoded === false || \strlen($decoded) !== 32) {
            return hash('sha256', $appKey, true);
        }

        return $decoded;
    }
}
