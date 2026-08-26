<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DashboardService;

/**
 * Dashboard principal (secao 10 do PLAN).
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $data = (new DashboardService())->dashboardData();

        return $this->view('dashboard/index', [
            'title'          => 'Dashboard',
            'activeNav'      => 'dashboard',
            'summary'        => $data['summary'],
            'servers'        => $data['serverList'],
            'openAlerts'     => $data['openAlerts'],
            'sitesOffline'   => $data['sitesOffline'],
            'sslExpiring'    => $data['sslExpiring'],
            'wordpressCount' => $data['wordpressCount'],
        ]);
    }
}
