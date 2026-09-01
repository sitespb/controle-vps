<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerService;
use App\Models\Site;
use App\Models\SslCertificate;
use App\Models\User;
use App\Services\ServerProvisionService;
use App\Services\TokenService;
use Tests\TestCase;

/**
 * API dos agentes: autenticacao, assinatura, replay e recepcao de dados
 * (secoes 5, 9 e 43 do PLAN).
 */
final class AgentApiTest extends TestCase
{
    private int $serverId = 0;

    private string $token = '';

    public function name(): string
    {
        return 'API dos agentes';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        Database::statement('DELETE FROM users');
        $this->truncate('agent_nonces', 'api_rate_limits', 'audit_logs');

        $adminId = User::create([
            'name'          => 'Admin',
            'email'         => 'admin@teste.local',
            'password_hash' => User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $created = ServerProvisionService::create([
            'name'     => 'VPS do Agente',
            'provider' => 'Provedor de Teste',
            'ip'       => '203.0.113.77',
        ], $adminId);

        $this->serverId = $created['server_id'];
        $this->token    = $created['token'];

        $this->logout();
    }

    // -----------------------------------------------------------------
    // Auxiliares de assinatura
    // -----------------------------------------------------------------

    /**
     * Monta os cabecalhos exatamente como o agente faz.
     *
     * @return array<string,string>
     */
    private function signedHeaders(
        string $body,
        ?int $serverId = null,
        ?string $token = null,
        ?int $timestamp = null,
        ?string $nonce = null
    ): array {
        $serverId  = $serverId ?? $this->serverId;
        $token     = $token ?? $this->token;
        $timestamp = $timestamp ?? time();
        $nonce     = $nonce ?? bin2hex(random_bytes(16));

        $key       = hash('sha256', $token);
        $canonical = TokenService::canonicalString($serverId, $timestamp, $nonce, $body);
        $signature = hash_hmac('sha256', $canonical, $key);

        return [
            'x-server-id' => (string) $serverId,
            'x-timestamp' => (string) $timestamp,
            'x-nonce'     => $nonce,
            'x-signature' => $signature,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function postAsAgent(string $path, array $payload, array $overrideHeaders = []): \App\Core\Response
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->jsonRequest('POST', $path, $body, $this->signedHeaders($body) + $overrideHeaders);
    }

    // -----------------------------------------------------------------
    // Autenticacao
    // -----------------------------------------------------------------

    public function testHeartbeatComAssinaturaValida(): void
    {
        $response = $this->postAsAgent('/api/v1/agent/heartbeat', [
            'agent_version' => '1.0.0',
            'system'        => [
                'hostname'   => 'vps-teste.exemplo.com.br',
                'os_name'    => 'Ubuntu',
                'os_version' => '22.04.4 LTS',
                'kernel'     => '5.15.0-119-generic',
                'arch'       => 'x86_64',
                'cpu_cores'  => 4,
                'uptime'     => 864000,
                'public_ip'  => '203.0.113.77',
            ],
        ]);

        $this->assertStatus(200, $response);

        $json = $this->decodeJson($response);
        $this->assertTrue($json['ok'] === true);

        $server = Server::find($this->serverId);
        $this->assertEquals('online', $server['status'], 'O heartbeat deveria marcar o servidor como online.');
        $this->assertEquals('Ubuntu', $server['os_name']);
        $this->assertEquals('vps-teste.exemplo.com.br', $server['hostname']);
        $this->assertNotNull($server['last_seen_at']);
    }

    public function testRequisicaoSemCabecalhosEhRecusada(): void
    {
        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', '{}');

        $this->assertStatus(400, $response);

        $json = $this->decodeJson($response);
        $this->assertFalse($json['ok']);
    }

    public function testAssinaturaInvalidaEhRecusada(): void
    {
        $body    = '{"agent_version":"1.0.0"}';
        $headers = $this->signedHeaders($body);

        // Assinatura de tamanho correto, porem errada.
        $headers['x-signature'] = str_repeat('a', 64);

        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(401, $response);
        $this->assertContainsString('signature_mismatch', $response->content());
    }

    public function testCorpoAlteradoAposAssinaturaEhRecusado(): void
    {
        $original = '{"cpu":{"usage":10}}';
        $headers  = $this->signedHeaders($original);

        // A assinatura cobre o corpo: trocar 10 por 90 tem que invalidar.
        $adulterado = '{"cpu":{"usage":90}}';

        $response = $this->jsonRequest('POST', '/api/v1/agent/metrics', $adulterado, $headers);

        $this->assertStatus(401, $response);
        $this->assertContainsString('signature_mismatch', $response->content());
    }

    public function testTokenDeOutroServidorNaoFunciona(): void
    {
        $outro = ServerProvisionService::create(['name' => 'Outro VPS'], null);

        $body = '{"agent_version":"1.0.0"}';

        // Assina com o token do outro servidor, mas declara o id deste.
        $headers = $this->signedHeaders($body, $this->serverId, $outro['token']);

        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(401, $response);
    }

    public function testTokenRevogadoDeixaDeFuncionar(): void
    {
        $antigo = $this->token;

        ServerProvisionService::regenerateToken($this->serverId, null);

        $body     = '{"agent_version":"1.0.0"}';
        $headers  = $this->signedHeaders($body, $this->serverId, $antigo);
        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(401, $response, 'O token revogado nao pode mais autenticar.');
    }

    public function testTimestampAntigoEhRecusado(): void
    {
        $body    = '{"agent_version":"1.0.0"}';
        $headers = $this->signedHeaders($body, null, null, time() - 3600);

        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(401, $response);
        $this->assertContainsString('stale_timestamp', $response->content());
    }

    public function testTimestampNoFuturoEhRecusado(): void
    {
        $body    = '{"agent_version":"1.0.0"}';
        $headers = $this->signedHeaders($body, null, null, time() + 3600);

        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(401, $response);
    }

    public function testReplayDaMesmaRequisicaoEhBloqueado(): void
    {
        $body      = '{"agent_version":"1.0.0"}';
        $timestamp = time();
        $nonce     = bin2hex(random_bytes(16));
        $headers   = $this->signedHeaders($body, null, null, $timestamp, $nonce);

        $primeira = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);
        $this->assertStatus(200, $primeira);

        // Exatamente os mesmos bytes e cabecalhos: um atacante que capturou
        // o trafego nao pode reenviar.
        $segunda = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(409, $segunda);
        $this->assertContainsString('replay_detected', $segunda->content());
    }

    public function testServidorInexistenteEhRecusado(): void
    {
        $body    = '{"agent_version":"1.0.0"}';
        $headers = $this->signedHeaders($body, 999999, $this->token);

        $response = $this->jsonRequest('POST', '/api/v1/agent/heartbeat', $body, $headers);

        $this->assertStatus(401, $response);
        $this->assertContainsString('no_active_token', $response->content());
    }

    // -----------------------------------------------------------------
    // Recepcao de dados
    // -----------------------------------------------------------------

    public function testRecebimentoDeMetricas(): void
    {
        $response = $this->postAsAgent('/api/v1/agent/metrics', [
            'cpu'    => ['usage' => 34.5, 'cores' => 4],
            'memory' => ['total' => 8589934592, 'used' => 4294967296, 'available' => 4294967296],
            'swap'   => ['total' => 2147483648, 'used' => 214748364],
            'disk'   => ['total' => 171798691840, 'used' => 85899345920, 'free' => 85899345920],
            'load'   => ['1' => 1.25, '5' => 1.1, '15' => 0.95],
            'uptime' => 864000,
        ]);

        $this->assertStatus(200, $response);

        $metric = ServerMetric::latestFor($this->serverId);

        $this->assertNotNull($metric);
        $this->assertEquals('34.50', (string) $metric['cpu_usage']);
        // Percentual calculado quando o agente nao envia: 4 GiB de 8 GiB.
        $this->assertEquals('50.00', (string) $metric['ram_percent']);
        $this->assertEquals('50.00', (string) $metric['disk_percent']);
        $this->assertEquals('1.25', (string) $metric['load_1']);
    }

    public function testMetricasForaDeFaixaSaoNormalizadas(): void
    {
        $this->postAsAgent('/api/v1/agent/metrics', [
            'cpu'    => ['usage' => 250],     // impossivel
            'memory' => ['percent' => -10],   // impossivel
            'disk'   => ['percent' => 'abc'], // nao numerico
        ]);

        $metric = ServerMetric::latestFor($this->serverId);

        $this->assertEquals('100.00', (string) $metric['cpu_usage'], 'CPU deveria ser limitada a 100.');
        $this->assertEquals('0.00', (string) $metric['ram_percent'], 'RAM negativa deveria virar 0.');
        $this->assertNull($metric['disk_percent'], 'Valor nao numerico deveria virar null, nao 0.');
    }

    public function testDescobertaDeSites(): void
    {
        $response = $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [
                [
                    'domain'             => 'exemplo.com.br',
                    'url'                => 'https://exemplo.com.br',
                    'http_status'        => 200,
                    'response_time'      => 183,
                    'https_available'    => true,
                    'ip'                 => '203.0.113.77',
                    'php_version'        => '8.2',
                    'wordpress_detected' => true,
                    'wordpress_version'  => '6.7.1',
                    'ssl'                => [
                        'issuer'      => "Let's Encrypt",
                        'valid_from'  => date('Y-m-d', strtotime('-30 days')),
                        'valid_until' => date('Y-m-d', strtotime('+60 days')),
                    ],
                ],
                [
                    'domain'      => 'loja.com.br',
                    'http_status' => 503,
                ],
            ],
        ]);

        $this->assertStatus(200, $response);

        $json = $this->decodeJson($response);
        $this->assertEquals(2, $json['data']['received']);
        $this->assertEquals(2, $json['data']['created']);

        $site = Site::findByServerAndDomain($this->serverId, 'exemplo.com.br');
        $this->assertNotNull($site);
        $this->assertEquals('online', $site['status']);
        $this->assertEquals('8.2', $site['php_version']);
        $this->assertEquals(1, (int) $site['wordpress_detected']);
        $this->assertEquals('6.7.1', $site['wordpress_version']);

        // 503 => offline (secao 17).
        $loja = Site::findByServerAndDomain($this->serverId, 'loja.com.br');
        $this->assertEquals('offline', $loja['status']);

        // O certificado tem que ter sido gravado e classificado.
        $cert = SslCertificate::forSite((int) $site['id']);
        $this->assertNotNull($cert);
        $this->assertEquals('valid', $cert['status']);
        $this->assertEquals(60, (int) $cert['days_remaining']);
    }

