<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  Controle VPS - Central de Monitoramento CyberPanel
 *  Front controller: unico ponto de entrada HTTP da aplicacao.
 * ============================================================================
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

require BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(BASE_PATH);

(new App\Core\App(BASE_PATH))->run();
