<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Models\Site;

/**
 * Decide se um aviso deve sair, por onde, e o envia.
 *
 * ---------------------------------------------------------------------------
 * AS QUATRO PORTAS QUE UM AVISO PRECISA ATRAVESSAR
 * ---------------------------------------------------------------------------
 *   1. o canal esta ligado e configurado?
 *   2. o operador marcou "ciente" neste dominio?      -> silencio
 *   3. este dominio ja foi avisado dentro da janela?  -> silencio
 *   4. o canal ja estourou o teto da hora?            -> silencio
 *
 * As portas 3 e 4 existem por motivos diferentes e nenhuma substitui a outra:
 * a janela por dominio protege contra um site que oscila; o teto por hora
 * protege contra a queda de um servidor inteiro, quando dezenas de dominios
 * ficam offline no mesmo ciclo.
 *
 * Tudo que e barrado vira uma linha `skipped` no log. Silencio sem registro
 * seria indistinguivel de bug - e a primeira pergunta de quem nao recebeu o
 * aviso e justamente "por que nao chegou?".
 *
 * ---------------------------------------------------------------------------
 * FALHA NUNCA PROPAGA
 * ---------------------------------------------------------------------------
 * Este servico e chamado de dentro da ingestao de sites. Um SMTP fora do ar
 * NAO pode derrubar a coleta: perder a metrica por causa do aviso seria trocar
 * um problema por um pior. Todo caminho publico e envolvido em try/catch.
 */
final class NotificationService
{
    /** Janela por dominio, em minutos. Um aviso a cada 6 horas por dominio. */
    private const WINDOW_MINUTES = 360;

    /** Teto por canal, por hora. Protege o provedor numa queda geral. */
    private const HOURLY_CAP = 20;

    /**
     * Avisa que um site caiu, pelos canais ativos.
     *
     * @return array<string,string> canal => resultado (sent|skipped|failed)
     */
    public static function siteOffline(int $siteId, string $domain, ?int $httpStatus, ?string $error): array
    {
        $result = [];

        foreach ([NotificationSetting::CHANNEL_EMAIL, NotificationSetting::CHANNEL_WHATSAPP] as $channel) {
            try {
                $result[$channel] = self::dispatch($channel, $siteId, $domain, $httpStatus, $error);
            } catch (\Throwable $e) {
                $result[$channel] = 'failed';

                Logger::error('Falha ao processar aviso de site offline: ' . $e->getMessage(), [
                    'channel' => $channel,
                    'domain'  => $domain,
                ]);
            }
        }

        return $result;
    }

    private static function dispatch(
        string $channel,
        int $siteId,
        string $domain,
        ?int $httpStatus,
        ?string $error
    ): string {
        if (!NotificationSetting::isEnabled($channel)) {
            return 'disabled';
        }

        $recipients = NotificationSetting::recipients($channel);

        if ($recipients === []) {
            return 'disabled';
        }

        // Porta 2 - o operador ja sabe deste dominio.
        if (Site::isNotifyMuted($siteId)) {
            NotificationLog::record([
                'channel'   => $channel,
                'event'     => NotificationLog::EVENT_SITE_OFFLINE,
                'site_id'   => $siteId,
                'domain'    => $domain,
                'recipient' => '-',
                'status'    => NotificationLog::STATUS_SKIPPED,
                'error'     => 'Dominio marcado como ciente pelo operador.',
            ]);

            return 'skipped';
        }

        // Porta 3 - ja avisamos ha pouco.
        if (NotificationLog::sentRecentlyFor($channel, $domain, self::windowMinutes())) {
            NotificationLog::record([
                'channel'   => $channel,
                'event'     => NotificationLog::EVENT_SITE_OFFLINE,
                'site_id'   => $siteId,
                'domain'    => $domain,
                'recipient' => '-',
                'status'    => NotificationLog::STATUS_SKIPPED,
                'error'     => sprintf('Ja avisado nas ultimas %d hora(s).', (int) round(self::windowMinutes() / 60)),
            ]);

            return 'skipped';
        }

        // Porta 4 - teto da hora.
        if (NotificationLog::sentInLastHour($channel) >= self::hourlyCap()) {
            NotificationLog::record([
                'channel'   => $channel,
                'event'     => NotificationLog::EVENT_SITE_OFFLINE,
                'site_id'   => $siteId,
                'domain'    => $domain,
                'recipient' => '-',
                'status'    => NotificationLog::STATUS_SKIPPED,
                'error'     => sprintf('Teto de %d mensagens por hora atingido.', self::hourlyCap()),
            ]);

            Logger::warning('Teto horario de avisos atingido: mensagens seguintes serao suprimidas.', [
                'channel' => $channel,
            ]);

            return 'skipped';
        }

        $message = self::offlineMessage($domain, $httpStatus, $error);
        $enviou  = false;

        foreach ($recipients as $recipient) {
            $outcome = $channel === NotificationSetting::CHANNEL_EMAIL
                ? self::sendEmail($recipient, $message)
                : self::sendWhatsApp($recipient, $message);

            NotificationLog::record([
                'channel'   => $channel,
                'event'     => NotificationLog::EVENT_SITE_OFFLINE,
                'site_id'   => $siteId,
                'domain'    => $domain,
                'recipient' => $recipient,
                'status'    => $outcome['ok'] ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
                'error'     => $outcome['error'],
            ]);

            $enviou = $enviou || $outcome['ok'];
        }

        return $enviou ? 'sent' : 'failed';
    }

