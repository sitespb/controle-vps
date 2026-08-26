<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerService;
use App\Models\ServerToken;
use App\Models\Site;
use App\Repositories\AlertRepository;
use App\Repositories\ServerRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\ServerProvisionService;

/**
 * Cadastro, listagem e detalhe dos servidores (secoes 11, 12 e 13 do PLAN).
 *
 * IMPORTANTE: aqui nao existe nenhuma acao administrativa sobre o VPS. As
 * unicas operacoes sao sobre o REGISTRO no painel (criar, editar, excluir,
 * regenerar token). Reiniciar servidor, executar comando ou gerenciar servicos
 * sao itens da V2 e estao fora do escopo (secao 41).
 */
final class ServerController extends Controller
{
    private ServerRepository $repository;

    public function __construct()
    {
        $this->repository = new ServerRepository();
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => $this->validStatus($request->string('status')),
            'search' => mb_substr($request->string('q'), 0, 120),
        ];

        $servers = $this->repository->listWithMetrics(array_filter($filters));

        return $this->view('servers/index', [
            'title'     => 'Servidores',
            'activeNav' => 'servers',
            'servers'   => $servers,
            'filters'   => $filters,
            'summary'   => $this->repository->statusSummary(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeRole('admin');

        return $this->view('servers/create', [
            'title'     => 'Novo servidor',
            'activeNav' => 'servers',
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole('admin');

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:120',
            'provider'    => 'nullable|string|max:120',
            'hostname'    => 'nullable|hostname|max:190',
            'ip'          => 'nullable|ip',
            'description' => 'nullable|string|max:2000',
        ], [
            'name'        => 'nome do servidor',
            'provider'    => 'provedor',
            'hostname'    => 'hostname',
            'ip'          => 'IP',
            'description' => 'descricao',
        ]);

        $created = ServerProvisionService::create($data, AuthService::id());

        AuditService::serverCreated($created['server_id'], $data['name']);

        // O token completo viaja apenas pela sessao e e consumido uma unica
        // vez na tela de instrucoes (secao 12: "mostrado apenas no momento
        // apropriado"). Nunca vai para a URL nem para o log.
        Session::set('_new_token', [
            'server_id' => $created['server_id'],
            'token'     => $created['token'],
            'expires'   => time() + 900,
        ]);

        $this->flashSuccess(sprintf('Servidor "%s" cadastrado com sucesso.', $data['name']));

        return $this->redirect('/servidores/' . $created['server_id'] . '/agente');
    }

    public function show(Request $request): Response
    {
        $id     = $request->routeInt('id');
        $server = $this->repository->findDetailed($id);

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        $hours = $this->validHours($request->int('horas', 24));

        return $this->view('servers/show', [
            'title'      => $server['name'],
            'activeNav'  => 'servers',
            'server'     => $server,
            'metric'     => $server['metric'],
            'services'   => ServerService::forServer($id),
            'sites'      => Site::forServer($id, 100),
            'alerts'     => (new AlertRepository())->forServer($id, 10),
            'series'     => ServerMetric::seriesFor($id, $hours),
            'hours'      => $hours,
            'tokenInfo'  => ServerToken::activeFor($id),
            'auditTrail' => AuditService::recentForServer($id, 8),
        ]);
    }

    /** Tela de instrucoes de instalacao do agente. */
    public function agent(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id     = $request->routeInt('id');
        $server = Server::find($id);

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        // Consome o token de uso unico guardado na sessao.
        $token   = null;
        $pending = Session::get('_new_token');

        if (
            \is_array($pending)
            && (int) ($pending['server_id'] ?? 0) === $id
            && (int) ($pending['expires'] ?? 0) > time()
        ) {
            $token = (string) $pending['token'];
        }

        Session::forget('_new_token');

        return $this->view('servers/agent', [
            'title'        => 'Instalacao do agente',
            'activeNav'    => 'servers',
            'server'       => $server,
            'token'        => $token,
            'tokenInfo'    => ServerToken::activeFor($id),
            'instructions' => ServerProvisionService::installationInstructions(
                $id,
                $token ?? 'SEU_TOKEN_AQUI'
            ),
        ]);
    }

    public function edit(Request $request): Response
    {
        $this->authorizeRole('admin');

        $server = Server::find($request->routeInt('id'));

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        return $this->view('servers/edit', [
            'title'     => 'Editar ' . $server['name'],
            'activeNav' => 'servers',
            'server'    => $server,
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id     = $request->routeInt('id');
        $server = Server::find($id);

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        $data = $this->validate($request, [
            'name'        => 'required|string|min:2|max:120',
            'provider'    => 'nullable|string|max:120',
            'hostname'    => 'nullable|hostname|max:190',
            'ip'          => 'nullable|ip',
            'description' => 'nullable|string|max:2000',
        ], [
            'name'        => 'nome do servidor',
            'provider'    => 'provedor',
            'hostname'    => 'hostname',
            'ip'          => 'IP',
            'description' => 'descricao',
        ]);

        $changes = ServerProvisionService::update($id, $data);

        AuditService::serverUpdated($id, $data['name'], $changes);

        $this->flashSuccess('Servidor atualizado.');

        return $this->redirect('/servidores/' . $id);
    }

    public function destroy(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id     = $request->routeInt('id');
        $server = Server::find($id);

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        // Confirmacao pelo nome: evita exclusao acidental de um servidor com
        // historico. As FKs em cascata removem metricas, sites e alertas.
        $confirmation = $request->string('confirm_name');

        if ($confirmation !== (string) $server['name']) {
            $this->flashError('Digite o nome exato do servidor para confirmar a exclusao.');

            return $this->redirect('/servidores/' . $id);
        }

        ServerProvisionService::delete($id);
        AuditService::serverDeleted($id, (string) $server['name']);

        $this->flashSuccess(sprintf('Servidor "%s" e todos os seus dados foram excluidos.', $server['name']));

        return $this->redirect('/servidores');
    }

    public function regenerateToken(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id     = $request->routeInt('id');
        $server = Server::find($id);

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        $token = ServerProvisionService::regenerateToken($id, AuthService::id());

        AuditService::tokenRegenerated($id, (string) $server['name'], $token['prefix']);

        Session::set('_new_token', [
            'server_id' => $id,
            'token'     => $token['token'],
            'expires'   => time() + 900,
        ]);

        $this->flashWarning(
            'Novo token gerado. O token anterior foi invalidado imediatamente - '
            . 'atualize o arquivo config.php do agente para restabelecer a comunicacao.'
        );

        return $this->redirect('/servidores/' . $id . '/agente');
    }

    /** Atalho "visualizar sites" da lista de servidores. */
    public function sites(Request $request): Response
    {
        $id     = $request->routeInt('id');
        $server = Server::find($id);

        if ($server === null) {
            throw HttpException::notFound('Servidor nao encontrado.');
        }

        return $this->redirect('/sites?servidor=' . $id);
    }

    private function validStatus(string $status): string
    {
        return \in_array($status, ['online', 'offline', 'warning', 'unknown'], true) ? $status : '';
    }

    private function validHours(int $hours): int
    {
        return \in_array($hours, [6, 24, 72, 168, 720], true) ? $hours : 24;
    }
}
