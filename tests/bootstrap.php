<?php

declare(strict_types=1);

/**
 * Bootstrap da suite de testes.
 *
 * Cria e migra um banco SEPARADO (sufixo _test) para que rodar os testes
 * nunca toque nos dados de desenvolvimento nem nos de producao. O nome sai do
 * DB_DATABASE do .env com o sufixo aplicado.
 */

define('BASE_PATH', dirname(__DIR__));
define('RUNNING_TESTS', true);

require BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(BASE_PATH);

require __DIR__ . '/TestCase.php';

// CLI nao inicia sessao: os testes manipulam $_SESSION diretamente.
$_SESSION = [];
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'suite-de-testes';
$_SERVER['REQUEST_METHOD']  = 'GET';

App\Core\App::bootstrap(BASE_PATH);

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

// ---------------------------------------------------------------------------
// Banco de teste
// ---------------------------------------------------------------------------

$productionDatabase = (string) Config::get('database.database');
$testDatabase       = $productionDatabase . '_test';

if ($productionDatabase === '') {
    fwrite(\STDERR, "DB_DATABASE nao configurado no .env.\n");
    exit(1);
}

Config::set('database.database', $testDatabase);

// Nao gerar ruido de log durante os testes.
Config::set('log.level', 'error');

try {
    Migrator::createDatabase();
} catch (Throwable $e) {
    fwrite(\STDERR, 'Nao foi possivel criar o banco de teste: ' . $e->getMessage() . "\n");
    fwrite(\STDERR, "Verifique se o MySQL esta rodando e se o usuario tem permissao de CREATE DATABASE.\n");
    exit(1);
}

if (!Database::isAvailable()) {
    fwrite(\STDERR, 'Banco de teste inacessivel: ' . (Database::lastError() ?? 'motivo desconhecido') . "\n");
    exit(1);
}

// Schema sempre do zero: teste que depende de estado anterior nao e teste.
$migrator = new Migrator();
$result   = $migrator->fresh();

if ($result['errors'] !== []) {
    fwrite(\STDERR, "Falha ao preparar o schema de teste:\n");

    foreach ($result['errors'] as $migration => $error) {
        fwrite(\STDERR, "  {$migration}: {$error}\n");
    }

    exit(1);
}

// Garante que os limites usados nas assercoes sao os padroes do arquivo, e
// nao valores que alguem ajustou na tela de configuracoes.
Config::set('monitoring.thresholds.cpu', ['warning' => 80.0, 'critical' => 90.0]);
Config::set('monitoring.thresholds.ram', ['warning' => 80.0, 'critical' => 90.0]);
Config::set('monitoring.thresholds.disk', ['warning' => 80.0, 'critical' => 90.0]);
Config::set('monitoring.ssl', ['warning' => 30, 'critical' => 7]);
Config::set('monitoring.server_offline_after', 600);
Config::set('monitoring.agent_api.clock_skew', 300);
Config::set('monitoring.login', ['max_attempts' => 5, 'decay_minutes' => 15]);

return $testDatabase;
