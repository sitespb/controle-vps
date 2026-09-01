<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\Server;
use App\Repositories\AlertRepository;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\AuthService;

/**
 * Alertas internos (secao 18 do PLAN).
 *
 * A resolucao AUTOMATICA acontece no AlertService, disparada pela chegada de
 * dados do agente e pelo cron. As acoes daqui sao apenas as manuais:
 * reconhecer e resolver.
 */
final class AlertController extends Controller
{
    private AlertRepository $repository;

    public function __construct()
    {
        $this->repository = new AlertRepository();
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status'    => $this->validStatus($request->string('status', 'active')),
            'severity'  => $this->validSeverity($request->string('severidade')),
            'type'      => $this->validType($request->string('tipo')),
            'server_id' => $request->int('servidor'),
            'search'    => mb_substr($request->string('q'), 0, 190),
            'sort'      => $request->string('sort', 'severity'),
            'direction' => $request->string('dir', 'asc'),
        ];

        $result = $this->repository->paginate(
            array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0),
            max(1, $request->int('pagina', 1)),
            $this->perPage($request)
        );

        return $this->view('alerts/index', [
            'title'      => 'Alertas',
            'activeNav'  => 'alerts',
            'alerts'     => $result['items'],
            'pagination' => [
                'total'    => $result['total'],
                'page'     => $result['page'],
                'pages'    => $result['pages'],
                'per_page' => $result['per_page'],
            ],
            'filters'  => $filters,
            'servers'  => Server::options(),
            'types'    => Alert::types(),
            'counts'   => Alert::countOpenBySeverity(),
        ]);
    }

    public function show(Request $request): Response
    {
        $id    = $request->routeInt('id');
        $alert = $this->repository->findDetailed($id);

        if ($alert === null) {
            throw HttpException::notFound('Alerta nao encontrado.');
        }

        return $this->view('alerts/show', [
            'title'     => Alert::typeLabel((string) $alert['type']),
            'activeNav' => 'alerts',
            'alert'     => $alert,
            'events'    => AlertEvent::forAlert($id, 30),
        ]);
    }

    public function acknowledge(Request $request): Response
    {
        $id     = $request->routeInt('id');
        $userId = AuthService::id();

        if ($userId === null) {
            throw HttpException::unauthorized();
        }

        if (!AlertService::acknowledge($id, $userId)) {
            $this->flashWarning('Este alerta nao esta aberto.');

            return $this->back($request, '/alertas');
        }

        AuditService::log('alert.acknowledged', sprintf('Alerta #%d reconhecido.', $id), [
            'entity_type' => 'alert',
            'entity_id'   => $id,
        ]);

        $this->flashSuccess('Alerta reconhecido.');

        return $this->back($request, '/alertas');
    }

    public function resolve(Request $request): Response
    {
        $id     = $request->routeInt('id');
        $userId = AuthService::id();

        if ($userId === null) {
            throw HttpException::unauthorized();
        }

        if (!AlertService::resolveManually($id, $userId)) {
            $this->flashWarning('Este alerta ja estava resolvido.');

            return $this->back($request, '/alertas');
        }

        AuditService::log('alert.resolved', sprintf('Alerta #%d resolvido manualmente.', $id), [
            'entity_type' => 'alert',
            'entity_id'   => $id,
        ]);

        $this->flashSuccess(
            'Alerta resolvido. Se a condicao persistir, ele sera reaberto automaticamente na proxima coleta.'
        );

        return $this->back($request, '/alertas');
    }

    private function validStatus(string $status): string
    {
        return \in_array($status, ['active', 'open', 'acknowledged', 'resolved', 'all'], true) ? $status : 'active';
    }

    private function validSeverity(string $severity): string
    {
        return \in_array($severity, ['critical', 'warning', 'info'], true) ? $severity : '';
    }

    private function validType(string $type): string
    {
        return \array_key_exists($type, Alert::types()) ? $type : '';
    }
}
