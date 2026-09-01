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
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\DuplicateSiteService;
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
            'duplicados' => $request->string('duplicados') === 'yes' ? 'yes' : '',
            'sort'      => $request->string('sort', 'domain'),
            'direction' => $request->string('dir', 'asc'),
        ];

        $result = $this->repository->paginate(
            array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0),
            max(1, $request->int('pagina', 1)),
            $this->validPerPage($request->int('por_pagina', self::PER_PAGE_DEFAULT))
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

            // Lista pequena (normalmente vazia) usada para marcar as linhas.
            // Enviar o conjunto de uma vez evita uma subconsulta por linha.
            'duplicados' => $this->repository->duplicateDomains(),
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

            // Mesmo dominio em outro servidor. Quase sempre vazio; quando nao
            // esta, e a informacao mais importante da pagina.
            'duplicado'   => DuplicateSiteService::analyse(
                $site,
                $this->repository->otherCopiesOf((string) $site['domain'], $id)
            ),
        ]);
    }

    /**
     * Liga/desliga o "estou ciente" do dominio.
     *
     * Enquanto ligado, este dominio nao gera aviso por e-mail nem WhatsApp.
     * A marcacao se desfaz sozinha quando o site volta a responder (ver
     * AlertService::siteCameBack) - ninguem precisa lembrar de reativar.
     */
    public function toggleNotify(Request $request): Response
    {
        $this->authorizeRole('admin');

        $id   = $request->routeInt('id');
        $site = Site::find($id);

        if ($site === null) {
            throw HttpException::notFound('Site nao encontrado.');
        }

        $muted = !$request->input('ciente');

        Site::setNotifyMuted($id, $muted, AuthService::id());

        AuditService::log(
            $muted ? 'site.notify.muted' : 'site.notify.unmuted',
            sprintf('Avisos de %s %s', $site['domain'], $muted ? 'silenciados' : 'reativados'),
            ['entity_type' => 'site', 'entity_id' => $id]
        );

        $this->flashSuccess($muted
            ? sprintf('Voce esta ciente de %s. Nao enviaremos avisos deste dominio ate ele voltar ao ar.', $site['domain'])
            : sprintf('Avisos de %s reativados.', $site['domain']));

        return $this->redirect('/sites/' . $id);
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

    /**
     * Opcoes de itens por pagina oferecidas na listagem.
     *
     * Lista fechada de proposito: o valor vem da querystring, e aceitar
     * qualquer numero deixaria alguem pedir 100000 registros de uma vez.
     */
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    /** Precisa ser um dos valores acima, senao o seletor abriria sem selecao. */
    public const PER_PAGE_DEFAULT = 20;

    private function validPerPage(int $perPage): int
    {
        return \in_array($perPage, self::PER_PAGE_OPTIONS, true)
            ? $perPage
            : self::PER_PAGE_DEFAULT;
    }
}
