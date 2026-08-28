<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Models\NotificationLog;
use App\Models\SecureSetting;
use App\Models\Site;
use App\Services\AlertService;
use App\Services\NotificationService;
use App\Services\ServerProvisionService;
use Tests\TestCase;

/**
 * Avisos ao administrador: cifragem dos segredos, limite de envio e o
 * switcher "ciente".
 *
 * Os testes cobrem deliberadamente as PORTAS - as condicoes que decidem se a
 * mensagem sai - e nao o envio em si. Falar com um SMTP ou com a RyzeAPI de
 * verdade dentro da suite tornaria os testes lentos e dependentes de rede, e
 * o que quebra na pratica e a regra de negocio, nao o socket.
 */
final class NotificationTest extends TestCase
{
    private int $serverId = 0;

    private int $siteId = 0;

    public function name(): string
    {
        return 'Avisos ao administrador';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        $this->truncate('secure_settings', 'notification_log');

        $created        = ServerProvisionService::create(['name' => 'VPS dos Avisos'], null);
        $this->serverId = $created['server_id'];

        $this->siteId = Database::insert('sites', [
            'server_id'  => $this->serverId,
            'domain'     => 'loja.exemplo.com.br',
            'status'     => 'offline',
            'discovered' => 1,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);
    }

    // =================================================================
    // Cifragem dos segredos
    // =================================================================

    public function testSegredoVaiEVoltaIntacto(): void
    {
        $senha = 'Senha com acento e simbolo: ç@#$%&';

        $cifrado = Crypto::encrypt($senha);

        $this->assertNotEquals($senha, $cifrado, 'O valor cifrado nao pode ser o texto puro.');
        $this->assertTrue(Crypto::isEncrypted($cifrado));
        $this->assertEquals($senha, Crypto::decrypt($cifrado));
    }

    public function testCifragemNuncaRepeteOMesmoResultado(): void
    {
        // IV sorteado a cada gravacao. Sem isso, dois campos com a mesma senha
        // ficariam identicos no banco - e a repeticao ja e informacao.
        $a = Crypto::encrypt('mesma-senha');
        $b = Crypto::encrypt('mesma-senha');

        $this->assertNotEquals($a, $b, 'Duas cifragens do mesmo valor nao podem coincidir.');
        $this->assertEquals('mesma-senha', Crypto::decrypt($a));
        $this->assertEquals('mesma-senha', Crypto::decrypt($b));
    }

    public function testValorAdulteradoNaoDecifraEmSilencio(): void
    {
        $cifrado = Crypto::encrypt('token-original');

        // Troca um caractere do meio do base64.
        $meio      = (int) (\strlen($cifrado) / 2);
        $adulterado = substr($cifrado, 0, $meio)
            . ($cifrado[$meio] === 'A' ? 'B' : 'A')
            . substr($cifrado, $meio + 1);

        $lancou = false;

        try {
            Crypto::decrypt($adulterado);
        } catch (\Throwable $e) {
            $lancou = true;
        }

        $this->assertTrue($lancou, 'GCM tem que recusar um valor adulterado, nao devolver lixo.');
    }

    public function testSenhaNaoFicaEmTextoPuroNoBanco(): void
    {
        SecureSetting::save(SecureSetting::SCOPE_EMAIL, [
            'smtp_host'     => 'smtp.gmail.com',
            'smtp_password' => 'senha-super-secreta',
        ]);

        $bruto = (string) Database::scalar(
            'SELECT `value` FROM secure_settings WHERE scope = ? AND `key` = ?',
            [SecureSetting::SCOPE_EMAIL, 'smtp_password']
        );

        $this->assertNotContainsString('senha-super-secreta', $bruto, 'A senha nao pode ficar legivel no banco.');
        $this->assertTrue(Crypto::isEncrypted($bruto));

        // E a leitura devolve decifrado, sem quem chama precisar saber.
        $this->assertEquals(
            'senha-super-secreta',
            SecureSetting::get(SecureSetting::SCOPE_EMAIL, 'smtp_password')
        );
    }

    public function testSalvarSemDigitarSenhaMantemAAtual(): void
    {
        SecureSetting::save(SecureSetting::SCOPE_EMAIL, ['smtp_password' => 'primeira']);

        // Segunda gravacao mexe so no host - a senha vem vazia do formulario.
        SecureSetting::save(SecureSetting::SCOPE_EMAIL, [
            'smtp_host'     => 'smtp.outro.com',
            'smtp_password' => '',
        ]);

        $this->assertEquals(
            'primeira',
            SecureSetting::get(SecureSetting::SCOPE_EMAIL, 'smtp_password'),
            'Campo de senha vazio significa "mantenha", nunca "apague".'
        );

        $this->assertEquals('smtp.outro.com', SecureSetting::get(SecureSetting::SCOPE_EMAIL, 'smtp_host'));
    }

    // =================================================================
    // Destinatarios
    // =================================================================

    public function testDestinatariosAceitamVariosSeparadoresEDescartamInvalidos(): void
    {
        SecureSetting::save(SecureSetting::SCOPE_EMAIL, [
            'recipients' => "a@x.com.br, b@y.com.br;  c@z.com.br\nnao-e-email\n\n a@x.com.br ",
        ]);

        $lista = SecureSetting::recipients(SecureSetting::SCOPE_EMAIL);

        $this->assertCount(3, $lista, 'Invalido descartado e duplicado colapsado.');
        $this->assertEquals('a@x.com.br', $lista[0]);
    }

    public function testNumerosDeWhatsappFicamSoComDigitos(): void
    {
        SecureSetting::save(SecureSetting::SCOPE_WHATSAPP, [
            'recipients' => '+55 (83) 99999-9999, 123',
        ]);

        $lista = SecureSetting::recipients(SecureSetting::SCOPE_WHATSAPP);

        $this->assertCount(1, $lista, 'Numero curto demais nao e telefone valido.');
        $this->assertEquals('5583999999999', $lista[0]);
    }

    // =================================================================
    // Limite de envio
    // =================================================================

    public function testCanalDesligadoNaoEnviaNada(): void
    {
        $resultado = NotificationService::siteOffline($this->siteId, 'loja.exemplo.com.br', 503, null);

        $this->assertEquals('disabled', $resultado['email']);
        $this->assertEquals('disabled', $resultado['whatsapp']);
        $this->assertCount(0, NotificationLog::recent(10), 'Canal desligado nao gera nem registro.');
    }

    public function testDominioAvisadoRecentementeEhSuprimido(): void
    {
        $this->ativarEmail();

        // Simula um envio bem-sucedido ha 10 minutos.
        Database::statement(
            "INSERT INTO notification_log (channel, event, site_id, domain, recipient, status, created_at)
             VALUES ('email', 'site_offline', ?, 'loja.exemplo.com.br', 'a@x.com.br', 'sent', DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [$this->siteId]
        );

        $resultado = NotificationService::siteOffline($this->siteId, 'loja.exemplo.com.br', 503, null);

        $this->assertEquals('skipped', $resultado['email']);

        $ultimo = NotificationLog::recent(1)[0];
        $this->assertEquals('skipped', $ultimo['status']);
        $this->assertContainsString('Ja avisado', (string) $ultimo['error']);
    }

    public function testTentativaQueFalhouNaoBloqueiaAProxima(): void
    {
        $this->ativarEmail();

        // Uma FALHA recente nao pode contar como "ja avisado": o operador nao
        // recebeu nada, e bloquear a proxima transformaria uma queda de SMTP
        // em silencio permanente.
        Database::statement(
            "INSERT INTO notification_log (channel, event, site_id, domain, recipient, status, error, created_at)
             VALUES ('email', 'site_offline', ?, 'loja.exemplo.com.br', 'a@x.com.br', 'failed', 'timeout', DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [$this->siteId]
        );

        $this->assertFalse(
            NotificationLog::sentRecentlyFor('email', 'loja.exemplo.com.br', 360),
            'So envio bem-sucedido conta para a janela.'
        );
    }

    public function testTetoPorHoraSuprimeOExcedente(): void
    {
        $this->ativarEmail();

        $teto = (int) Config::get('monitoring.notify.hourly_cap', 20);

        for ($i = 0; $i < $teto; $i++) {
            Database::statement(
                "INSERT INTO notification_log (channel, event, domain, recipient, status, created_at)
                 VALUES ('email', 'site_offline', ?, 'a@x.com.br', 'sent', DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
                ['outro-' . $i . '.com.br']
            );
        }

        $resultado = NotificationService::siteOffline($this->siteId, 'loja.exemplo.com.br', 503, null);

        $this->assertEquals('skipped', $resultado['email']);

        $ultimo = NotificationLog::recent(1)[0];
        $this->assertContainsString('Teto de', (string) $ultimo['error']);
    }

    // =================================================================
    // Switcher "ciente"
    // =================================================================

    public function testDominioMarcadoComoCienteNaoAvisa(): void
    {
        $this->ativarEmail();

        Site::setNotifyMuted($this->siteId, true, null);

        $resultado = NotificationService::siteOffline($this->siteId, 'loja.exemplo.com.br', 503, null);

        $this->assertEquals('skipped', $resultado['email']);

        $ultimo = NotificationLog::recent(1)[0];
        $this->assertContainsString('ciente', (string) $ultimo['error']);
    }

    public function testCienteSeDesfazQuandoOSiteVolta(): void
    {
        Site::setNotifyMuted($this->siteId, true, null);
        $this->assertTrue(Site::isNotifyMuted($this->siteId));

        AlertService::siteCameBack($this->siteId, $this->serverId, 'loja.exemplo.com.br');

        $this->assertFalse(
            Site::isNotifyMuted($this->siteId),
            'Com o site de volta, manter o silencio faria a proxima queda passar despercebida.'
        );
    }

    // =================================================================
    // Formato da resposta dos testes de canal
    // =================================================================

    public function testFalhaNoTesteChegaComOMotivoNaTela(): void
    {
        // O helper de API do painel trata `ok:false` no ENVELOPE como erro de
        // requisicao e troca a mensagem por um texto generico ("Erro HTTP
        // 200"). Por isso o resultado do teste viaja dentro de uma resposta
        // bem-sucedida: o envelope diz que a requisicao funcionou, e o corpo
        // diz que o SMTP/WhatsApp la fora falhou - com o motivo legivel.
        $adminId = \App\Models\User::create([
            'name'          => 'Admin dos Avisos',
            'email'         => 'admin.avisos@teste.local',
            'password_hash' => \App\Models\User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $this->loginAs($adminId, 'admin');

        // Sem instancia nem token configurados, o cliente falha sem rede.
        $response = $this->request('POST', '/avisos/whatsapp/testar', [
            'to'     => '5583999999999',
            '_token' => $this->csrfToken(),
        ]);

        $this->assertStatus(200, $response, 'A requisicao em si funcionou; quem falhou foi o servico externo.');

        $json = $this->decodeJson($response);

        $this->assertTrue($json['ok'], 'O envelope precisa indicar sucesso para a mensagem real chegar a tela.');
        $this->assertFalse($json['data']['ok'], 'O corpo e que carrega o resultado do teste.');
        $this->assertNotEquals('', (string) $json['data']['error'], 'O motivo nao pode chegar vazio.');
        $this->assertContainsString('configurados', (string) $json['data']['error']);
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    private function ativarEmail(): void
    {
        SecureSetting::save(SecureSetting::SCOPE_EMAIL, [
            'enabled'    => '1',
            'smtp_host'  => '127.0.0.1',
            'smtp_port'  => '2525',
            'recipients' => 'a@x.com.br',
        ]);
    }
}