    // -----------------------------------------------------------------
    // Testes acionados pela tela de Avisos
    // -----------------------------------------------------------------

    /** @return array{ok:bool,error:?string,detail:array<int,string>} */
    public static function testEmail(string $to): array
    {
        $config = NotificationSetting::all(NotificationSetting::CHANNEL_EMAIL);

        $assunto = '[Controle VPS] Teste de configuracao';
        $texto   = "Este e um e-mail de teste do Controle VPS.\n\n"
            . "Se voce recebeu esta mensagem, o envio de avisos por e-mail esta funcionando.\n\n"
            . 'Enviado em ' . format_datetime(now_string()) . '.';

        $resultado = Mailer::fromConfig($config)->send($to, $assunto, $texto, self::htmlWrapper(
            'Teste de configuracao',
            '<p>Este e um e-mail de teste do <strong>Controle VPS</strong>.</p>'
            . '<p>Se voce recebeu esta mensagem, o envio de avisos por e-mail esta funcionando.</p>'
        ));

        NotificationLog::record([
            'channel'   => NotificationSetting::CHANNEL_EMAIL,
            'event'     => NotificationLog::EVENT_TEST,
            'recipient' => $to,
            'status'    => $resultado['ok'] ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
            'error'     => $resultado['error'],
        ]);

        return [
            'ok'     => $resultado['ok'],
            'error'  => $resultado['error'],
            'detail' => $resultado['transcript'],
        ];
    }

