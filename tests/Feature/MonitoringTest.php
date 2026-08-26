<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Alert;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteCheck;
use App\Models\SslCertificate;
use App\Services\AlertService;
use App\Services\HttpStatusService;
use App\Services\MonitoringService;
use App\Services\RetentionService;
use App\Services\ServerProvisionService;
use App\Services\SslService;
use Tests\TestCase;

/**
 * Regras de monitoramento: status HTTP, SSL, alertas, deteccao de offline e
 * retencao (secoes 16 a 21, 28, 29 e 43 do PLAN).
 */
final class MonitoringTest extends TestCase
{
    private int $serverId = 0;

    public function name(): string
    {
        return 'Monitoramento e alertas';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        $this->truncate('audit_logs');

        $created        = ServerProvisionService::create(['name' => 'VPS Monitorado'], null);
        $this->serverId = $created['server_id'];
    }

    // =================================================================
    // Secao 17 - classificacao de status HTTP
    // =================================================================

    public function testHttp200EhOnline(): void
    {
        $this->assertEquals(Site::STATUS_ONLINE, HttpStatusService::classify(200, 150));
    }

    public function testHttp301EhOnline(): void
    {
        $this->assertEquals(Site::STATUS_ONLINE, HttpStatusService::classify(301, 90));
    }

    public function testHttp404NaoDerrubaOSite(): void
    {
        // Regra explicita da secao 17: 404 significa servidor no ar.
        $this->assertEquals(Site::STATUS_WARNING, HttpStatusService::classify(404, 120));
    }

    public function testHttp403NaoDerrubaOSite(): void
    {
        $this->assertEquals(Site::STATUS_WARNING, HttpStatusService::classify(403, 120));
    }

    public function testHttp500EhOffline(): void
    {
        $this->assertEquals(Site::STATUS_OFFLINE, HttpStatusService::classify(500, 800));
    }

    public function testHttp502E503SaoOffline(): void
    {
        $this->assertEquals(Site::STATUS_OFFLINE, HttpStatusService::classify(502, 800));
        $this->assertEquals(Site::STATUS_OFFLINE, HttpStatusService::classify(503, 800));
    }

    public function testTimeoutEhOffline(): void
    {
        $this->assertEquals(
            Site::STATUS_OFFLINE,
            HttpStatusService::classify(null, null, 'Operation timed out after 10000 milliseconds')
        );
    }

    public function testRespostaMuitoLentaEntraEmAtencao(): void
    {
        // 200, porem acima do limite de resposta lenta.
        $this->assertEquals(Site::STATUS_WARNING, HttpStatusService::classify(200, 9000));
    }

    public function testSemDadoNenhumFicaDesconhecido(): void
    {
        $this->assertEquals(Site::STATUS_UNKNOWN, HttpStatusService::classify(null, null, null));
    }

    // =================================================================
    // Secao 16 - classificacao de SSL
    // =================================================================

    public function testSslValidoPorMaisDe30Dias(): void
    {
        $this->assertEquals(SslCertificate::STATUS_VALID, SslCertificate::classify(90, 30));
    }

    public function testSslVencendoEmAte30Dias(): void
    {
        $this->assertEquals(SslCertificate::STATUS_EXPIRING, SslCertificate::classify(12, 30));
    }

    public function testSslExpirado(): void
    {
        $this->assertEquals(SslCertificate::STATUS_EXPIRED, SslCertificate::classify(-3, 30));
    }

    public function testSslSemDadoFicaDesconhecido(): void
    {
        $this->assertEquals(SslCertificate::STATUS_UNKNOWN, SslCertificate::classify(null, 30));
    }

    public function testNormalizacaoCalculaDiasRestantes(): void
    {
        $normalizado = SslService::normalize([
            'issuer'      => "Let's Encrypt",
            'valid_from'  => date('Y-m-d', strtotime('-15 days')),
            'valid_until' => date('Y-m-d', strtotime('+45 days')),
        ]);

        $this->assertEquals(45, $normalizado['days_remaining']);
        $this->assertEquals(SslCertificate::STATUS_VALID, $normalizado['status']);
    }