    public function testReenvioDaMesmaListaAtualizaSemDuplicar(): void
    {
        $payload = ['sites' => [['domain' => 'idempotente.com.br', 'http_status' => 200]]];

        $this->postAsAgent('/api/v1/agent/sites', $payload);
        $this->postAsAgent('/api/v1/agent/sites', $payload);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM sites WHERE server_id = ? AND domain = ?',
            [$this->serverId, 'idempotente.com.br']
        );

        $this->assertEquals(1, $total, 'A chave unica (server_id, domain) deveria evitar duplicacao.');

        // Mas o historico registra as duas verificacoes.
        $site   = Site::findByServerAndDomain($this->serverId, 'idempotente.com.br');
        $checks = (int) Database::scalar('SELECT COUNT(*) FROM site_checks WHERE site_id = ?', [$site['id']]);
        $this->assertEquals(2, $checks);
    }

    public function testDominioInvalidoEhIgnoradoSemDerrubarOLote(): void
    {
        $response = $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [
                ['domain' => 'valido.com.br', 'http_status' => 200],
                ['domain' => 'isso nao e um dominio', 'http_status' => 200],
                ['domain' => '', 'http_status' => 200],
                ['domain' => 'tambem-valido.com.br', 'http_status' => 200],
            ],
        ]);

        $this->assertStatus(200, $response);

        $json = $this->decodeJson($response);
        $this->assertEquals(2, $json['data']['created'], 'Os dois validos deveriam ser gravados.');
        $this->assertEquals(2, $json['data']['skipped'], 'Os dois invalidos deveriam ser descartados.');
    }

    public function testDominioQueSomeNaoEhApagado(): void
    {
        $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [
                ['domain' => 'permanece.com.br', 'http_status' => 200],
                ['domain' => 'sumira.com.br', 'http_status' => 200],
            ],
        ]);

        // Segunda coleta traz apenas um dos dois.
        $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [['domain' => 'permanece.com.br', 'http_status' => 200]],
        ]);

        $sumido = Site::findByServerAndDomain($this->serverId, 'sumira.com.br');

        $this->assertNotNull($sumido, 'O registro nao pode ser apagado (secao 21).');
        $this->assertEquals(0, (int) $sumido['discovered'], 'Deveria estar marcado como nao descoberto.');
    }

    public function testDominioQueSomeTemSeusAlertasEncerrados(): void
    {
        // Primeira coleta: um dominio com certificado vencido abre o alerta.
        $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [
                ['domain' => 'permanece.com.br', 'http_status' => 200],
                [
                    'domain'      => 'excluido.com.br',
                    'http_status' => 200,
                    'ssl'         => [
                        'issuer'      => "Let's Encrypt",
                        'valid_until' => date('Y-m-d', strtotime('-5 days')),
                    ],
                ],
            ],
        ]);

        $site = Site::findByServerAndDomain($this->serverId, 'excluido.com.br');
        $this->assertNotNull($site);

        $abertos = Database::select(
            "SELECT type FROM alerts WHERE site_id = ? AND status IN ('open','acknowledged')",
            [(int) $site['id']]
        );

        $this->assertCount(1, $abertos, 'A primeira coleta deveria ter aberto o alerta de SSL expirado.');
        $this->assertEquals('ssl_expired', $abertos[0]['type']);

        // Segunda coleta: o dominio foi removido do servidor.
        $response = $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [['domain' => 'permanece.com.br', 'http_status' => 200]],
        ]);

        $this->assertStatus(200, $response);

        $json = $this->decodeJson($response);
        $this->assertEquals(1, $json['data']['undiscovered'], 'Um dominio deveria ter sido invalidado.');
        $this->assertEquals(1, $json['data']['alerts_resolved'], 'O alerta orfao deveria ter sido encerrado.');

        $aindaAbertos = Database::select(
            "SELECT id FROM alerts WHERE site_id = ? AND status IN ('open','acknowledged')",
            [(int) $site['id']]
        );

        $this->assertCount(0, $aindaAbertos, 'Site removido nao pode continuar com alerta aberto.');

        $resolvido = Database::selectOne(
            'SELECT status, resolved_at FROM alerts WHERE site_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $site['id']]
        );

        $this->assertEquals('resolved', $resolvido['status'], 'O alerta deveria estar resolvido, nao apagado.');
        $this->assertNotNull($resolvido['resolved_at'], 'A data de resolucao precisa ser gravada.');
    }

    public function testAlertaDeServidorNaoEhAfetadoPorSiteRemovido(): void
    {
        $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [
                ['domain' => 'permanece.com.br', 'http_status' => 200],
                ['domain' => 'excluido.com.br', 'http_status' => 200],
            ],
        ]);

        // Alerta de servidor, sem site_id: nao pode ser tocado pela limpeza.
        \App\Services\AlertService::raise(
            \App\Models\Alert::TYPE_SERVER_DISK_HIGH,
            'Disco cheio',
            'Disco em 94%.',
            ['server_id' => $this->serverId, 'severity' => 'critical', 'value' => 94.0]
        );

        $this->postAsAgent('/api/v1/agent/sites', [
            'sites' => [['domain' => 'permanece.com.br', 'http_status' => 200]],
        ]);

        $servidor = Database::selectOne(
            "SELECT status FROM alerts
             WHERE server_id = ? AND site_id IS NULL AND type = 'server_disk_high'
             ORDER BY id DESC LIMIT 1",
            [$this->serverId]
        );

        $this->assertNotNull($servidor, 'O alerta de servidor deveria continuar existindo.');
        $this->assertEquals('open', $servidor['status'], 'Alerta de servidor nao pode ser encerrado por site removido.');
    }

    public function testRecebimentoDeServicos(): void
    {
        $response = $this->postAsAgent('/api/v1/agent/services', [
            'services' => [
                ['name' => 'openlitespeed', 'label' => 'OpenLiteSpeed', 'status' => 'running', 'version' => '1.7.19'],
                ['name' => 'mariadb', 'label' => 'MariaDB', 'status' => 'running', 'version' => '10.11.6'],
                ['name' => 'redis', 'label' => 'Redis', 'status' => 'not_installed', 'version' => null],
                ['name' => 'cyberpanel', 'label' => 'CyberPanel', 'status' => 'running', 'version' => '2.3.7'],
            ],
        ]);

        $this->assertStatus(200, $response);

        $services = ServerService::forServer($this->serverId);
        $this->assertCount(4, $services);

        $byName = [];
        foreach ($services as $service) {
            $byName[$service['name']] = $service;
        }

        $this->assertEquals('running', $byName['openlitespeed']['status']);
        $this->assertEquals('1.7.19', $byName['openlitespeed']['version']);

        // Servico ausente e estado legitimo, nao erro (secao 6).
        $this->assertEquals('not_installed', $byName['redis']['status']);

        // A versao do CyberPanel sobe para a tabela de servidores.
        $server = Server::find($this->serverId);
        $this->assertEquals('2.3.7', $server['cyberpanel_version']);
    }

    public function testStatusDeServicoDesconhecidoEhNormalizado(): void
    {
        $this->postAsAgent('/api/v1/agent/services', [
            'services' => [['name' => 'openlitespeed', 'status' => 'valor-invalido']],
        ]);

        $services = ServerService::forServer($this->serverId);

        $this->assertEquals('unknown', $services[0]['status']);
    }

    public function testPayloadMalformadoRetorna422(): void
    {
        $body    = (string) json_encode(['sites' => 'isso deveria ser uma lista']);
        $headers = $this->signedHeaders($body);

        $response = $this->jsonRequest('POST', '/api/v1/agent/sites', $body, $headers);

        $this->assertStatus(422, $response);
    }

    public function testApiDeAgenteNaoAceitaGet(): void
    {
        $response = $this->request('GET', '/api/v1/agent/heartbeat', [], ['accept' => 'application/json']);

        $this->assertStatus(405, $response, 'A API de agentes so aceita POST.');
    }

    public function testRespostaNaoCarregaNenhumComando(): void
    {
        $response = $this->postAsAgent('/api/v1/agent/heartbeat', ['agent_version' => '1.0.0']);

        $json = $this->decodeJson($response);
        $data = $json['data'];

        // A garantia central da V1: a resposta e so confirmacao e um numero.
        foreach (['command', 'cmd', 'exec', 'script', 'shell', 'run', 'eval', 'path', 'file'] as $forbidden) {
            $this->assertFalse(
                \array_key_exists($forbidden, $data),
                "A resposta do painel nunca pode conter o campo \"{$forbidden}\"."
            );
        }

        $this->assertTrue(is_numeric($data['next_interval']), 'next_interval deveria ser numerico.');
    }

    public function testEndpointDeSaudeEhPublicoENaoVazaDados(): void
    {
        $response = $this->request('GET', '/api/v1/health', [], ['accept' => 'application/json']);

        $this->assertStatus(200, $response);

        $json = $this->decodeJson($response);
        $this->assertEquals(['status' => 'ok'], $json['data']);
    }

    public function testApiDoPainelExigeSessao(): void
    {
        $this->logout();

        $response = $this->request('GET', '/api/v1/servers', [], ['accept' => 'application/json']);

        $this->assertStatus(401, $response, 'Endpoint administrativo nao pode ficar aberto.');
    }

    /**
     * As tres pontas que declaram a versao do agente precisam concordar.
     *
     * Sao arquivos de naturezas diferentes - PHP, shell e config - e por isso
     * o desalinhamento passa despercebido: nada quebra, o agente roda, e o
     * painel apenas mostra um numero errado. Foi o que aconteceu ate a v1.2.1,
     * com os quatro servidores reportando "v1.0.0" durante um deploy em que
     * saber quem ja tinha atualizado era justamente o que importava.
     */
    public function testVersaoDoAgenteAcompanhaATagPublicada(): void
    {
        $agente = (string) file_get_contents(\BASE_PATH . '/agent/agent.php');
        $script = (string) file_get_contents(\BASE_PATH . '/agent/install.sh');
        $config = (string) file_get_contents(\BASE_PATH . '/config/monitoring.php');

        $this->assertEquals(
            1,
            preg_match("/const AGENT_VERSION = '([^']+)';/", $agente, $a),
            'AGENT_VERSION nao encontrado em agent/agent.php.'
        );

        $this->assertEquals(
            1,
            preg_match('/^AGENT_REF="([^"]+)"/m', $script, $b),
            'AGENT_REF nao encontrado em agent/install.sh.'
        );

        $this->assertEquals(
            1,
            preg_match("/'agent_ref'\s*=>\s*Env::get\('AGENT_REF',\s*'([^']+)'\)/", $config, $c),
            'Padrao de agent_ref nao encontrado em config/monitoring.php.'
        );

        $this->assertEquals(
            'v' . $a[1],
            $b[1],
            'A tag baixada pelo instalador precisa ser a versao que o agente declara.'
        );

        $this->assertEquals(
            $b[1],
            $c[1],
            'O painel monta o comando de instalacao com esta tag; ela nao pode divergir do instalador.'
        );
    }
}
