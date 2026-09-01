<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\TurnstileService;

/**
 * Login e logout do painel (secoes 23 e 33 do PLAN).
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', [
            'title'          => 'Entrar',
            'activeNav'      => 'login',
            'turnstileKey'   => TurnstileService::isEnabled() ? TurnstileService::siteKey() : '',
        ], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email'    => 'required|email|max:190',
                'password' => 'required|string|max:255',
            ],
            ['email' => 'e-mail', 'password' => 'senha']
        );

        if ($validator->fails()) {
            Session::flashErrors($validator->errors());
            Session::flashInput(['email' => $request->string('email')]);
            $this->flashError('Verifique os dados informados.');

            return $this->redirect('/login');
        }

        // O captcha e verificado ANTES de tocar na senha: um bot que nem
        // passou pelo widget nao deve nem consumir uma tentativa da contagem
        // de forca bruta daquele e-mail - senao ele bloqueia a conta de um
        // usuario legitimo so martelando o formulario.
        $captcha = TurnstileService::verify(
            (string) $request->input(TurnstileService::FIELD, ''),
            $request->ip()
        );

        if (!$captcha['ok']) {
            Session::flashInput(['email' => $request->string('email')]);
            $this->flashError((string) $captcha['error']);

            return $this->redirect('/login');
        }

        $result = AuthService::attempt(
            $request->string('email'),
            (string) $request->input('password', ''),
            $request->ip(),
            $request->userAgent()
        );

        if (!$result['ok']) {
            Session::flashInput(['email' => $request->string('email')]);
            $this->flashError($result['message']);

            $remaining = AuthService::remainingAttempts($request->string('email'), $request->ip());

            if ($remaining > 0 && $remaining <= 2) {
                $this->flashWarning(sprintf(
                    'Restam %d tentativa(s) antes do bloqueio temporário.',
                    $remaining
                ));
            }

            return $this->redirect('/login');
        }

        $this->flashSuccess($result['message']);

        // Retoma o destino que o usuario tentou acessar antes do login.
        $intended = Session::get('_intended');
        Session::forget('_intended');

        if (\is_string($intended) && $intended !== '' && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            return $this->redirect($intended);
        }

        return $this->redirect('/');
    }

    public function logout(Request $request): Response
    {
        AuthService::logout();

        // Sessao nova apenas para carregar a mensagem de despedida.
        Session::start();
        Session::flash('info', 'Você saiu do painel.');

        return $this->redirect('/login');
    }
}
