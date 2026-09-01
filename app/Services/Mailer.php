<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Cliente SMTP proprio.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ESCREVER UM EM VEZ DE USAR BIBLIOTECA
 * ---------------------------------------------------------------------------
 * O projeto nao usa Composer (ver PROGRESS.md, decisao nº 1): tem autoloader
 * proprio e zero dependencia em runtime. Puxar PHPMailer so para enviar um
 * texto simples inverteria essa decisao inteira. O que precisamos e um
 * subconjunto pequeno e bem definido de SMTP: conectar, autenticar, mandar uma
 * mensagem de texto. Isso cabe em uma classe.
 *
 * A funcao mail() do PHP tambem foi descartada: ela depende de um MTA local
 * configurado, que num VPS de painel normalmente nao existe - e quando existe,
 * o e-mail cai em spam por falta de SPF/DKIM do dominio. Enviar autenticado
 * pelo SMTP do provedor (Gmail, por exemplo) e o que realmente chega.
 *
 * ---------------------------------------------------------------------------
 * O QUE ESTA CLASSE FAZ E NAO FAZ
 * ---------------------------------------------------------------------------
 * Faz:      EHLO, STARTTLS, AUTH LOGIN / PLAIN, MAIL FROM, RCPT TO, DATA.
 *           Texto puro e HTML (multipart/alternative), UTF-8, multiplos
 *           destinatarios.
 * Nao faz:  anexos, imagens embutidas, filas. Um aviso de site fora do ar nao
 *           precisa de nada disso, e cada recurso a mais e mais superficie
 *           para dar errado no meio da noite.
 */
final class Mailer
{
    private const CRLF = "\r\n";

    /** Falhar rapido: o painel nao pode travar esperando um SMTP mudo. */
    private const TIMEOUT = 15;

    /** @var resource|null */
    private $socket = null;

