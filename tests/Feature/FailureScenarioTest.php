<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Database;
use App\Models\Alert;
use App\Models\Server;
use App\Models\Site;
use App\Models\SslCertificate;
use App\Services\AlertService;
use App\Services\HttpStatusService;
use App\Services\MonitoringService;
use App\Services\RateLimiter;
use App\Services\ServerProvisionService;
use App\Services\SiteIngestService;
use App\Services\SslService;
use Tests\TestCase;

/**
 * Situacoes de falha exigidas pela secao 43 do PLAN.
 *
 * O objetivo aqui nao e o caminho feliz: e provar que o sistema degrada com
 * elegancia quando algo do lado de fora quebra.
 */
final class FailureScenarioTest extends TestCase
{
    private int $serverId = 0;

    public function name(): string
    {
        return 'Cenarios de falha';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        $this->truncate('audit_logs');

        $created        = ServerProvisionService::create(['name' => 'VPS Instavel'], null);
        $this->serverId = $created['server_id'];
    }

    public function testServidorSemInternetVirouOfflineSemErro(): void
    {
        // Sem internet o agente nao envia nada: para o painel, e silencio.
        Database::statement(
            'UPDATE servers SET status = ?, last_seen_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?',
            [Server::STATUS_ONLINE, $this->serverId]
        );

        $result = MonitoringService::detectOfflineServers();

        $this->assertEquals(0, $result['failed']);
        $this->assertEquals('offline', Server::find($this->serverId)['status']);
    }

    public function testSiteIndisponivelNaoImpedeOsDemaisDoLote(): void
    {
        $result = SiteIngestService::store($this->serverId, [
            ['domain' => 'ok-um.com.br', 'http_status' => 200, 'response_time' => 120],
            ['domain' => 'quebrado.com.br', 'http_status' => 503, 'error' => 'Service Unavailable'],
            ['domain' => 'sem-resposta.com.br', 'http_status' => null, 'error' => 'Could not resolve host'],
            ['domain' => 'ok-dois.com.br', 'http_status' => 200, 'response_time' => 95],
        ]);

        $this->assertEquals(4, $result['received']);
        $this->assertEquals(4, $result['created'], 'Todos os dominios validos devem ser gravados.');
        $this->assertEquals(2, $result['offline']);

        $this->assertEquals('online', Site::findByServerAndDomain($this->serverId, 'ok-dois.com.br')['status']);
    }

    public function testTimeoutEhTratadoComoOfflineComMensagem(): void
    {
        SiteIngestService::store($this->serverId, [[
            'domain'      => 'lento.com.br',
            'http_status' => null,
            'error'       => 'Operation timed out after 10001 milliseconds with 0 bytes received',
        ]]);

        $site = Site::findByServerAndDomain($this->serverId, 'lento.com.br');

        $this->assertEquals('offline', $site['status']);
        $this->assertContainsString('timed out', (string) $site['last_error']);
        $this->assertNull($site['http_status'], 'Sem resposta HTTP nao pode virar 0.');
    }

    public function testCertificadoInvalidoNaoQuebraAColeta(): void
    {
        SiteIngestService::store($this->serverId, [[
            'domain'          => 'cert-ruim.com.br',
            'http_status'     => 200,
            'https_available' => true,
            'ssl'             => ['error' => 'unable to get local issuer certificate'],
        ]]);

        $site = Site::findByServerAndDomain($this->serverId, 'cert-ruim.com.br');

        $this->assertNotNull($site);
        $this->assertEquals('online', $site['status'], 'Certificado ruim nao derruba o site.');

        $cert = SslCertificate::forSite((int) $site['id']);
        $this->assertEquals('unknown', $cert['status'], 'Sem data de validade, o SSL fica cinza.');
        $this->assertContainsString('issuer', (string) $cert['error']);
    }

