<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\ServerProvisionService;
use App\Services\TokenService;
use Tests\TestCase;

/**
 * Cadastro de servidor, geracao de token e permissoes
 * (secoes 11, 12, 23 e 43 do PLAN).
 */
final class ServerTest extends TestCase
{
    private int $adminId = 0;

    private int $operatorId = 0;

    public function name(): string
    {
        return 'Servidores e tokens';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        Database::statement('DELETE FROM users');
        $this->truncate('audit_logs');

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

    public function testCriacaoGeraIdentificacaoUnicaEToken(): void
    {
        $created = ServerProvisionService::create([
            'name'        => 'VPS de Teste',
            'provider'    => 'Provedor X',
            'hostname'    => 'teste.exemplo.com.br',
            'ip'          => '203.0.113.10',
            'description' => 'Servidor usado na suite de testes.',
        ], $this->adminId);

        $server = Server::find($created['server_id']);

        $this->assertNotNull($server, 'O servidor deveria existir no banco.');
        $this->assertEquals('VPS de Teste', $server['name']);
        $this->assertEquals(32, \strlen((string) $server['uid']), 'O UID deveria ter 32 caracteres.');
        $this->assertEquals('unknown', $server['status'], 'Servidor novo comeca sem status conhecido.');

        // Formato do token: cvps_<id>_<64 hex>
        $this->assertTrue(
            preg_match('/^cvps_' . $created['server_id'] . '_[a-f0-9]{64}$/', $created['token']) === 1,
            'Formato de token inesperado: ' . $created['token']
        );
    }

    public function testTokenEhGravadoApenasComoHash(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Hash'], $this->adminId);

        $stored = ServerToken::activeFor($created['server_id']);

        $this->assertNotNull($stored);
        $this->assertNotEquals($created['token'], $stored['token_hash']);
        $this->assertEquals(TokenService::hash($created['token']), $stored['token_hash']);

        // Nenhuma coluna da tabela pode conter o token em texto puro.
        $row = Database::selectOne('SELECT * FROM server_tokens WHERE id = ?', [$stored['id']]);
        $this->assertNotContainsString(
            $created['token'],
            (string) json_encode($row),
            'O token em texto puro nao pode existir no banco.'
        );
    }

    public function testRegenerarTokenInvalidaOAnterior(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Rotacao'], $this->adminId);
        $antigo  = $created['token'];

        $novo = ServerProvisionService::regenerateToken($created['server_id'], $this->adminId);

        $this->assertNotEquals($antigo, $novo['token']);

        // O hash antigo ainda existe na tabela, porem revogado.
        $antigoRow = Database::selectOne(
            'SELECT * FROM server_tokens WHERE token_hash = ?',
            [TokenService::hash($antigo)]
        );

        $this->assertNotNull($antigoRow);
        $this->assertNotNull($antigoRow['revoked_at'], 'O token anterior deveria estar revogado.');

        // E o ativo e o novo.
        $ativo = ServerToken::activeFor($created['server_id']);
        $this->assertEquals(TokenService::hash($novo['token']), $ativo['token_hash']);

        // Apenas um token ativo por servidor.
        $ativos = (int) Database::scalar(
            'SELECT COUNT(*) FROM server_tokens WHERE server_id = ? AND revoked_at IS NULL',
            [$created['server_id']]
        );
        $this->assertEquals(1, $ativos);
    }

    public function testExclusaoRemoveDadosAssociados(): void
    {
        $created  = ServerProvisionService::create(['name' => 'VPS Descartavel'], $this->adminId);
        $serverId = $created['server_id'];

        Database::insert('server_metrics', [
            'server_id'  => $serverId,
            'cpu_usage'  => 10.5,
            'created_at' => now_string(),
        ]);

        $siteId = Database::insert('sites', [
            'server_id'  => $serverId,
            'domain'     => 'descartavel.teste.br',
            'status'     => 'online',
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        ServerProvisionService::delete($serverId);

        $this->assertNull(Server::find($serverId));
        $this->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM server_metrics WHERE server_id = ?', [$serverId]));
        $this->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM sites WHERE id = ?', [$siteId]));
        $this->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM server_tokens WHERE server_id = ?', [$serverId]));
    }

    public function testCadastroPelaWebExigeCsrf(): void
    {
        $response = $this->request('POST', '/servidores', [
            'name' => 'Sem token CSRF',
            // _token ausente de proposito
        ]);

        // Sem CSRF valido o middleware redireciona sem gravar nada.
        $this->assertStatus(302, $response);
        $this->assertEquals(0, Server::count(['name' => 'Sem token CSRF']));
    }

    public function testCadastroPelaWebComCsrfValido(): void
    {
        $response = $this->request('POST', '/servidores', [
            '_token'   => $this->csrfToken(),
            'name'     => 'VPS Via Formulario',
            'provider' => 'Provedor Y',
            'ip'       => '203.0.113.25',
        ]);

        $this->assertStatus(302, $response);

        $server = Server::findBy('name', 'VPS Via Formulario');
        $this->assertNotNull($server, 'O servidor deveria ter sido criado.');
        $this->assertNotNull(ServerToken::activeFor((int) $server['id']), 'Um token deveria ter sido gerado.');
    }

    public function testValidacaoRecusaIpInvalido(): void
    {
        $response = $this->request('POST', '/servidores', [
            '_token' => $this->csrfToken(),
            'name'   => 'VPS IP Invalido',
            'ip'     => 'nao-e-um-ip',
        ]);

        $this->assertStatus(302, $response);
        $this->assertEquals(0, Server::count(['name' => 'VPS IP Invalido']));
    }

    public function testOperadorNaoPodeCadastrarServidor(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $response = $this->request('POST', '/servidores', [
            '_token' => $this->csrfToken(),
            'name'   => 'VPS Do Operador',
        ]);

        $this->assertStatus(403, $response, 'Operador nao deveria criar servidor.');
        $this->assertEquals(0, Server::count(['name' => 'VPS Do Operador']));
    }

    public function testOperadorVisualizaAListaDeServidores(): void
    {
        ServerProvisionService::create(['name' => 'VPS Visivel'], $this->adminId);

        $this->loginAs($this->operatorId, 'operator');

        $response = $this->request('GET', '/servidores');

        $this->assertStatus(200, $response, 'Operador deveria ter acesso de leitura.');
        $this->assertContainsString('VPS Visivel', $response->content());
    }

    public function testOperadorNaoVeOBotaoDeNovoServidor(): void
    {
        $this->loginAs($this->operatorId, 'operator');

        $response = $this->request('GET', '/servidores');

        $this->assertNotContainsString(
            '/servidores/novo',
            $response->content(),
            'A acao indisponivel nao deveria aparecer para o operador.'
        );
    }

    public function testTokenSoApareceUmaVezNaTelaDoAgente(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Token Unico'], $this->adminId);

        // Simula o que o controller grava na sessao logo apos o cadastro.
        $_SESSION['_new_token'] = [
            'server_id' => $created['server_id'],
            'token'     => $created['token'],
            'expires'   => time() + 900,
        ];

        $primeira = $this->request('GET', '/servidores/' . $created['server_id'] . '/agente');
        $this->assertContainsString($created['token'], $primeira->content(), 'A primeira visita deve exibir o token.');

        $segunda = $this->request('GET', '/servidores/' . $created['server_id'] . '/agente');
        $this->assertNotContainsString(
            $created['token'],
            $segunda->content(),
            'Recarregar a pagina nao pode exibir o token de novo.'
        );
    }

    public function testTelaDoAgenteMostraOComandoUnicoDeInstalacao(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Comando Unico'], $this->adminId);

        $response = $this->request('GET', '/servidores/' . $created['server_id'] . '/agente');
        $html     = $response->content();

        $this->assertStatus(200, $response);

        $this->assertContainsString(
            'raw.githubusercontent.com',
            $html,
            'A tela deve trazer o comando que baixa o instalador.'
        );

        $this->assertContainsString(
            'agent-watch',
            $html,
            'A tela deve trazer o bloco que acompanha o primeiro contato do agente.'
        );

        // O caminho do PHP nao pode ser chutado pelo painel: quem resolve e o
        // instalador, no servidor. Ver PROGRESS.md, bug B2.
        $this->assertNotContainsString(
            '/usr/bin/php',
            $html,
            'A tela nao pode sugerir um caminho de PHP que ela nao tem como conhecer.'
        );
    }

    public function testEstadoDoAgenteRespondeEmJson(): void
    {
        $created = ServerProvisionService::create(['name' => 'VPS Estado'], $this->adminId);

        $response = $this->request('GET', '/api/v1/servers/' . $created['server_id'] . '/agent-status');

        $this->assertStatus(200, $response);

        $json = $this->decodeJson($response);

        $this->assertTrue(
            \array_key_exists('last_seen_at', $json['data']),
            'O laco da tela compara last_seen_at: o campo precisa vir sempre.'
        );

        $this->assertNull(
            $json['data']['last_seen_at'],
            'Servidor recem-cadastrado ainda nao teve contato.'
        );
    }

    public function testEstadoDoAgenteDeServidorInexistenteDa404(): void
    {
        $response = $this->request('GET', '/api/v1/servers/999999/agent-status');

        $this->assertStatus(404, $response);
    }
}
