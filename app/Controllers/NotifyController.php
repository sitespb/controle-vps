<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\NotificationService;

/**
 * Avisos ao administrador - configuracao dos canais e testes.
 *
 * Duas abas, um controller: e-mail (SMTP) e WhatsApp (RyzeAPI). O que muda
 * entre eles e o conjunto de campos e o cliente usado; o fluxo - validar,
 * gravar, testar, registrar na auditoria - e o mesmo.
 *
 * SEGREDOS: a senha do SMTP e o token da RyzeAPI nunca voltam para a tela em
 * texto puro. O formulario recebe uma mascara; enviar o campo vazio significa
 * "mantenha o que esta gravado". Assim salvar as outras configuracoes nao
 * apaga a credencial por descuido.
 */
final class NotifyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRole('admin');

        $email    = NotificationSetting::all(NotificationSetting::CHANNEL_EMAIL);
        $whatsapp = NotificationSetting::all(NotificationSetting::CHANNEL_WHATSAPP);

        return $this->view('notify/index', [
            'title'     => 'Avisos',
            'activeNav' => 'notify',
            'email'     => $email,
            'whatsapp'  => $whatsapp,

            // Booleano, e nao a mascara do valor: revelar qualquer parte de
            // uma senha nao ajuda o operador a reconhece-la e so aumenta o
            // que um ombro curioso leva da tela. Saber que existe basta.
            'emailHasSecret' => ($email['smtp_password'] ?? '') !== '',
            'whatsHasSecret' => ($whatsapp['token'] ?? '') !== '',

            'log'            => NotificationLog::recent(15),
            'aba'            => $request->string('aba') === 'whatsapp' ? 'whatsapp' : 'email',
            'windowHours'    => (int) round((int) Config::get('monitoring.notify.window_minutes', 360) / 60),
            'hourlyCap'      => (int) Config::get('monitoring.notify.hourly_cap', 20),
        ]);
    }

    public function updateEmail(Request $request): Response
    {
        $this->authorizeRole('admin');

        $values = [
            'enabled'       => $request->input('enabled') ? '1' : '0',
            'smtp_host'     => trim((string) $request->string('smtp_host')),
            'smtp_port'     => (string) max(1, min(65535, (int) $request->string('smtp_port', '587'))),
            'smtp_security' => \in_array($request->string('smtp_security'), ['tls', 'ssl', 'none'], true)
                ? (string) $request->string('smtp_security')
                : 'tls',
            'smtp_user'     => trim((string) $request->string('smtp_user')),
            'smtp_password' => (string) $request->string('smtp_password'),
            'from_email'    => trim((string) $request->string('from_email')),
            'from_name'     => trim((string) $request->string('from_name', 'Controle VPS')),
            'recipients'    => trim((string) $request->string('recipients')),
        ];

        // Ligar o canal sem servidor ou sem destinatario produziria falha
        // silenciosa na primeira queda de site - justamente quando importa.
        if ($values['enabled'] === '1') {
            if ($values['smtp_host'] === '') {
                $this->flashError('Informe o servidor SMTP antes de ativar os avisos por e-mail.');

                return $this->redirect('/avisos?aba=email');
            }

            if (NotificationSetting::recipients(NotificationSetting::CHANNEL_EMAIL) === []
                && $this->parseCount($values['recipients'], 'email') === 0
            ) {
                $this->flashError('Informe ao menos um destinatario valido antes de ativar os avisos por e-mail.');

                return $this->redirect('/avisos?aba=email');
            }
        }

        NotificationSetting::save(
            NotificationSetting::CHANNEL_EMAIL,
            $values,
            AuthService::id()
        );

        AuditService::log('notify.email.update', 'Configuracao de avisos por e-mail alterada', [
            'context' => ['enabled' => $values['enabled'], 'host' => $values['smtp_host']],
        ]);

        $this->flashSuccess('Configuracao de e-mail salva.');

        return $this->redirect('/avisos?aba=email');
    }

    public function updateWhatsapp(Request $request): Response
    {
        $this->authorizeRole('admin');

        $values = [
            'enabled'    => $request->input('enabled') ? '1' : '0',
            'base_url'   => trim((string) $request->string('base_url', 'https://ryzeapi.cloud')),
            'instance'   => trim((string) $request->string('instance')),
            'token'      => (string) $request->string('token'),
            'recipients' => trim((string) $request->string('recipients')),
        ];

        if ($values['enabled'] === '1' && $values['instance'] === '') {
            $this->flashError('Informe o nome da instancia antes de ativar os avisos por WhatsApp.');

            return $this->redirect('/avisos?aba=whatsapp');
        }

        NotificationSetting::save(
            NotificationSetting::CHANNEL_WHATSAPP,
            $values,
            AuthService::id()
        );

        AuditService::log('notify.whatsapp.update', 'Configuracao de avisos por WhatsApp alterada', [
            'context' => ['enabled' => $values['enabled'], 'instance' => $values['instance']],
        ]);

        $this->flashSuccess('Configuracao de WhatsApp salva.');

        return $this->redirect('/avisos?aba=whatsapp');
    }

    /**
     * Teste de e-mail.
     *
     * Responde JSON porque o resultado aparece na propria tela, sem recarregar
     * - e porque o dialogo SMTP completo e util demais para caber num flash.
     */
    public function testEmail(Request $request): Response
    {
        $this->authorizeRole('admin');

        $to = trim((string) $request->string('to'));

        if ($to === '' || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            return $this->apiError('Informe um e-mail valido para o teste.', 422, 'invalid_email');
        }

        $resultado = NotificationService::testEmail($to);

        AuditService::log('notify.email.test', 'Teste de envio de e-mail', [
            'context' => ['destino' => $to, 'resultado' => $resultado['ok'] ? 'ok' : 'falha'],
        ]);

        // apiOk mesmo quando o teste falha, e nao apiError: do ponto de vista
        // do HTTP a requisicao funcionou - o que falhou foi o SMTP la fora. O
        // helper de API do painel trata `ok:false` no envelope como erro de
        // requisicao e substitui a mensagem por um texto generico, engolindo
        // justamente o motivo que o operador precisa ler.
        return $this->apiOk([
            'ok'     => $resultado['ok'],
            'error'  => $resultado['error'],
            'detail' => $resultado['detail'],
        ]);
    }

    public function testWhatsapp(Request $request): Response
    {
        $this->authorizeRole('admin');

        $numero = trim((string) $request->string('to'));

        $resultado = NotificationService::testWhatsApp($numero !== '' ? $numero : null);

        AuditService::log('notify.whatsapp.test', 'Teste de envio de WhatsApp', [
            'context' => [
                'destino'   => $numero !== '' ? $numero : '(so estado da instancia)',
                'resultado' => $resultado['ok'] ? 'ok' : 'falha',
            ],
        ]);

        // Ver o comentario em testEmail(): o resultado do teste viaja dentro
        // de uma resposta bem-sucedida, para que a mensagem real chegue a tela.
        return $this->apiOk([
            'ok'     => $resultado['ok'],
            'error'  => $resultado['error'],
            'detail' => $resultado['detail'],
        ]);
    }

    /** Quantos destinatarios validos ha num texto ainda nao gravado. */
    private function parseCount(string $raw, string $tipo): int
    {
        $partes = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $total  = 0;

        foreach ($partes as $parte) {
            $parte = trim($parte);

            if ($parte === '') {
                continue;
            }

            $valido = $tipo === 'email'
                ? filter_var($parte, \FILTER_VALIDATE_EMAIL) !== false
                : preg_match('/^[0-9]{10,15}$/', preg_replace('/\D/', '', $parte) ?? '') === 1;

            if ($valido) {
                $total++;
            }
        }

        return $total;
    }
}
