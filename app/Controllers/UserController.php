<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthService;

/**
 * Administracao de usuarios (secao 23 do PLAN).
 *
 * Somente administradores acessam. A senha nunca e armazenada em texto puro:
 * apenas password_hash()/password_verify().
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRole('admin');

        return $this->view('users/index', [
            'title'     => 'Usuarios',
            'activeNav' => 'users',
            'users'     => User::listAll(),
            'roles'     => User::roles(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeRole('admin');

        return $this->view('users/create', [
            'title'     => 'Novo usuário',
            'activeNav' => 'users',
            'roles'     => User::roles(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole('admin');

        $data = $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190',
            'password' => 'required|string|min:8|max:255|confirmed',
            'role'     => 'required|in:admin,operator',
            'status'   => 'required|in:active,inactive',
        ], [
            'name'     => 'nome',
            'email'    => 'e-mail',
            'password' => 'senha',
            'role'     => 'perfil',
            'status'   => 'situacao',
        ]);

        if (User::emailExists($data['email'])) {
            Session::flashErrors(['email' => 'Já existe um usuário com este e-mail.']);
            Session::flashInput($request->all());
            $this->flashError('Não foi possível cadastrar o usuário.');

            return $this->redirect('/usuarios/novo');
        }

        $id = User::create([
            'name'          => $data['name'],
            'email'         => mb_strtolower($data['email']),
            'password_hash' => User::hashPassword($data['password']),
            'role'          => $data['role'],
            'status'        => $data['status'],
        ]);

        AuditService::log('user.created', sprintf('Usuário "%s" criado com perfil %s.', $data['name'], $data['role']), [
            'entity_type' => 'user',
            'entity_id'   => $id,
        ]);

        $this->flashSuccess('Usuário cadastrado.');

        return $this->redirect('/usuarios');
    }

    public function edit(Request $request): Response
    {
        $this->authorizeRole('admin');

        $user = User::find($request->routeInt('id'));

        if ($user === null) {
            throw HttpException::notFound('Usuário não encontrado.');
        }

        return $this->view('users/edit', [
            'title'     => 'Editar ' . $user['name'],
            'activeNav' => 'users',
            'user'      => $user,
            'roles'     => User::roles(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id   = $request->routeInt('id');
        $user = User::find($id);

        if ($user === null) {
            throw HttpException::notFound('Usuário não encontrado.');
        }

        $rules = [
            'name'   => 'required|string|min:2|max:120',
            'email'  => 'required|email|max:190',
            'role'   => 'required|in:admin,operator',
            'status' => 'required|in:active,inactive',
        ];

        // Senha e opcional na edicao; quando informada, precisa ser forte.
        if ($request->string('password') !== '') {
            $rules['password'] = 'required|string|min:8|max:255|confirmed';
        }

        $data = $this->validate($request, $rules, [
            'name'     => 'nome',
            'email'    => 'e-mail',
            'password' => 'senha',
            'role'     => 'perfil',
            'status'   => 'situacao',
        ]);

        if (User::emailExists($data['email'], $id)) {
            Session::flashErrors(['email' => 'Já existe outro usuário com este e-mail.']);
            Session::flashInput($request->all());

            return $this->redirect('/usuarios/' . $id . '/editar');
        }

        // Trava de seguranca: nao deixar o sistema sem administrador ativo.
        $isLastAdmin = $user['role'] === User::ROLE_ADMIN
            && $user['status'] === 'active'
            && User::countAdmins() <= 1;

        if ($isLastAdmin && ($data['role'] !== User::ROLE_ADMIN || $data['status'] !== 'active')) {
            $this->flashError(
                'Este é o único administrador ativo. Promova outro usuário antes de alterar o perfil ou desativá-lo.'
            );

            return $this->redirect('/usuarios/' . $id . '/editar');
        }

        $changes = [
            'name'   => $data['name'],
            'email'  => mb_strtolower($data['email']),
            'role'   => $data['role'],
            'status' => $data['status'],
        ];

        if (isset($data['password'])) {
            $changes['password_hash'] = User::hashPassword($data['password']);
        }

        User::updateById($id, $changes);

        AuditService::log('user.updated', sprintf('Usuário "%s" atualizado.', $data['name']), [
            'entity_type' => 'user',
            'entity_id'   => $id,
            'context'     => ['senha_alterada' => isset($data['password'])],
        ]);

        // Alterou a propria senha: renova a sessao.
        if ($id === AuthService::id() && isset($data['password'])) {
            Session::regenerate();
        }

        $this->flashSuccess('Usuário atualizado.');

        return $this->redirect('/usuarios');
    }

    public function destroy(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id   = $request->routeInt('id');
        $user = User::find($id);

        if ($user === null) {
            throw HttpException::notFound('Usuário não encontrado.');
        }

        if ($id === AuthService::id()) {
            $this->flashError('Você não pode excluir o próprio usuário.');

            return $this->redirect('/usuarios');
        }

        if ($user['role'] === User::ROLE_ADMIN && User::countAdmins() <= 1) {
            $this->flashError('Este é o único administrador ativo. Promova outro usuário antes de excluir.');

            return $this->redirect('/usuarios');
        }

        User::deleteById($id);

        AuditService::log('user.deleted', sprintf('Usuário "%s" excluido.', $user['name']), [
            'entity_type' => 'user',
            'entity_id'   => $id,
            'level'       => 'warning',
        ]);

        $this->flashSuccess('Usuário excluido.');

        return $this->redirect('/usuarios');
    }
}
