<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Models\Server;
use App\Models\User;
use App\Repositories\SiteRepository;
use App\Services\AuditService;
use App\Services\RateLimiter;
use App\Services\ServerProvisionService;
use Tests\TestCase;

/**
 * Endurecimento: CSRF, SQL injection, XSS, rate limiting, permissoes e
 * ausencia de execucao remota (secoes 33, 41 e 43 do PLAN).
 */
final class SecurityTest extends TestCase
{
    private int $adminId = 0;

    private int $operatorId = 0;

    public function name(): string
    {
        return 'Seguranca';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        Database::statement('DELETE FROM users');
        $this->truncate('audit_logs', 'api_rate_limits');

        $this->adminId = User::create([
            'name'          => 'Admin',
            'email'         => 'admin@teste.local',
            'password_hash' => User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $this->operatorId = User::create([
            'name'          => 'Operador',
            'email'         => 'operador@teste.local',
            'password_hash' => User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'operator',
            'status'        => 'active',
        ]);

        $this->loginAs($this->adminId, 'admin');
    }

    // =================================================================
    // CSRF
    // =================================================================

    public function testCsrfBloqueiaPostSemToken(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Alvo'], $this->adminId);

        $response = $this->request('POST', '/servidores/' . $created['server_id'] . '/excluir', [
            'confirm_name' => 'VPS Alvo',
        ]);

        $this->assertStatus(302, $response);
        $this->assertNotNull(Server::find($created['server_id']), 'O servidor nao deveria ter sido excluido.');
    }

    public function testCsrfBloqueiaTokenDeOutraSessao(): void
    {
        $response = $this->request('POST', '/servidores', [
            '_token' => str_repeat('x', 64), // token valido em forma, errado em valor
            'name'   => 'VPS Com Token Falso',
        ]);

        $this->assertStatus(302, $response);
        $this->assertEquals(0, Server::count(['name' => 'VPS Com Token Falso']));
    }

    public function testCsrfAceitaTokenNoCabecalhoParaFetch(): void
    {
        // As chamadas fetch do painel mandam o token em X-CSRF-Token.
        $request = new Request(
            'POST',
            '/api/v1/alerts/999/acknowledge',
            [],
            [],
            ['x-csrf-token' => $this->csrfToken(), 'accept' => 'application/json'],
            ''
        );

        $response = $this->app->handle($request);

        // 409 (alerta inexistente) prova que passou pelo CSRF; 419 significaria bloqueio.
        $this->assertNotEquals(419, $response->status(), 'O token no cabecalho deveria ter sido aceito.');
    }

    public function testComparacaoDeCsrfUsaHashEquals(): void
    {
        $_SESSION['_csrf_token'] = 'token-correto-para-o-teste';

        $valido = new Request('POST', '/x', [], ['_token' => 'token-correto-para-o-teste'], [], '');
        $errado = new Request('POST', '/x', [], ['_token' => 'token-correto-para-o-test'], [], '');

        $this->assertTrue(Csrf::check($valido));
        $this->assertFalse(Csrf::check($errado), 'Prefixo correto nao pode passar.');
    }

    // =================================================================
    // SQL injection
    // =================================================================

    public function testPesquisaComPayloadDeInjecaoNaoQuebraNemApaga(): void
    {
        ServerProvisionService::create(['name' => 'VPS Intacto'], $this->adminId);

        $payloads = [
            "' OR '1'='1",
            "'; DROP TABLE servers; --",
            "1' UNION SELECT NULL,NULL,NULL--",
            "\\'; DELETE FROM users; --",
        ];

        foreach ($payloads as $payload) {
            $response = $this->request('GET', '/servidores', [], [], ['q' => $payload]);

            $this->assertStatus(200, $response, "A pesquisa deveria responder normalmente para: {$payload}");
        }

        // As tabelas continuam de pe e com os dados.
        $this->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM servers'));
        $this->assertEquals(2, (int) Database::scalar('SELECT COUNT(*) FROM users'));
    }

    public function testOrdenacaoSoAceitaColunasDaAllowlist(): void
    {
        // O parametro `sort` nunca pode virar SQL livre.
        $repository = new SiteRepository();

        $result = $repository->paginate([
            'sort'      => 'domain; DROP TABLE sites',
            'direction' => 'DESC; DROP TABLE sites',
        ], 1, 10);

        $this->assertTrue(\is_array($result['items']));
        $this->assertTrue(Database::tableExists('sites'), 'A tabela sites deveria continuar existindo.');
    }

    public function testFiltroDeStatusInvalidoEhDescartado(): void
    {
        $response = $this->request('GET', '/servidores', [], [], ['status' => "online' OR 1=1--"]);

        $this->assertStatus(200, $response);
    }

    // =================================================================
    // XSS
    // =================================================================

    public function testDadoDoUsuarioEhEscapadoNaSaida(): void
    {
        $payload = '<script>alert("xss")</script>';

        ServerProvisionService::create([
            'name'     => 'VPS ' . $payload,
            'provider' => $payload,
        ], $this->adminId);

        $response = $this->request('GET', '/servidores');
        $html     = $response->content();

        $this->assertNotContainsString(
            '<script>alert("xss")</script>',
            $html,
            'O script nunca pode sair sem escape.'
        );
        $this->assertContainsString('&lt;script&gt;', $html, 'O conteudo deveria aparecer escapado.');
    }

    public function testAtributoComAspasEhEscapado(): void
    {
        ServerProvisionService::create([
            'name'        => 'VPS Atributo',
            'description' => '" onmouseover="alert(1)',
        ], $this->adminId);

        $server   = Server::findBy('name', 'VPS Atributo');
        $response = $this->request('GET', '/servidores/' . $server['id']);

        $this->assertNotContainsString('onmouseover="alert(1)"', $response->content());
    }

    // =================================================================
    // Rate limiting
    // =================================================================

    public function testRateLimiterBloqueiaAposOLimite(): void
    {
        $bucket = 'teste:rate:' . bin2hex(random_bytes(4));

        for ($i = 1; $i <= 3; $i++) {
            $result = RateLimiter::hit($bucket, 3, 60);
            $this->assertTrue($result['allowed'], "A requisicao {$i} deveria passar.");
        }

        $excedente = RateLimiter::hit($bucket, 3, 60);

        $this->assertFalse($excedente['allowed'], 'A quarta requisicao deveria ser barrada.');
        $this->assertTrue($excedente['retry_after'] > 0);
    }

    public function testBucketsDiferentesNaoSeAfetam(): void
    {
        $a = 'teste:a:' . bin2hex(random_bytes(4));
        $b = 'teste:b:' . bin2hex(random_bytes(4));

        RateLimiter::hit($a, 1, 60);
        RateLimiter::hit($a, 1, 60);

        $this->assertTrue(RateLimiter::hit($b, 1, 60)['allowed'], 'Um agente barulhento nao pode consumir a cota de outro.');
    }

    // =================================================================
    // Permissoes
    // =================================================================

    public function testOperadorNaoAcessaUsuarios(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $this->assertStatus(403, $this->request('GET', '/usuarios'));
    }

    public function testOperadorNaoAcessaConfiguracoes(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $this->assertStatus(403, $this->request('GET', '/configuracoes'));
    }

    public function testOperadorNaoAcessaAvisos(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $this->assertStatus(403, $this->request('GET', '/avisos'));
    }

    public function testOperadorNaoAcessaLogs(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $this->assertStatus(403, $this->request('GET', '/logs'));
    }

    public function testOperadorAcessaDashboardSitesEAlertas(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $this->assertStatus(200, $this->request('GET', '/'));
        $this->assertStatus(200, $this->request('GET', '/sites'));
        $this->assertStatus(200, $this->request('GET', '/alertas'));
    }

    public function testAdministradorAcessaTudo(): void
    {
        $this->loginAs($this->adminId, 'admin');

        foreach (['/', '/servidores', '/sites', '/metricas', '/alertas', '/usuarios', '/configuracoes', '/avisos', '/logs'] as $path) {
            $this->assertStatus(200, $this->request('GET', $path), "Falhou em {$path}.");
        }
    }

    // =================================================================
    // Ausencia de execucao remota (secao 41)
    // =================================================================

    public function testParametrosDeComandoNaoTemEfeito(): void
    {
        // A V1 nao possui execucao remota. Estes parametros precisam ser
        // simplesmente ignorados, nunca interpretados.
        foreach (['command', 'exec', 'cmd', 'shell', 'run'] as $parametro) {
            $response = $this->request('GET', '/servidores', [], [], [$parametro => 'whoami']);

            $this->assertStatus(200, $response, "O parametro ?{$parametro}= nao deveria ter efeito algum.");
            $this->assertNotContainsString('uid=', $response->content());
        }
    }

    public function testNaoExisteRotaDeExecucaoRemota(): void
    {
        $rotasProibidas = [
            '/servidores/1/reiniciar',
            '/servidores/1/comando',
            '/servidores/1/terminal',
            '/servidores/1/ssh',
            '/api/v1/agent/command',
            '/api/v1/agent/exec',
        ];

        foreach ($rotasProibidas as $rota) {
            $response = $this->request('GET', $rota);

            $this->assertTrue(
                \in_array($response->status(), [404, 405], true),
                "A rota {$rota} nao deveria existir (status {$response->status()})."
            );
        }
    }

    // =================================================================
    // Logs
    // =================================================================

    public function testAuditoriaNaoGravaSegredos(): void
    {
        AuditService::log('teste.redacao', 'Evento de teste', [
            'context' => [
                'token'    => 'cvps_1_' . str_repeat('a', 64),
                'password' => 'SenhaSuperSecreta',
                'campo_ok' => 'valor visivel',
            ],
        ]);

        $log = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'teste.redacao'");

        $this->assertNotNull($log);

        $context = (string) $log['context'];

        $this->assertNotContainsString('SenhaSuperSecreta', $context, 'Senha nunca pode ir para o log.');
        $this->assertNotContainsString(str_repeat('a', 64), $context, 'Token completo nunca pode ir para o log.');
        $this->assertContainsString('valor visivel', $context, 'Os demais campos devem continuar legiveis.');
    }

    public function testTokenRegeneradoRegistraApenasOPrefixo(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Auditado'], $this->adminId);
        $novo    = ServerProvisionService::regenerateToken($created['server_id'], $this->adminId);

        AuditService::tokenRegenerated($created['server_id'], 'VPS Auditado', substr($novo['token'], 0, 20));

        $log = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'token.regenerated'");

        $this->assertNotNull($log);
        $this->assertNotContainsString($novo['token'], (string) json_encode($log), 'O token completo nao pode aparecer.');
    }
}
