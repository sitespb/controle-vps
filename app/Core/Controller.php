<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AuthService;

/**
 * Base dos controllers: atalhos para view, JSON, redirect e validacao.
 */
abstract class Controller
{
    /**
     * Renderiza uma view dentro do layout do painel.
     *
     * @param array<string,mixed> $data
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        $data += [
            'title'       => Config::get('app.name', 'Controle VPS'),
            'currentUser' => AuthService::user(),
            'flash'       => Session::pullFlash(),
            'errors'      => Session::pullErrors(),
            'old'         => Session::pullOldInput(),
            'activeNav'   => '',
        ];

        // Estado geral exibido na topbar. Memoizado no service, entao nao
        // custa consulta extra quando a pagina ja pediu o mesmo dado.
        if ($layout === 'layouts/app' && !isset($data['overallStatus'])) {
            try {
                $data['overallStatus'] = \App\Services\MonitoringService::overallStatus();
            } catch (\Throwable) {
                // Banco indisponivel nao pode impedir a renderizacao da pagina.
                $data['overallStatus'] = null;
            }
        }

        return Response::html(View::render($view, $data, $layout));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function apiOk(mixed $data = null, int $status = 200): Response
    {
        return Response::apiOk($data, $status);
    }

    protected function apiError(string $message, int $status = 400, string $code = '', array $details = []): Response
    {
        return Response::apiError($message, $status, $code, $details);
    }

    /** Redireciona para um caminho interno (aplica o prefixo de instalacao). */
    protected function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect(url($path), $status);
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = $request->header('referer');

        if ($referer !== null && $referer !== '' && $this->isInternalUrl($referer)) {
            return Response::redirect($referer);
        }

        return $this->redirect($fallback);
    }

    private function isInternalUrl(string $url): bool
    {
        $appHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        $host    = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === $appHost;
    }

    /**
     * Valida os dados e, em caso de erro, volta ao formulario com mensagens
     * (HTML) ou lanca 422 (JSON).
     *
     * @param  array<string,string> $rules
     * @param  array<string,string> $labels
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        $validator = Validator::make($request->all(), $rules, $labels);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                throw HttpException::validation($validator->errors());
            }

            Session::flashErrors($validator->errors());
            Session::flashInput($request->all());

            throw new ValidationRedirect($request->header('referer') ?? url('/'));
        }

        return $validator->validated();
    }

    /** Interrompe se o usuario logado nao tiver o papel exigido. */
    protected function authorizeRole(string ...$roles): void
    {
        if (!AuthService::hasRole(...$roles)) {
            throw HttpException::forbidden('Seu perfil nao permite executar esta acao.');
        }
    }

    protected function flashSuccess(string $message): void
    {
        Session::flash('success', $message);
    }

    protected function flashError(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function flashWarning(string $message): void
    {
        Session::flash('warning', $message);
    }

    protected function flashInfo(string $message): void
    {
        Session::flash('info', $message);
    }
}