    /** @var array<int,string> Dialogo completo, para diagnostico na tela de teste. */
    private array $transcript = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $security,   // 'tls' (STARTTLS), 'ssl' ou 'none'
        private string $username,
        private string $password,
        private string $fromEmail,
        private string $fromName = 'Controle VPS'
    ) {
    }

    /** @param array<string,string> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['smtp_host'] ?? ''),
            (int) ($config['smtp_port'] ?? 587),
            (string) ($config['smtp_security'] ?? 'tls'),
            (string) ($config['smtp_user'] ?? ''),
            (string) ($config['smtp_password'] ?? ''),
            (string) ($config['from_email'] ?? ($config['smtp_user'] ?? '')),
            (string) ($config['from_name'] ?? 'Controle VPS')
        );
    }

    /**
     * Envia para um destinatario.
     *
     * @return array{ok:bool,error:?string,transcript:array<int,string>}
     */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): array
    {
        $this->transcript = [];

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->envelope($to);
            $this->data($to, $subject, $textBody, $htmlBody);
            $this->command('QUIT', [221]);

            return ['ok' => true, 'error' => null, 'transcript' => $this->transcript];
        } catch (\Throwable $e) {
            Logger::error('Falha no envio de e-mail: ' . $e->getMessage(), [
                'host' => $this->host,
                'to'   => $to,
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'transcript' => $this->transcript];
        } finally {
            $this->close();
        }
    }

    // -----------------------------------------------------------------
    // Conexao
    // -----------------------------------------------------------------

    private function connect(): void
    {
        if ($this->host === '') {
            throw new \RuntimeException('Servidor SMTP não configurado.');
        }

        // Porta 465 fala TLS desde o primeiro byte; 587 comeca em texto claro
        // e sobe para TLS com STARTTLS depois do EHLO.
        $prefix = $this->security === 'ssl' ? 'ssl://' : '';
        $errno  = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            self::TIMEOUT,
            \STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $this->host]])
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf(
                'Não foi possível conectar em %s:%d - %s',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : "erro {$errno}"
            ));
        }

        $this->socket = $socket;
        stream_set_timeout($socket, self::TIMEOUT);

        $this->expect([220]);
    }

    private function handshake(): void
    {
        $ehlo = $this->command('EHLO ' . $this->clientName(), [250]);

        if ($this->security !== 'tls') {
            return;
        }

        if (stripos($ehlo, 'STARTTLS') === false) {
            throw new \RuntimeException(
                'O servidor não anunciou STARTTLS. Se ele usa TLS direto, mude a segurança para SSL (porta 465).'
            );
        }

        $this->command('STARTTLS', [220]);

        $ok = @stream_socket_enable_crypto(
            $this->socket,
            true,
            \STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($ok !== true) {
            throw new \RuntimeException('Falha ao iniciar a camada TLS (STARTTLS).');
        }

        // Depois do STARTTLS o EHLO precisa ser repetido: a lista de recursos
        // anunciada antes da criptografia nao vale mais.
        $this->command('EHLO ' . $this->clientName(), [250]);
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return; // relay interno sem autenticacao
        }

        // AUTH LOGIN e o que Gmail, Outlook e a maioria dos provedores aceitam.
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);

        try {
            $this->command(base64_encode($this->password), [235]);
        } catch (\Throwable $e) {
            // O erro cru do Gmail aponta para uma pagina generica. Dizer o que
            // realmente resolve poupa uma hora de tentativa e erro.
            throw new \RuntimeException(
                'Autenticação recusada. No Gmail é obrigatório usar uma "Senha de app" '
                . '(myaccount.google.com > Segurança > Senhas de app), não a senha normal da conta. '
                . 'Resposta do servidor: ' . $e->getMessage()
            );
        }
    }

    private function envelope(string $to): void
    {
        $from = $this->fromEmail !== '' ? $this->fromEmail : $this->username;

        $this->command('MAIL FROM:<' . $from . '>', [250]);
        $this->command('RCPT TO:<' . $to . '>', [250, 251]);
    }

    private function data(string $to, string $subject, string $text, ?string $html): void
    {
        $this->command('DATA', [354]);

        $from     = $this->fromEmail !== '' ? $this->fromEmail : $this->username;
        $boundary = 'cvps-' . bin2hex(random_bytes(8));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->clientName() . '>',
            'MIME-Version: 1.0',
            // Marca o e-mail como automatico: evita resposta automatica de
            // ferias criando laco com o proprio painel.
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body      = $text;
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            $body = implode(self::CRLF, [
                '--' . $boundary,
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $text,
                '',
                '--' . $boundary,
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $html,
                '',
                '--' . $boundary . '--',
            ]);
        }

        $message = implode(self::CRLF, $headers) . self::CRLF . self::CRLF . $this->dotStuff($body);

        $this->write($message . self::CRLF . '.' . self::CRLF);
        $this->expect([250]);
    }

    // -----------------------------------------------------------------
    // Primitivas
    // -----------------------------------------------------------------

    private function command(string $command, array $expected): string
    {
        $this->write($command . self::CRLF);

        // A senha nunca entra no transcript: ele e exibido na tela de teste.
        $this->transcript[] = '> ' . (
            preg_match('/^(AUTH LOGIN|[A-Za-z0-9+\/=]{16,})$/', $command) === 1
                ? $command === 'AUTH LOGIN' ? $command : '<credencial omitida>'
                : $command
        );

        return $this->expect($expected);
    }

    /** @param array<int,int> $expected */
    private function expect(array $expected): string
    {
        $response = $this->readResponse();
        $code     = (int) substr($response, 0, 3);

        $this->transcript[] = '< ' . trim($response);

        if (!\in_array($code, $expected, true)) {
            throw new \RuntimeException(trim($response));
        }

        return $response;
    }

    /**
     * Uma resposta SMTP pode vir em varias linhas: as intermediarias trazem
     * hifen apos o codigo (`250-STARTTLS`), a ultima traz espaco (`250 OK`).
     * Parar na primeira linha deixaria o resto no buffer e dessincronizaria
     * todo o dialogo seguinte.
     */
    private function readResponse(): string
    {
        $data = '';

        while (($line = fgets($this->socket, 1024)) !== false) {
            $data .= $line;

            if (\strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        if ($data === '') {
            $meta = stream_get_meta_data($this->socket);

            throw new \RuntimeException(
                ($meta['timed_out'] ?? false)
                    ? 'O servidor SMTP não respondeu no tempo limite.'
                    : 'Conexão encerrada pelo servidor SMTP.'
            );
        }

        return $data;
    }

    private function write(string $data): void
    {
        if ($this->socket === null || @fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('Falha ao escrever no socket SMTP.');
        }
    }

    private function close(): void
    {
        if (\is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * Uma linha do corpo comecando com ponto encerraria o DATA no meio da
     * mensagem. O protocolo manda duplicar esse ponto.
     */
    private function dotStuff(string $body): string
    {
        // UMA passada de regex, e nao str_replace encadeado.
        //
        // str_replace(["\r\n", "\r", "\n"], "\r\n", ...) parece equivalente mas
        // nao e: ele aplica as buscas EM SEQUENCIA sobre o resultado anterior,
        // entao o "\r" de um "\r\n" ja correto vira "\r\n" de novo e cada
        // quebra de linha acaba duplicada. No corpo MIME isso insere uma linha
        // em branco entre os cabecalhos da parte - e o cliente de e-mail passa
        // a ler o resto como texto solto. A alternancia do regex casa cada
        // terminador uma unica vez.
        $body = preg_replace('/\r\n|\r|\n/', self::CRLF, $body) ?? $body;

        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /** Assunto e nome com acento precisam de MIME encoded-word. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function clientName(): string
    {
        $host = parse_url((string) \App\Core\Config::get('app.url', ''), \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? $host : 'controle-vps.local';
    }
}
