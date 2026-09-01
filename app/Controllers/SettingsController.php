<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\SecureSetting;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\RetentionService;
use App\Services\SettingsService;
use App\Services\TurnstileService;

/**
 * Configuracoes do sistema (secao 19 do PLAN).
 *
 * Os limites de CPU/RAM/disco/SSL, o intervalo de coleta e a retencao passam
 * a ser editaveis aqui. O que for salvo vale imediatamente para o painel e
 * para os crons.
 */
final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRole('admin');

        $turnstile = SecureSetting::all(SecureSetting::SCOPE_TURNSTILE);

        return $this->view('settings/index', [
            'title'       => 'Configuracoes',
            'activeNav'   => 'settings',
            'groups'      => Setting::grouped(),
            'groupLabels' => SettingsService::groupLabels(),
            'system'      => $this->systemInfo(),
            'tableStats'  => RetentionService::tableStats(),
            'volume'      => RetentionService::volumeSummary(),

            'aba'            => $request->string('aba') === 'recaptcha' ? 'recaptcha' : 'sistema',
            'turnstile'      => $turnstile,
            'turnstileAtivo' => ($turnstile['enabled'] ?? '0') === '1',

            // Booleano, e nao a chave: revelar parte de um segredo nao ajuda a
            // reconhece-lo e so aumenta o que um ombro curioso leva da tela.
            'turnstileHasSecret' => ($turnstile['secret_key'] ?? '') !== '',

            // Dominio sugerido ao cadastrar o widget na Cloudflare.
            'hostname' => (string) (parse_url((string) Config::get('app.url', ''), \PHP_URL_HOST) ?: 'seu-dominio'),
        ]);
    }

    /**
     * Grava a configuracao do Turnstile.
     *
     * Fica neste controller, e nao num proprio, porque para o operador e a
     * mesma tela: aba "Recaptcha" dentro de Configuracoes. Os valores vao para
     * o armazenamento cifrado (SecureSetting), e nao para a tabela `settings`,
     * porque a chave secreta e credencial - e as settings comuns sao cacheadas
     * em arquivo, o que deixaria o segredo em texto claro no disco.
     */
    public function updateTurnstile(Request $request): Response
    {
        $this->authorizeRole('admin');

        $siteKey = trim((string) $request->string('site_key'));
        $secret  = (string) $request->string('secret_key');
        $ligar   = (bool) $request->input('enabled');

        // Ligar sem as chaves produziria uma tela de login com um widget que
        // nunca valida - ninguem entraria, e a causa nao estaria em lugar
        // nenhum. Melhor recusar aqui, com a tela ainda aberta.
        if ($ligar) {
            $secretGravado = SecureSetting::get(SecureSetting::SCOPE_TURNSTILE, 'secret_key');

            if ($siteKey === '' || ($secret === '' && $secretGravado === '')) {
                $this->flashError('Informe a chave do site e a chave secreta antes de ativar a verificação.');

                return $this->redirect('/configuracoes?aba=recaptcha');
            }
        }

        SecureSetting::save(SecureSetting::SCOPE_TURNSTILE, [
            'enabled'    => $ligar ? '1' : '0',
            'site_key'   => $siteKey,
            'secret_key' => $secret,
        ], AuthService::id());

        AuditService::log('settings.turnstile.update', 'Configuração do Turnstile alterada', [
            'context' => ['ativo' => $ligar ? '1' : '0'],
        ]);

        $this->flashSuccess('Configuração do Turnstile salva.');

        return $this->redirect('/configuracoes?aba=recaptcha');
    }

    /** Testa as chaves contra a Cloudflare, sem precisar resolver um captcha. */
    public function testTurnstile(Request $request): Response
    {
        $this->authorizeRole('admin');

        $resultado = TurnstileService::testKeys();

        AuditService::log('settings.turnstile.test', 'Teste das chaves do Turnstile', [
            'context' => ['resultado' => $resultado['ok'] ? 'ok' : 'falha'],
        ]);

        // apiOk mesmo quando o teste falha: do ponto de vista do HTTP a
        // requisicao funcionou - quem recusou foi a Cloudflare. Ver o mesmo
        // raciocinio em NotifyController::testEmail().
        return $this->apiOk($resultado);
    }

    public function update(Request $request): Response
    {
        $this->authorizeRole('admin');

        /** @var array<string,string> $input */
        $input = $request->input('settings', []);

        if (!\is_array($input) || $input === []) {
            $this->flashWarning('Nenhuma alteração enviada.');

            return $this->redirect('/configuracoes');
        }

        // Filtra para strings simples - nada de array aninhado vindo do POST.
        $input = array_filter($input, static fn ($v): bool => \is_scalar($v));
        $input = array_map(static fn ($v): string => trim((string) $v), $input);

        // Coerencia entre atencao e critico antes de gravar qualquer coisa.
        $coherence = SettingsService::checkCoherence($input);

        if ($coherence !== []) {
            Session::flashErrors($coherence);
            $this->flashError('Corrija os limites destacados. O valor crítico deve ser mais severo que o de atenção.');

            return $this->redirect('/configuracoes');
        }

        $result = SettingsService::updateMany($input, AuthService::id());

        if ($result['errors'] !== []) {
            Session::flashErrors($result['errors']);
            $this->flashError('Alguns valores não foram aceitos. Verifique os campos destacados.');

            return $this->redirect('/configuracoes');
        }

        if ($result['updated'] === 0) {
            $this->flashInfo('Nenhum valor foi alterado.');
        } else {
            $this->flashSuccess(sprintf('%d configuração(oes) atualizada(s).', $result['updated']));
        }

        return $this->redirect('/configuracoes');
    }

    /** @return array<string,string> */
    private function systemInfo(): array
    {
        $dbVersion = 'indisponivel';

        try {
            $dbVersion = (string) Database::scalar('SELECT VERSION()');
        } catch (\Throwable) {
            // Mantem "indisponivel" - a tela nao pode quebrar por isso.
        }

        return [
            'Versão da aplicacao' => App::VERSION,
            'Ambiente'            => (string) Config::get('app.env', 'production'),
            'Modo debug'          => Config::get('app.debug', false) ? 'ativo' : 'desativado',
            'URL base'            => (string) Config::get('app.url', ''),
            'Fuso horário'        => (string) Config::get('app.timezone', ''),
            'PHP'                 => \PHP_VERSION,
            'Banco de dados'      => $dbVersion,
            'Intervalo do agente' => sprintf('%d s', (int) Config::get('monitoring.agent_interval', 300)),
            'Tolerancia offline'  => sprintf('%d s', (int) Config::get('monitoring.server_offline_after', 600)),
        ];
    }
}
