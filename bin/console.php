<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  Controle VPS - Console
 * ============================================================================
 *
 *  Uso:  php bin/console.php <comando> [opcoes]
 *
 *  Comandos:
 *    key:generate            Gera a APP_KEY e grava no .env
 *    db:create               Cria o banco definido em DB_DATABASE
 *    db:check                Testa a conexao e lista as tabelas
 *    migrate                 Executa as migrations pendentes
 *    migrate:status          Mostra o que ja rodou e o que falta
 *    migrate:fresh           APAGA tudo e recria o schema  (destrutivo)
 *    db:seed                 Insere os dados ficticios de demonstracao
 *    db:seed --refresh       Rejuvenesce a demonstracao (desloca a serie no tempo)
 *    db:seed --remove        Remove todos os dados de demonstracao
 *    user:create             Cria um usuario (interativo ou por opcoes)
 *    user:list               Lista os usuarios cadastrados
 *    user:password           Redefine a senha de um usuario
 *    routes                  Lista as rotas registradas
 *    install                 Atalho: db:create + migrate + admin + seed
 *
 *  Opcoes usadas por user:create / user:password / install:
 *    --name="Nome"  --email=a@b.c  --password=segredo  --role=admin|operator
 *    --force        Nao pede confirmacao em comandos destrutivos
 *    --no-seed      Em `install`, nao insere os dados ficticios
 * ============================================================================
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script roda apenas via linha de comando.\n");
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(BASE_PATH);
App\Core\App::bootstrap(BASE_PATH);

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Router;
use App\Models\User;
use App\Services\AuditService;

// ---------------------------------------------------------------------------
// Utilidades de saida
// ---------------------------------------------------------------------------

function out(string $line = ''): void
{
    echo $line . \PHP_EOL;
}

function ok(string $line): void
{
    out('  [OK]   ' . $line);
}

function fail(string $line): void
{
    out('  [ERRO] ' . $line);
}

function warn(string $line): void
{
    out('  [!]    ' . $line);
}

function title(string $line): void
{
    out();
    out('=== ' . $line . ' ' . str_repeat('=', max(0, 66 - mb_strlen($line))));
}

/** @param array<int,string> $argv @return array<string,string|bool> */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (\array_slice($argv, 2) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);

        if (str_contains($arg, '=')) {
            [$key, $value]  = explode('=', $arg, 2);
            $options[$key]  = trim($value, "\"'");
        } else {
            $options[$arg] = true;
        }
    }

    return $options;
}

function ask(string $question, string $default = ''): string
{
    $suffix = $default === '' ? '' : " [{$default}]";
    echo $question . $suffix . ': ';

    $line = fgets(\STDIN);
    $line = $line === false ? '' : trim($line);

    return $line === '' ? $default : $line;
}

function confirm(string $question): bool
{
    echo $question . ' (digite SIM para confirmar): ';
    $line = fgets(\STDIN);

    return $line !== false && strtoupper(trim($line)) === 'SIM';
}

// ---------------------------------------------------------------------------
// Comandos
// ---------------------------------------------------------------------------

$command = $argv[1] ?? 'help';
$options = parseOptions($argv);

