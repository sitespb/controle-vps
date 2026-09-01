<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Consulta dos logs de auditoria (secao 31 do PLAN).
 *
 * Somente leitura: nao ha edicao nem exclusao manual de log pelo painel. A
 * remocao acontece apenas pela retencao automatica do cron.
 */
final class LogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRole('admin');

        $filters = [
            'action'  => mb_substr($request->string('acao'), 0, 60),
            'level'   => $this->validLevel($request->string('nivel')),
            'user_id' => $request->int('usuario'),
            'search'  => mb_substr($request->string('q'), 0, 190),
            'from'    => $this->validDate($request->string('de')),
            'to'      => $this->validDate($request->string('ate')),
        ];

        $page    = max(1, $request->int('pagina', 1));
        $perPage = $this->perPage($request);

        $result = AuditLog::paginate(
            array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0),
            $page,
            $perPage
        );

        // AuditLog::paginate devolve apenas items e total; o total de paginas
        // e calculado aqui - e precisa usar o MESMO $perPage do SELECT, senao
        // a barra de paginacao mostraria um numero de paginas que nao existe.
        $pages = max(1, (int) ceil($result['total'] / $perPage));

        return $this->view('logs/index', [
            'title'      => 'Logs do sistema',
            'activeNav'  => 'logs',
            'logs'       => $result['items'],
            'pagination' => [
                'total'    => $result['total'],
                'page'     => min($page, $pages),
                'pages'    => $pages,
                'per_page' => $perPage,
            ],
            'filters' => $filters,
            'actions' => AuditLog::distinctActions(),
            'users'   => User::listAll(),
        ]);
    }

    private function validLevel(string $level): string
    {
        return \in_array($level, ['info', 'warning', 'error'], true) ? $level : '';
    }

    private function validDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }
}
