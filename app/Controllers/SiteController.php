<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Repositories\AlertRepository;
use App\Repositories\SiteRepository;
use App\Services\HttpStatusService;

/**
 * Listagem e detalhe dos sites (secoes 14 e 15 do PLAN).
 *
 * Nao existe cadastro manual de site: os dominios chegam pela descoberta
 * automatica do agente. Por isso este controller e somente leitura.
 */
final class SiteController extends Controller
{
    private SiteRepository $repository;

    public function __construct()
    {
        $this->repository = new SiteRepository();
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search'    => mb_substr($request->string('q'), 0, 190),
            'server_id' => $request->int('servidor'),
            'status'    => $this->validStatus($request->string('status')),
            'ssl'       => $this->validSsl($request->string('ssl')),
            'wordpress' => $this->validWordpress($request->string('wordpress')),
            'sort'      => $request->string('sort', 'domain'),
            'direction' => $request->string('dir', 'asc'),
        ];

        $result = $this->repository->paginate(
            array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0),
            max(1, $request->int('pagina', 1)),
            $this->validPerPage($request->int('por_pagina', 25))
        );

        return $this->view('sites/index', [
            'title'      => 'Sites',
            'activeNav'  => 'sites',
            'sites'      => $result['items'],
            'pagination' => [
                'total'    => $result['total'],
                'page'     => $result['page'],
                'pages'    => $result['pages'],
                'per_page' => $result['per_page'],
            ],
            'filters'    => $filters,
            'servers'    => Server::options(),
            'summary'    => $this->repository->statusSummary(),
            'sslSummary' => $this->repository->sslSummary(),
        ]);
    }

    public function show(Request $request): Response
    {
        $id   = $request->routeInt('id');
        $site = Site::findDetailed($id);

        if ($site === null) {
            throw HttpException::notFound('Site nao encontrado.');
        }

        $hours = \in_array($request->int('horas', 24), [6, 24, 72, 168], true)
            ? $request->int('horas', 24)
            : 24;

        return $this->view('sites/show', [
            'title'       => $site['domain'],
            'activeNav'   => 'sites',
            'site'        => $site,
            'httpLabel'   => HttpStatusService::describe(
                $site['http_status'] === null ? null : (int) $site['http_status']
            ),
            'httpExplain' => HttpStatusService::explain(
                $site['http_status'] === null ? null : (int) $site['http_status'],
                $site['last_error'] === null ? null : (string) $site['last_error']
            ),
            'checks'      => SiteCheck::recentFor($id, 30),
            'changes'     => SiteCheck::changesFor($id, 12),
            'series'      => SiteCheck::responseSeries($id, $hours),
            'uptime24h'   => SiteCheck::uptimePercent($id, 24),
            'uptime7d'    => SiteCheck::uptimePercent($id, 168),
            'alerts'      => (new AlertRepository())->forSite($id, 10),
            'hours'       => $hours,
        ]);
    }

    private function validStatus(string $status): string
    {
        return \in_array($status, ['online', 'offline', 'warning', 'unknown'], true) ? $status : '';
    }

    private function validSsl(string $ssl): string
    {
        return \in_array($ssl, ['valid', 'expiring', 'expired', 'unknown', 'none'], true) ? $ssl : '';
    }

    private function validWordpress(string $value): string
    {
        return \in_array($value, ['yes', 'no'], true) ? $value : '';
    }

    private function validPerPage(int $perPage): int
    {
        return \in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    }
}
