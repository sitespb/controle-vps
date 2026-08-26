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
use App\Models\Setting;
use App\Services\AuthService;
use App\Services\RetentionService;
use App\Services\SettingsService;

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

        return $this->view('settings/index', [
            'title'       => 'Configuracoes do sistema',
            'activeNav'   => 'settings',
            'groups'      => Setting::grouped(),
            'groupLabels' => SettingsService::groupLabels(),
            'system'      => $this->systemInfo(),
            'tableStats'  => RetentionService::tableStats(),
            'volume'      => RetentionService::volumeSummary(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorizeRole('admin');

        /** @var array<string,string> $input */
        $input = $request->input('settings', []);

        if (!\is_array($input) || $input === []) {
            $this->flashWarning('Nenhuma alteracao enviada.');

            return $this->redirect('/configuracoes');
        }

        // Filtra para strings simples - nada de array aninhado vindo do POST.
        $input = array_filter($input, static fn ($v): bool => \is_scalar($v));
        $input = array_map(static fn ($v): string => trim((string) $v), $input);

        // Coerencia entre atencao e critico antes de gravar qualquer coisa.
        $coherence = SettingsService::checkCoherence($input);

        if ($coherence !== []) {
            Session::flashErrors($coherence);
            $this->flashError('Corrija os limites destacados. O valor critico deve ser mais severo que o de atencao.');

            return $this->redirect('/configuracoes');
        }

        $result = SettingsService::updateMany($input, AuthService::id());

        if ($result['errors'] !== []) {
            Session::flashErrors($result['errors']);
            $this->flashError('Alguns valores nao foram aceitos. Verifique os campos destacados.');

            return $this->redirect('/configuracoes');
        }

        if ($result['updated'] === 0) {
            $this->flashInfo('Nenhum valor foi alterado.');
        } else {
            $this->flashSuccess(sprintf('%d configuracao(oes) atualizada(s).', $result['updated']));
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
            'Versao da aplicacao' => App::VERSION,
            'Ambiente'            => (string) Config::get('app.env', 'production'),
            'Modo debug'          => Config::get('app.debug', false) ? 'ativo' : 'desativado',
            'URL base'            => (string) Config::get('app.url', ''),
            'Fuso horario'        => (string) Config::get('app.timezone', ''),
            'PHP'                 => \PHP_VERSION,
            'Banco de dados'      => $dbVersion,
            'Intervalo do agente' => sprintf('%d s', (int) Config::get('monitoring.agent_interval', 300)),
            'Tolerancia offline'  => sprintf('%d s', (int) Config::get('monitoring.server_offline_after', 600)),
        ];
    }
}