    /**
     * Testa o WhatsApp. Sem numero, apenas confere o estado da instancia -
     * util para validar token e nome sem gastar mensagem.
     *
     * @return array{ok:bool,error:?string,detail:array<int,string>}
     */
    public static function testWhatsApp(?string $number = null): array
    {
        $config = NotificationSetting::all(NotificationSetting::CHANNEL_WHATSAPP);
        $client = RyzeApiClient::fromConfig($config);

        $estado = $client->instanceState();

        if (!$estado['ok']) {
            return ['ok' => false, 'error' => $estado['error'], 'detail' => []];
        }

        $detail = ['Estado da instancia: ' . ($estado['state'] ?? 'desconhecido')];

        $configurada = (string) ($config['instance'] ?? '');
        $real        = (string) ($estado['name'] ?? '');

        // O filtro por nome da RyzeAPI e ignorado quando se usa TokenInstance:
        // ela devolve a instancia dona do token, com qualquer nome pedido. Sem
        // esta comparacao, um nome errado passaria como "conectado" aqui e so
        // falharia no primeiro envio real, com um "Instance not found" que nao
        // aponta para o campo errado.
        if ($real !== '' && strcasecmp($real, $configurada) !== 0) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'O token pertence a instancia "%s", mas voce configurou "%s". '
                    . 'Corrija o nome da instancia para "%s" e salve.',
                    $real,
                    $configurada,
                    $real
                ),
                'detail' => array_merge($detail, ['Nome real da instancia: ' . $real]),
            ];
        }

        if ($real !== '') {
            $detail[] = 'Nome da instancia confere: ' . $real;
        }

        if (!$estado['connected']) {
            return [
                'ok'     => false,
                'error'  => 'A instancia existe, mas nao esta conectada ao WhatsApp (estado: '
                    . ($estado['state'] ?? 'desconhecido') . '). Leia o QR Code na RyzeAPI.',
                'detail' => $detail,
            ];
        }

        if ($number === null || trim($number) === '') {
            $detail[] = 'Token e instancia validos. Informe um numero para receber a mensagem de teste.';

            return ['ok' => true, 'error' => null, 'detail' => $detail];
        }

        $envio = $client->sendText(
            $number,
            "*Controle VPS*\n\nTeste de configuracao. Se voce recebeu esta mensagem, "
            . 'os avisos por WhatsApp estao funcionando.'
        );

        NotificationLog::record([
            'channel'   => NotificationSetting::CHANNEL_WHATSAPP,
            'event'     => NotificationLog::EVENT_TEST,
            'recipient' => $number,
            'status'    => $envio['ok'] ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
            'error'     => $envio['error'],
        ]);

        if ($envio['ok']) {
            $detail[] = 'Mensagem enviada' . ($envio['message_id'] !== null ? ' (id ' . $envio['message_id'] . ')' : '') . '.';
        }

        return ['ok' => $envio['ok'], 'error' => $envio['error'], 'detail' => $detail];
    }

    // -----------------------------------------------------------------
    // Envio
    // -----------------------------------------------------------------

    /**
     * @param  array{subject:string,text:string,html:string} $message
     * @return array{ok:bool,error:?string}
     */
    private static function sendEmail(string $to, array $message): array
    {
        $config    = NotificationSetting::all(NotificationSetting::CHANNEL_EMAIL);
        $resultado = Mailer::fromConfig($config)->send($to, $message['subject'], $message['text'], $message['html']);

        return ['ok' => $resultado['ok'], 'error' => $resultado['error']];
    }

    /**
     * @param  array{subject:string,text:string,html:string} $message
     * @return array{ok:bool,error:?string}
     */
    private static function sendWhatsApp(string $number, array $message): array
    {
        $config = NotificationSetting::all(NotificationSetting::CHANNEL_WHATSAPP);
        $envio  = RyzeApiClient::fromConfig($config)->sendText($number, $message['text']);

        return ['ok' => $envio['ok'], 'error' => $envio['error']];
    }

    // -----------------------------------------------------------------
    // Conteudo
    // -----------------------------------------------------------------

    /** @return array{subject:string,text:string,html:string} */
    private static function offlineMessage(string $domain, ?int $httpStatus, ?string $error): array
    {
        $motivo = $httpStatus !== null
            ? sprintf('retornou HTTP %d', $httpStatus)
            : sprintf('nao respondeu (%s)', $error ?? 'sem resposta');

        $quando = format_datetime(now_string());
        $url    = rtrim((string) Config::get('app.url', ''), '/') . '/sites';

        $texto = "*{$domain} esta fora do ar*\n\n"
            . "O site {$motivo}.\n"
            . "Detectado em {$quando}.\n\n"
            . "Painel: {$url}\n\n"
            . 'Para parar de receber avisos deste dominio, marque "Ciente" na tela de Sites.';

        $html = self::htmlWrapper(
            $domain . ' esta fora do ar',
            sprintf(
                '<p>O site <strong>%s</strong> %s.</p><p>Detectado em %s.</p>'
                . '<p><a href="%s" style="color:#dc2626">Abrir o painel</a></p>'
                . '<p style="color:#6b7280;font-size:13px">Para parar de receber avisos deste dominio, '
                . 'marque <strong>Ciente</strong> na tela de Sites.</p>',
                e($domain),
                e($motivo),
                e($quando),
                e($url)
            )
        );

        return [
            'subject' => sprintf('[Controle VPS] %s esta fora do ar', $domain),
            'text'    => $texto,
            'html'    => $html,
        ];
    }

    /**
     * HTML deliberadamente simples: tabela nenhuma, CSS embutido, sem imagem.
     * Cliente de e-mail e terreno hostil, e um aviso de indisponibilidade
     * precisa ser legivel ate no relogio.
     */
    private static function htmlWrapper(string $title, string $body): string
    {
        return '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:24px;background:#f9fafb;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;'
            . 'border-radius:12px;padding:24px">'
            . '<h1 style="margin:0 0 16px;font-size:18px">' . e($title) . '</h1>'
            . $body
            . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">'
            . '<p style="margin:0;color:#9ca3af;font-size:12px">Controle VPS - aviso automatico. Nao responda este e-mail.</p>'
            . '</div></body></html>';
    }

    // -----------------------------------------------------------------
    // Limites (configuraveis por settings, com o padrao acordado)
    // -----------------------------------------------------------------

    private static function windowMinutes(): int
    {
        return max(1, (int) Config::get('monitoring.notify.window_minutes', self::WINDOW_MINUTES));
    }

    private static function hourlyCap(): int
    {
        return max(1, (int) Config::get('monitoring.notify.hourly_cap', self::HOURLY_CAP));
    }
}