    public function testCertificadoExpiradoEhReconhecidoNaNormalizacao(): void
    {
        $normalizado = SslService::normalize([
            'valid_until' => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertTrue($normalizado['days_remaining'] < 0);
        $this->assertEquals(SslCertificate::STATUS_EXPIRED, $normalizado['status']);
    }

    // =================================================================
    // Secao 19 - limites de recursos
    // =================================================================

    public function testLimitesDeCpu(): void
    {
        $this->assertEquals('normal', threshold_level(55.0, 'cpu'));
        $this->assertEquals('warning', threshold_level(85.0, 'cpu'));
        $this->assertEquals('critical', threshold_level(95.0, 'cpu'));
    }

    public function testLimiteExatoDeAtencaoContaComoAtencao(): void
    {
        // 80% e o inicio da faixa de atencao, nao o fim da normal.
        $this->assertEquals('warning', threshold_level(80.0, 'disk'));
    }

    public function testSemMetricaOLimiteFicaDesconhecido(): void
    {
        $this->assertEquals('unknown', threshold_level(null, 'ram'));
    }

    // =================================================================
    // Secao 18 - alertas
    // =================================================================

    public function testAlertaDeDiscoAltoEhAberto(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 87.4]);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_DISK_HIGH, $this->serverId, null)
        );

        $this->assertNotNull($alert, 'O alerta de disco deveria existir.');
        $this->assertEquals('warning', $alert['severity']);
        $this->assertContainsString('87', (string) $alert['message']);
    }

    public function testAcimaDe90PorCentoOAlertaEhCritico(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['cpu_usage' => 95.0]);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_CPU_HIGH, $this->serverId, null)
        );

        $this->assertEquals('critical', $alert['severity']);
    }

    public function testAlertaRepetidoNaoDuplica(): void
    {
        // Simula 5 ciclos do agente com o mesmo problema.
        for ($i = 0; $i < 5; $i++) {
            AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 91.0]);
        }

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM alerts WHERE server_id = ? AND type = ?',
            [$this->serverId, Alert::TYPE_SERVER_DISK_HIGH]
        );

        $this->assertEquals(1, $total, 'Deveria existir UM alerta, nao cinco.');

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_DISK_HIGH, $this->serverId, null)
        );

        $this->assertEquals(5, (int) $alert['occurrences'], 'As reincidencias deveriam ser contadas.');
    }

    public function testAlertaSeResolveSozinhoQuandoNormaliza(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 92.0]);

        $aberto = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_DISK_HIGH, $this->serverId, null)
        );
        $this->assertNotNull($aberto);

        // O operador liberou espaco e a proxima coleta chega normalizada.
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 45.0]);

        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SERVER_DISK_HIGH, $this->serverId, null)),
            'Nao deveria restar alerta aberto.'
        );

        $resolvido = Alert::find((int) $aberto['id']);
        $this->assertEquals('resolved', $resolvido['status']);
        $this->assertNotNull($resolvido['resolved_at']);
    }

    public function testLinhaDoTempoDoAlertaEhRegistrada(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['ram_percent' => 93.0]);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_MEMORY_HIGH, $this->serverId, null)
        );

        $eventos = Database::select('SELECT * FROM alert_events WHERE alert_id = ?', [$alert['id']]);

        $this->assertCount(1, $eventos);
        $this->assertEquals('created', $eventos[0]['event']);
    }

    // =================================================================
    // Secao 28 - servidor offline
    // =================================================================

    public function testServidorSemHeartbeatEhMarcadoOffline(): void
    {
        // Ultimo contato ha 30 minutos; a tolerancia e de 10.
        Database::statement(
            'UPDATE servers SET status = ?, last_seen_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ?',
            [Server::STATUS_ONLINE, $this->serverId]
        );

        $result = MonitoringService::detectOfflineServers();

        $this->assertEquals(1, $result['went_offline']);
        $this->assertEquals('offline', Server::find($this->serverId)['status']);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SERVER_OFFLINE, $this->serverId, null)
        );
        $this->assertNotNull($alert, 'Deveria existir alerta de servidor offline.');
        $this->assertEquals('critical', $alert['severity']);
    }

    public function testServidorOfflineNaoGeraAlertaNovoACadaCiclo(): void
    {
        Database::statement(
            'UPDATE servers SET status = ?, last_seen_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ?',
            [Server::STATUS_ONLINE, $this->serverId]
        );

        // Tres passagens do cron com o servidor ainda mudo.
        MonitoringService::detectOfflineServers();
        MonitoringService::detectOfflineServers();
        MonitoringService::detectOfflineServers();

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM alerts WHERE server_id = ? AND type = ?',
            [$this->serverId, Alert::TYPE_SERVER_OFFLINE]
        );

        $this->assertEquals(1, $total, 'Nao pode acumular um alerta por ciclo.');
    }

    public function testServidorQueVoltaResolveOAlerta(): void
    {
        Database::statement(
            'UPDATE servers SET status = ?, last_seen_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ?',
            [Server::STATUS_ONLINE, $this->serverId]
        );

        MonitoringService::detectOfflineServers();
        $this->assertEquals('offline', Server::find($this->serverId)['status']);

        // O agente volta a reportar.
        Database::statement('UPDATE servers SET last_seen_at = NOW() WHERE id = ?', [$this->serverId]);

        $result = MonitoringService::detectOfflineServers();

        $this->assertEquals(1, $result['recovered']);
        $this->assertEquals('online', Server::find($this->serverId)['status']);
        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SERVER_OFFLINE, $this->serverId, null)),
            'O alerta deveria ter sido resolvido automaticamente.'
        );
    }

    public function testServidorRecemCadastradoNaoDerrubaOProcessamento(): void
    {
        // Servidor sem nenhum heartbeat: last_seen_at e NULL.
        $result = MonitoringService::detectOfflineServers();

        $this->assertEquals(0, $result['failed'], 'Nenhuma falha deveria ocorrer.');
    }

    public function testUmServidorComProblemaNaoImpedeOsDemais(): void
    {
        // Secao 32: a indisponibilidade de um VPS nao pode parar o resto.
        $outro = ServerProvisionService::create(['name' => 'VPS Saudavel'], null);

        Database::statement(
            'UPDATE servers SET status = ?, last_seen_at = DATE_SUB(NOW(), INTERVAL 40 MINUTE) WHERE id = ?',
            [Server::STATUS_ONLINE, $this->serverId]
        );
        Database::statement(
            'UPDATE servers SET status = ?, last_seen_at = NOW() WHERE id = ?',
            [Server::STATUS_ONLINE, $outro['server_id']]
        );

        $result = MonitoringService::detectOfflineServers();

        $this->assertEquals(1, $result['went_offline']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals('online', Server::find($outro['server_id'])['status'], 'O servidor saudavel nao pode ser afetado.');
    }

    // =================================================================
    // Secao 29 - site offline
    // =================================================================

    public function testSiteOfflineGeraAlertaERecuperacaoResolve(): void
    {
        $siteId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'loja.teste.com.br',
            'status'     => 'offline',
            'http_status' => 503,
            'discovered' => 1,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        AlertService::siteWentOffline($siteId, $this->serverId, 'loja.teste.com.br', 503, null);

        $alert = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SITE_OFFLINE, $this->serverId, $siteId)
        );

        $this->assertNotNull($alert);
        $this->assertContainsString('503', (string) $alert['message']);

        // O site volta.
        $resolvido = AlertService::siteCameBack($siteId, $this->serverId, 'loja.teste.com.br');

        $this->assertTrue($resolvido);
        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SITE_OFFLINE, $this->serverId, $siteId))
        );
    }

    public function testAlertaDeSslVencendoEExpirado(): void
    {
        $siteId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'ssl.teste.com.br',
            'status'     => 'online',
            'discovered' => 1,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        // 12 dias: atencao.
        AlertService::evaluateSsl($siteId, $this->serverId, 'ssl.teste.com.br', 12);

        $expiring = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SSL_EXPIRING, $this->serverId, $siteId)
        );
        $this->assertNotNull($expiring);
        $this->assertEquals('warning', $expiring['severity']);

        // 3 dias: critico.
        AlertService::evaluateSsl($siteId, $this->serverId, 'ssl.teste.com.br', 3);
        $critico = Alert::findOpenByFingerprint(
            Alert::fingerprint(Alert::TYPE_SSL_EXPIRING, $this->serverId, $siteId)
        );
        $this->assertEquals('critical', $critico['severity']);

        // Expirado: troca de tipo.
        AlertService::evaluateSsl($siteId, $this->serverId, 'ssl.teste.com.br', -2);

        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SSL_EXPIRING, $this->serverId, $siteId)),
            'O alerta de "vencendo" deveria fechar quando o certificado expira.'
        );
        $this->assertNotNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SSL_EXPIRED, $this->serverId, $siteId))
        );

        // Renovado: tudo se resolve.
        AlertService::evaluateSsl($siteId, $this->serverId, 'ssl.teste.com.br', 89);

        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SSL_EXPIRED, $this->serverId, $siteId))
        );
        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SSL_EXPIRING, $this->serverId, $siteId))
        );
    }

    public function testCronDeSslNaoReabreAlertaDeSiteRemovido(): void
    {
        // Site que ja saiu do servidor, com certificado expirado gravado.
        $siteId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'removido.teste.com.br',
            'status'     => 'online',
            'discovered' => 0,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        SslCertificate::upsert($siteId, SslService::normalize([
            'issuer'      => 'Autoridade de Teste',
            'valid_until' => date('Y-m-d', strtotime('-10 days')),
        ]));

        // Um site normal, para provar que o filtro nao silencia quem existe.
        $ativoId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'ativo.teste.com.br',
            'status'     => 'online',
            'discovered' => 1,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        SslCertificate::upsert($ativoId, SslService::normalize([
            'issuer'      => 'Autoridade de Teste',
            'valid_until' => date('Y-m-d', strtotime('-10 days')),
        ]));

        SslService::refreshAll();

        $this->assertNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SSL_EXPIRED, $this->serverId, $siteId)),
            'O cron nao pode reabrir alerta de SSL de site que nao existe mais no servidor.'
        );

        $this->assertNotNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SSL_EXPIRED, $this->serverId, $ativoId)),
            'O site ativo com certificado expirado continua alertando normalmente.'
        );
    }

    // =================================================================
    // Secao 21 - retencao
    // =================================================================

    public function testLimpezaRemoveMetricasAntigasEPreservaAsRecentes(): void
    {
        Database::statement(
            'INSERT INTO server_metrics (server_id, cpu_usage, created_at)
             VALUES (?, 10, DATE_SUB(NOW(), INTERVAL 45 DAY))',
            [$this->serverId]
        );
        Database::statement(
            'INSERT INTO server_metrics (server_id, cpu_usage, created_at)
             VALUES (?, 20, DATE_SUB(NOW(), INTERVAL 5 DAY))',
            [$this->serverId]
        );

        $removed = ServerMetric::pruneOlderThan(30);

        $this->assertEquals(1, $removed);
        $this->assertEquals(
            1,
            (int) Database::scalar('SELECT COUNT(*) FROM server_metrics WHERE server_id = ?', [$this->serverId])
        );
    }

    public function testLimpezaNaoRemoveServidoresNemSites(): void
    {
        // Secao 21: "nao apagar dados importantes de servidores/sites".
        $siteId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'preservado.com.br',
            'status'     => 'online',
            'discovered' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime('-400 days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-400 days')),
        ]);

        RetentionService::runAll();

        $this->assertNotNull(Server::find($this->serverId), 'O servidor nunca pode ser removido pela limpeza.');
        $this->assertNotNull(Site::find($siteId), 'O site nunca pode ser removido pela limpeza.');
    }

    public function testLimpezaNaoRemoveAlertaEmAberto(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 95.0]);

        // Envelhece o alerta artificialmente.
        Database::statement(
            "UPDATE alerts SET created_at = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE server_id = ?",
            [$this->serverId]
        );

        Alert::pruneResolvedOlderThan(90);

        $this->assertNotNull(
            Alert::findOpenByFingerprint(Alert::fingerprint(Alert::TYPE_SERVER_DISK_HIGH, $this->serverId, null)),
            'Alerta em aberto nao pode ser apagado por retencao.'
        );
    }

    public function testLimpezaRemoveAlertaResolvidoAntigo(): void
    {
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 95.0]);
        AlertService::evaluateServerMetrics($this->serverId, 'VPS Monitorado', ['disk_percent' => 30.0]);

        Database::statement(
            "UPDATE alerts SET resolved_at = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE server_id = ?",
            [$this->serverId]
        );

        $removed = Alert::pruneResolvedOlderThan(90);

        $this->assertEquals(1, $removed);
    }

    public function testLimpezaDeChecagensDeSite(): void
    {
        $siteId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'historico.com.br',
            'status'     => 'online',
            'discovered' => 1,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        Database::statement(
            "INSERT INTO site_checks (site_id, status, created_at)
             VALUES (?, 'online', DATE_SUB(NOW(), INTERVAL 60 DAY))",
            [$siteId]
        );
        Database::statement(
            "INSERT INTO site_checks (site_id, status, created_at) VALUES (?, 'online', NOW())",
            [$siteId]
        );

        $removed = SiteCheck::pruneOlderThan(30);

        $this->assertEquals(1, $removed);
        $this->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM site_checks WHERE site_id = ?', [$siteId]));
    }

    public function testRetencaoDesligadaNaoApagaNada(): void
    {
        Database::statement(
            'INSERT INTO server_metrics (server_id, cpu_usage, created_at)
             VALUES (?, 10, DATE_SUB(NOW(), INTERVAL 400 DAY))',
            [$this->serverId]
        );

        $this->assertEquals(0, ServerMetric::pruneOlderThan(0), 'Retencao 0 significa "nunca apagar".');
        $this->assertEquals(
            1,
            (int) Database::scalar('SELECT COUNT(*) FROM server_metrics WHERE server_id = ?', [$this->serverId])
        );
    }
}