    public function testCertificadoExpiradoGeraAlertaCritico(): void
    {
        SiteIngestService::store($this->serverId, [[
            'domain'          => 'expirado.com.br',
            'http_status'     => 200,
            'https_available' => true,
            'ssl'             => [
                'issuer'      => "Let's Encrypt",
                'valid_from'  => date('Y-m-d', strtotime('-120 days')),
                'valid_until' => date('Y-m-d', strtotime('-15 days')),
            ],
        ]]);

        $site = Site::findByServerAndDomain($this->serverId, 'expirado.com.br');
        $cert = SslCertificate::forSite((int) $site['id']);

        $this->assertEquals('expired', $cert['status']);
        $this->assertTrue((int) $cert['days_remaining'] < 0);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SSL_EXPIRED, $this->serverId, (int) $site['id'])
        );

        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert['severity']);
    }

    public function testDiscoCheioGeraAlertaCritico(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Instavel', ['disk_percent' => 99.2]);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_DISK_HIGH, $this->serverId, null)
        );

        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert['severity']);
        $this->assertEquals('99.20', (string) $alert['metric_value']);
    }

    public function testColetaVaziaNaoInvalidaOsSitesJaConhecidos(): void
    {
        // Cenario real: a descoberta falhou (MySQL do CyberPanel parado) e o
        // agente enviou lista vazia. Apagar tudo seria um desastre.
        SiteIngestService::store($this->serverId, [
            ['domain' => 'importante.com.br', 'http_status' => 200],
        ]);

        $result = SiteIngestService::store($this->serverId, []);

        $this->assertEquals(0, $result['received']);

        $site = Site::findByServerAndDomain($this->serverId, 'importante.com.br');
        $this->assertNotNull($site);
        $this->assertEquals(1, (int) $site['discovered'], 'Lista vazia nao pode invalidar os dominios conhecidos.');
    }

    public function testPayloadComTiposErradosNaoQuebra(): void
    {
        $result = SiteIngestService::store($this->serverId, [
            ['domain' => 'tipos.com.br', 'http_status' => 'duzentos', 'response_time' => 'rapido'],
            'isso nao e um array',
            ['sem_dominio' => true],
            123,
        ]);

        $this->assertEquals(4, $result['received']);
        $this->assertEquals(1, $result['created']);
        $this->assertEquals(3, $result['skipped']);

        $site = Site::findByServerAndDomain($this->serverId, 'tipos.com.br');
        $this->assertNull($site['http_status'], 'Texto no lugar de numero vira null.');
        $this->assertNull($site['response_time']);
    }

    public function testDominioAcimaDoLimiteDeCaracteresEhDescartado(): void
    {
        $gigante = str_repeat('a', 200) . '.com.br';

        $result = SiteIngestService::store($this->serverId, [['domain' => $gigante, 'http_status' => 200]]);

        $this->assertEquals(1, $result['skipped']);
    }

    public function testLoteAcimaDoLimiteEhTruncadoSemErro(): void
    {
        $limite = (int) Config::get('monitoring.agent_api.max_sites', 500);
        $sites  = [];

        for ($i = 0; $i < $limite + 25; $i++) {
            $sites[] = ['domain' => "site{$i}.exemplo.com.br", 'http_status' => 200];
        }

        $result = SiteIngestService::store($this->serverId, $sites);

        $this->assertEquals($limite, $result['received'], 'O excedente deveria ser cortado, nao causar erro.');
    }

    public function testRateLimiterIndisponivelNaoBloqueiaAColeta(): void
    {
        // Se a tabela de controle sumir, a decisao correta e DEIXAR PASSAR:
        // barrar a coleta legitima seria pior que perder o controle de limite.
        Database::connection()->exec('DROP TABLE IF EXISTS api_rate_limits');

        $result = RateLimiter::hit('bucket-sem-tabela', 10, 60);

        $this->assertTrue($result['allowed'], 'Falha no rate limiter nao pode barrar o agente.');

        // Recria para nao afetar os proximos testes.
        (new \App\Core\Migrator())->fresh();
    }

    public function testStatusHttpDesconhecidoNaoDerrubaAClassificacao(): void
    {
        // Codigos fora do padrao acontecem com proxies mal configurados.
        foreach ([0, 1, 99, 999] as $codigo) {
            $status = HttpStatusService::classify($codigo === 0 ? null : $codigo, 100, $codigo === 0 ? 'erro' : null);

            $this->assertTrue(
                \in_array($status, [Site::STATUS_ONLINE, Site::STATUS_WARNING, Site::STATUS_OFFLINE, Site::STATUS_UNKNOWN], true),
                "Codigo {$codigo} produziu um status invalido: {$status}"
            );
        }
    }

    public function testNormalizacaoDeSslComDataInvalidaNaoQuebra(): void
    {
        $normalizado = SslService::normalize([
            'valid_from'  => 'data-invalida',
            'valid_until' => 'tambem-invalida',
        ]);

        $this->assertNull($normalizado['valid_until']);
        $this->assertEquals('unknown', $normalizado['status']);
    }

    public function testPaginaDeErroEhRenderizadaParaRotaInexistente(): void
    {
        $response = $this->request('GET', '/rota-que-nao-existe');

        $this->assertStatus(404, $response);
        $this->assertContainsString('nao encontrada', mb_strtolower(strip_tags($response->content())));
    }

    public function testApiRespondeJsonEmVezDeHtmlNoErro(): void
    {
        $response = $this->request('GET', '/api/v1/rota-inexistente', [], ['accept' => 'application/json']);

        $this->assertStatus(404, $response);

        $json = $this->decodeJson($response);
        $this->assertFalse($json['ok']);
        $this->assertNotNull($json['error']['code']);
    }
}