try {
    switch ($command) {
        case 'key:generate':
            commandKeyGenerate();
            break;

        case 'db:create':
            commandDbCreate();
            break;

        case 'db:check':
            commandDbCheck();
            break;

        case 'migrate':
            commandMigrate();
            break;

        case 'migrate:status':
            commandMigrateStatus();
            break;

        case 'migrate:fresh':
            commandMigrateFresh($options);
            break;

        case 'db:seed':
            commandSeed($options);
            break;

        case 'user:create':
            commandUserCreate($options);
            break;

        case 'user:list':
            commandUserList();
            break;

        case 'user:password':
            commandUserPassword($options);
            break;

        case 'routes':
            commandRoutes();
            break;

        case 'install':
            commandInstall($options);
            break;

        default:
            commandHelp();
    }
} catch (Throwable $e) {
    out();
    fail($e->getMessage());
    out('  ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

exit(0);

// ---------------------------------------------------------------------------

function commandHelp(): void
{
    out();
    out('  Controle VPS - Central de Monitoramento CyberPanel  v' . App\Core\App::VERSION);
    out('  ' . str_repeat('-', 66));
    out();
    out('  Uso: php bin/console.php <comando> [opcoes]');
    out();

    $commands = [
        'key:generate'   => 'Gera a APP_KEY e grava no .env',
        'db:create'      => 'Cria o banco definido em DB_DATABASE',
        'db:check'       => 'Testa a conexao e lista as tabelas',
        'migrate'        => 'Executa as migrations pendentes',
        'migrate:status' => 'Mostra o que ja rodou e o que falta',
        'migrate:fresh'  => 'APAGA tudo e recria o schema (destrutivo)',
        'db:seed'          => 'Insere os dados ficticios de demonstracao',
        'db:seed --refresh' => 'Rejuvenesce a demonstracao (desloca no tempo)',
        'db:seed --remove' => 'Remove todos os dados de demonstracao',
        'user:create'    => 'Cria um usuario do painel',
        'user:list'      => 'Lista os usuarios cadastrados',
        'user:password'  => 'Redefine a senha de um usuario',
        'routes'         => 'Lista as rotas registradas',
        'install'        => 'db:create + migrate + admin + seed',
    ];

    foreach ($commands as $name => $description) {
        out(sprintf('    %-20s %s', $name, $description));
    }

    out();
    out('  Opcoes: --name= --email= --password= --role= --force --no-seed');
    out();
}

function commandKeyGenerate(): void
{
    title('Gerando APP_KEY');

    $key      = base64_encode(random_bytes(32));
    $envFile  = BASE_PATH . '/.env';

    if (!is_file($envFile)) {
        fail('Arquivo .env nao encontrado. Copie o .env.example primeiro.');
        exit(1);
    }

    $content = (string) file_get_contents($envFile);

    if (preg_match('/^APP_KEY=.*$/m', $content) === 1) {
        $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content) ?? $content;
    } else {
        $content .= "\nAPP_KEY={$key}\n";
    }

    file_put_contents($envFile, $content);

    ok('APP_KEY gerada e gravada no .env.');
}

function commandDbCreate(): void
{
    title('Criando o banco de dados');

    $name = (string) Config::get('database.database', '');

    Migrator::createDatabase();

    ok(sprintf('Banco "%s" pronto em %s:%s.', $name, Config::get('database.host'), Config::get('database.port')));
}

function commandDbCheck(): void
{
    title('Verificando a conexao com o banco');

    out(sprintf('  Host ....: %s:%s', Config::get('database.host'), Config::get('database.port')));
    out(sprintf('  Banco ...: %s', Config::get('database.database')));
    out(sprintf('  Usuario .: %s', Config::get('database.username')));
    out();

    if (!Database::isAvailable()) {
        fail('Conexao falhou: ' . (Database::lastError() ?? 'motivo desconhecido'));
        out();
        out('  Verifique se o MySQL esta rodando e se as credenciais do .env estao corretas.');
        exit(1);
    }

    ok('Conexao estabelecida. MySQL ' . Database::scalar('SELECT VERSION()'));
    out();

    $tables = Database::select('SHOW TABLES');

    if ($tables === []) {
        warn('Nenhuma tabela encontrada. Rode: php bin/console.php migrate');

        return;
    }

    out(sprintf('  %d tabela(s):', \count($tables)));

    foreach ($tables as $row) {
        $table = (string) array_values($row)[0];
        $count = (int) Database::scalar('SELECT COUNT(*) FROM `' . $table . '`');
        out(sprintf('    %-22s %8s linha(s)', $table, number_format($count, 0, ',', '.')));
    }
}

function commandMigrate(): void
{
    title('Executando migrations');

    $migrator = new Migrator();
    $pending  = $migrator->pending();

    if ($pending === []) {
        ok('Nenhuma migration pendente. O banco ja esta atualizado.');

        return;
    }

    out(sprintf('  %d migration(s) pendente(s).', \count($pending)));
    out();

    $result = $migrator->run(static function (string $name, string $status): void {
        if (str_starts_with($status, 'erro')) {
            fail($name . ' -> ' . $status);
        } else {
            ok($name);
        }
    });

    out();

    if ($result['errors'] !== []) {
        fail(sprintf('%d migration(s) falharam. O schema pode estar incompleto.', \count($result['errors'])));
        exit(1);
    }

    ok(sprintf('%d migration(s) aplicada(s).', \count($result['executed'])));
}

function commandMigrateStatus(): void
{
    title('Status das migrations');

    $migrator = new Migrator();
    $applied  = $migrator->applied();

    foreach ($migrator->available() as $name) {
        $done = \in_array($name, $applied, true);
        out(sprintf('  [%s] %s', $done ? 'x' : ' ', $name));
    }

    out();
    out(sprintf('  %d aplicada(s), %d pendente(s).', \count($applied), \count($migrator->pending())));
}

/** @param array<string,string|bool> $options */
function commandMigrateFresh(array $options): void
{
    title('Recriando o schema do zero');

    warn('Esta operacao APAGA TODAS as tabelas do banco "' . Config::get('database.database') . '".');
    warn('Servidores, sites, metricas, alertas e usuarios serao perdidos.');
    out();

    if (!isset($options['force']) && !confirm('  Tem certeza?')) {
        out('  Cancelado.');

        return;
    }

    $migrator = new Migrator();
    $result   = $migrator->fresh(static function (string $name, string $status): void {
        out(sprintf('  %-46s %s', $name, $status));
    });

    out();

    if ($result['errors'] !== []) {
        fail('Falha ao recriar o schema.');
        exit(1);
    }

    ok(sprintf('Schema recriado com %d migration(s).', \count($result['executed'])));
}

/** @param array<string,string|bool> $options */
function commandSeed(array $options): void
{
    require_once BASE_PATH . '/database/seeders/DemoSeeder.php';

    if (isset($options['refresh'])) {
        title('Atualizando os dados de demonstracao');

        out('  Desloca a serie inteira no tempo para que a demonstracao volte');
        out('  a mostrar uma infraestrutura ativa. O historico e preservado.');
        out('');

        $result = \Database\Seeders\DemoSeeder::refresh();

        if (isset($result['erro'])) {
            fail((string) $result['erro']);
            exit(1);
        }

        foreach ($result as $label => $value) {
            ok(sprintf('%-24s %s', str_replace('_', ' ', (string) $label), $value));
        }

        return;
    }

    if (isset($options['remove'])) {
        title('Removendo os dados de demonstracao');

        // Nome totalmente qualificado: sem a barra inicial o PHP resolveria
        // "Database" pelo `use App\Core\Database` do topo deste arquivo.
        $result = \Database\Seeders\DemoSeeder::remove();

        ok(sprintf('%d servidor(es) de demonstracao removido(s) com todos os dados associados.', $result['servidores_removidos']));

        return;
    }

    title('Inserindo dados ficticios de demonstracao');

    $existing = (int) Database::scalar('SELECT COUNT(*) FROM servers WHERE is_demo = 1');

    if ($existing > 0 && !isset($options['force'])) {
        warn(sprintf('Ja existem %d servidor(es) de demonstracao no banco.', $existing));
        out('  Use --force para inserir mesmo assim, ou --remove para limpar antes.');

        return;
    }

    $seeder = new \Database\Seeders\DemoSeeder(static fn (string $line): int => print($line . \PHP_EOL));
    $result = $seeder->run();

    out();
    ok('Dados de demonstracao inseridos:');

    foreach ($result as $label => $value) {
        out(sprintf('    %-14s %s', $label, number_format($value, 0, ',', '.')));
    }

    out();
    warn('Todos estes registros estao marcados com is_demo = 1 e aparecem com o selo DEMO no painel.');
    warn('Antes de usar em producao, rode: php bin/console.php db:seed --remove');
}

/** @param array<string,string|bool> $options */
function commandUserCreate(array $options): void
{
    title('Criando usuario do painel');

    $name     = (string) ($options['name'] ?? '');
    $email    = (string) ($options['email'] ?? '');
    $password = (string) ($options['password'] ?? '');
    $role     = (string) ($options['role'] ?? '');

    if ($name === '') {
        $name = ask('  Nome completo');
    }

    if ($email === '') {
        $email = ask('  E-mail');
    }

    if ($role === '') {
        $role = ask('  Perfil (admin/operator)', 'admin');
    }

    if ($password === '') {
        $password = ask('  Senha (minimo 8 caracteres)');
    }

    // Validacao
    if (mb_strlen($name) < 2) {
        fail('Nome muito curto.');
        exit(1);
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fail('E-mail invalido.');
        exit(1);
    }

    if (mb_strlen($password) < 8) {
        fail('A senha precisa ter no minimo 8 caracteres.');
        exit(1);
    }

    if (!\in_array($role, ['admin', 'operator'], true)) {
        fail('Perfil invalido. Use admin ou operator.');
        exit(1);
    }

    if (User::emailExists($email)) {
        fail('Ja existe um usuario com este e-mail.');
        exit(1);
    }

    $id = User::create([
        'name'          => $name,
        'email'         => mb_strtolower($email),
        'password_hash' => User::hashPassword($password),
        'role'          => $role,
        'status'        => 'active',
    ]);

    AuditService::log('user.created', sprintf('Usuario "%s" criado via console.', $name), [
        'entity_type' => 'user',
        'entity_id'   => $id,
        'user_id'     => null,
        'actor'       => 'console',
    ]);

    out();
    ok(sprintf('Usuario #%d criado.', $id));
    out(sprintf('    Nome ....: %s', $name));
    out(sprintf('    E-mail ..: %s', mb_strtolower($email)));
    out(sprintf('    Perfil ..: %s', User::roleLabel($role)));
    out();
    out('  Acesse: ' . Config::get('app.url') . '/login');
}

function commandUserList(): void
{
    title('Usuarios cadastrados');

    $users = User::listAll();

    if ($users === []) {
        warn('Nenhum usuario cadastrado. Rode: php bin/console.php user:create');

        return;
    }

    out(sprintf('  %-4s %-26s %-32s %-12s %-9s %s', 'ID', 'NOME', 'E-MAIL', 'PERFIL', 'SITUACAO', 'ULTIMO LOGIN'));
    out('  ' . str_repeat('-', 100));

    foreach ($users as $user) {
        out(sprintf(
            '  %-4d %-26s %-32s %-12s %-9s %s',
            $user['id'],
            mb_substr((string) $user['name'], 0, 25),
            mb_substr((string) $user['email'], 0, 31),
            User::roleLabel((string) $user['role']),
            $user['status'] === 'active' ? 'ativo' : 'inativo',
            $user['last_login_at'] === null ? 'nunca' : format_datetime((string) $user['last_login_at'])
        ));
    }
}

/** @param array<string,string|bool> $options */
function commandUserPassword(array $options): void
{
    title('Redefinindo senha');

    $email    = (string) ($options['email'] ?? '');
    $password = (string) ($options['password'] ?? '');

    if ($email === '') {
        $email = ask('  E-mail do usuario');
    }

    $user = User::findByEmail($email);

    if ($user === null) {
        fail('Usuario nao encontrado: ' . $email);
        exit(1);
    }

    if ($password === '') {
        $password = ask('  Nova senha (minimo 8 caracteres)');
    }

    if (mb_strlen($password) < 8) {
        fail('A senha precisa ter no minimo 8 caracteres.');
        exit(1);
    }

    User::updateById((int) $user['id'], ['password_hash' => User::hashPassword($password)]);

    AuditService::log('user.password_reset', sprintf('Senha do usuario "%s" redefinida via console.', $user['name']), [
        'entity_type' => 'user',
        'entity_id'   => (int) $user['id'],
        'level'       => 'warning',
        'user_id'     => null,
        'actor'       => 'console',
    ]);

    ok('Senha redefinida para ' . $user['email'] . '.');
}

function commandRoutes(): void
{
    title('Rotas registradas');

    $router = new Router();

    $router->registerMiddleware([
        'auth' => App\Middleware\AuthMiddleware::class,
        'guest' => App\Middleware\GuestMiddleware::class,
        'role' => App\Middleware\RoleMiddleware::class,
        'csrf' => App\Middleware\CsrfMiddleware::class,
        'agent' => App\Middleware\AgentAuthMiddleware::class,
        'api' => App\Middleware\ApiAuthMiddleware::class,
        'throttle' => App\Middleware\RateLimitMiddleware::class,
    ]);

    require BASE_PATH . '/routes/web.php';
    require BASE_PATH . '/routes/api.php';

    foreach ($router->summary() as $method => $count) {
        out(sprintf('  %-8s %d rota(s)', $method, $count));
    }

    out();
    out('  Detalhe completo em routes/web.php e routes/api.php.');
}

/** @param array<string,string|bool> $options */
function commandInstall(array $options): void
{
    out();
    out('  ============================================================');
    out('   INSTALACAO DO CONTROLE VPS');
    out('  ============================================================');

    // 1. APP_KEY
    if ((string) Config::get('app.key', '') === '') {
        commandKeyGenerate();
    } else {
        title('APP_KEY');
        ok('Ja definida no .env.');
    }

    // 2. Banco
    commandDbCreate();

    // 3. Migrations
    commandMigrate();

    // 4. Administrador
    title('Usuario administrador');

    if (User::countAdmins(false) > 0) {
        ok('Ja existe administrador cadastrado. Pulando.');
    } else {
        commandUserCreate($options);
    }

    // 5. Dados de demonstracao
    if (!isset($options['no-seed'])) {
        commandSeed($options);
    }

    out();
    out('  ============================================================');
    out('   INSTALACAO CONCLUIDA');
    out('  ============================================================');
    out();
    out('   Acesse: ' . Config::get('app.url') . '/login');
    out();
    out('   Proximos passos:');
    out('     1. Configure o cron do painel  (ver docs/INSTALACAO-LOCAL.md)');
    out('     2. Cadastre um servidor real   (Servidores > Novo servidor)');
    out('     3. Instale o agente no VPS     (ver agent/README.md)');
    out();
}
