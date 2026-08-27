<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  Rotas do painel (HTML)
 * ============================================================================
 *
 * Convencoes:
 *  - middleware `guest`  : apenas visitantes (tela de login);
 *  - middleware `auth`   : exige sessao valida;
 *  - middleware `role:x` : exige perfil (admin tem acesso total);
 *  - middleware `csrf`   : obrigatorio em TODA rota que altera estado;
 *  - middleware `throttle:n,seg,nome` : limite de requisicoes.
 *
 * A variavel $router e injetada pelo App::router().
 *
 * @var \App\Core\Router $router
 */

use App\Controllers\AlertController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\LogController;
use App\Controllers\MetricsController;
use App\Controllers\NotifyController;
use App\Controllers\ServerController;
use App\Controllers\SettingsController;
use App\Controllers\SiteController;
use App\Controllers\UserController;

// ---------------------------------------------------------------------------
// Autenticacao
// ---------------------------------------------------------------------------
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);

// throttle antes do csrf: forca bruta nao deve nem chegar a validar o token.
$router->post('/login', [AuthController::class, 'login'], ['guest', 'throttle:20,300,login', 'csrf']);

$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

// ---------------------------------------------------------------------------
// Painel autenticado
// ---------------------------------------------------------------------------
$router->group(['middleware' => ['auth']], static function ($router): void {

    // Dashboard -------------------------------------------------------------
    $router->get('/', [DashboardController::class, 'index']);

    // Servidores ------------------------------------------------------------
    $router->get('/servidores', [ServerController::class, 'index']);
    $router->get('/servidores/novo', [ServerController::class, 'create'], ['role:admin']);
    $router->post('/servidores', [ServerController::class, 'store'], ['role:admin', 'csrf']);

    $router->get('/servidores/{id:\d+}', [ServerController::class, 'show']);
    $router->get('/servidores/{id:\d+}/agente', [ServerController::class, 'agent'], ['role:admin']);
    $router->get('/servidores/{id:\d+}/editar', [ServerController::class, 'edit'], ['role:admin']);
    $router->get('/servidores/{id:\d+}/sites', [ServerController::class, 'sites']);

    $router->post('/servidores/{id:\d+}', [ServerController::class, 'update'], ['role:admin', 'csrf']);
    $router->post('/servidores/{id:\d+}/excluir', [ServerController::class, 'destroy'], ['role:admin', 'csrf']);
    $router->post('/servidores/{id:\d+}/token', [ServerController::class, 'regenerateToken'], ['role:admin', 'csrf']);

    // Sites -----------------------------------------------------------------
    // Somente leitura: os dominios chegam pela descoberta automatica do agente.
    $router->get('/sites', [SiteController::class, 'index']);
    $router->get('/sites/{id:\d+}', [SiteController::class, 'show']);

    // "Estou ciente": silencia os avisos deste dominio ate ele voltar ao ar.
    $router->post('/sites/{id:\d+}/ciente', [SiteController::class, 'toggleNotify'], ['role:admin', 'csrf']);

    // Monitoramento ---------------------------------------------------------
    $router->get('/metricas', [MetricsController::class, 'index']);

    $router->get('/alertas', [AlertController::class, 'index']);
    $router->get('/alertas/{id:\d+}', [AlertController::class, 'show']);
    $router->post('/alertas/{id:\d+}/reconhecer', [AlertController::class, 'acknowledge'], ['csrf']);
    $router->post('/alertas/{id:\d+}/resolver', [AlertController::class, 'resolve'], ['csrf']);

    // Configuracoes ---------------------------------------------------------
    $router->get('/usuarios', [UserController::class, 'index'], ['role:admin']);
    $router->get('/usuarios/novo', [UserController::class, 'create'], ['role:admin']);
    $router->post('/usuarios', [UserController::class, 'store'], ['role:admin', 'csrf']);
    $router->get('/usuarios/{id:\d+}/editar', [UserController::class, 'edit'], ['role:admin']);
    $router->post('/usuarios/{id:\d+}', [UserController::class, 'update'], ['role:admin', 'csrf']);
    $router->post('/usuarios/{id:\d+}/excluir', [UserController::class, 'destroy'], ['role:admin', 'csrf']);

    $router->get('/configuracoes', [SettingsController::class, 'index'], ['role:admin']);
    $router->post('/configuracoes', [SettingsController::class, 'update'], ['role:admin', 'csrf']);

    // Avisos ao administrador -----------------------------------------------
    // Os testes tem throttle proprio: cada clique fala com um provedor
    // externo, e um botao e facil de martelar sem querer.
    $router->get('/avisos', [NotifyController::class, 'index'], ['role:admin']);
    $router->post('/avisos/email', [NotifyController::class, 'updateEmail'], ['role:admin', 'csrf']);
    $router->post('/avisos/whatsapp', [NotifyController::class, 'updateWhatsapp'], ['role:admin', 'csrf']);
    $router->post('/avisos/email/testar', [NotifyController::class, 'testEmail'], ['role:admin', 'throttle:10,600,notify-test', 'csrf']);
    $router->post('/avisos/whatsapp/testar', [NotifyController::class, 'testWhatsapp'], ['role:admin', 'throttle:10,600,notify-test', 'csrf']);

    $router->get('/logs', [LogController::class, 'index'], ['role:admin']);
});
