<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Crypto;
use App\Core\Database;
use App\Models\SecureSetting;
use App\Models\User;
use App\Services\TurnstileService;
use Tests\TestCase;

/**
 * Cloudflare Turnstile na tela de login.
 *
 * Os testes cobrem as decisoes que o servidor toma - quando o captcha vale,
 * quando ele e ignorado, e o que aparece no HTML. A validacao contra a
 * Cloudflare em si nao entra na suite: exige rede e um token real de
 * navegador, e o que quebra na pratica sao as condicoes em volta.
 */
final class TurnstileTest extends TestCase
{
    private int $adminId = 0;

    public function name(): string
    {
        return 'Turnstile no login';
    }

    protected function setUp(): void
    {
        $this->truncate('secure_settings');
        Database::statement('DELETE FROM users');

        $this->adminId = User::create([
            'name'          => 'Admin do Captcha',
            'email'         => 'admin.captcha@teste.local',
            'password_hash' => User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $this->logout();
    }

    // =================================================================
    // Quando o captcha vale
    // =================================================================

    public function testDesligadoNaoAtrapalhaOLogin(): void
    {
        $this->assertFalse(TurnstileService::isEnabled());

        $resultado = TurnstileService::verify(null, '203.0.113.10');

        $this->assertTrue($resultado['ok'], 'Com o captcha desligado, o login segue sem verificacao.');
    }

    public function testLigadoSemChavesNaoContaComoAtivo(): void
    {
        // Exibir um widget sem site_key deixaria a tela de login intransponivel
        // e sem explicacao. Meio configurado equivale a desligado.
        SecureSetting::save(SecureSetting::SCOPE_TURNSTILE, [
            'enabled'    => '1',
            'site_key'   => '',
            'secret_key' => '',
        ]);

        $this->assertFalse(
            TurnstileService::isEnabled(),
            'Ligado sem as duas chaves nao pode valer como ativo.'
        );

        $this->assertTrue(TurnstileService::verify(null)['ok']);
    }

    public function testAtivoRecusaLoginSemToken(): void
    {
        $this->ativar();

        $resultado = TurnstileService::verify('', '203.0.113.10');

        $this->assertFalse($resultado['ok']);
        $this->assertNotEquals('', (string) $resultado['error'], 'A recusa precisa dizer o que fazer.');
    }

    // =================================================================
    // Segredo
    // =================================================================

    public function testChaveSecretaFicaCifradaNoBanco(): void
    {
        $this->ativar();

        $bruto = (string) Database::scalar(
            'SELECT `value` FROM secure_settings WHERE scope = ? AND `key` = ?',
            [SecureSetting::SCOPE_TURNSTILE, 'secret_key']
        );

        $this->assertNotContainsString('0x-secreta-de-teste', $bruto, 'A chave secreta nao pode ficar legivel.');
        $this->assertTrue(Crypto::isEncrypted($bruto));
    }

    public function testChaveDoSiteNaoEhCifrada(): void
    {
        $this->ativar();

        // A site key vai no HTML - qualquer visitante a le. Cifrar so
        // dificultaria a manutencao sem proteger nada.
        $bruto = (string) Database::scalar(
            'SELECT `value` FROM secure_settings WHERE scope = ? AND `key` = ?',
            [SecureSetting::SCOPE_TURNSTILE, 'site_key']
        );

        $this->assertEquals('0x-site-de-teste', $bruto);
    }

    // =================================================================
    // Tela de login
    // =================================================================

    public function testWidgetNaoAparecerQuandoDesligado(): void
    {
        $html = $this->request('GET', '/login')->content();

        $this->assertNotContainsString('cf-turnstile', $html);
        $this->assertNotContainsString('challenges.cloudflare.com', $html);
    }

    public function testWidgetApareceQuandoAtivo(): void
    {
        $this->ativar();

        $html = $this->request('GET', '/login')->content();

        $this->assertContainsString('cf-turnstile', $html);
        $this->assertContainsString('challenges.cloudflare.com', $html);
        $this->assertContainsString('0x-site-de-teste', $html, 'A chave publica vai no HTML.');

        // E a secreta, jamais.
        $this->assertNotContainsString('0x-secreta-de-teste', $html);
    }

    public function testLoginEhRecusadoSemResolverOCaptcha(): void
    {
        $credenciais = [
            'email'    => 'admin.captcha@teste.local',
            'password' => 'SenhaDeTeste@2026',
        ];

        // CONTROLE POSITIVO. Sem ele, este teste passaria mesmo que o login
        // estivesse falhando por outro motivo qualquer - CSRF, validacao, uma
        // senha errada no setUp - e daria a falsa impressao de que o captcha
        // esta bloqueando. Primeiro provamos que estas credenciais ENTRAM.
        $this->logout();
        $this->request('POST', '/login', $credenciais + ['_token' => $this->csrfToken()]);

        $this->assertEquals(
            $this->adminId,
            $_SESSION['user_id'] ?? null,
            'Com o captcha desligado, estas credenciais precisam funcionar.'
        );

        // Agora, com o captcha ativo, as MESMAS credenciais devem ser barradas.
        $this->logout();
        $this->ativar();

        $response = $this->request('POST', '/login', $credenciais + ['_token' => $this->csrfToken()]);

        $this->assertStatus(302, $response, 'Volta para o login em vez de autenticar.');
        $this->assertNull($_SESSION['user_id'] ?? null, 'Sem captcha resolvido, ninguem entra.');
    }

    // =================================================================
    // Tela de configuracao
    // =================================================================

    public function testAbaRecaptchaAparecemNasConfiguracoes(): void
    {
        $this->loginAs($this->adminId, 'admin');

        $html = $this->request('GET', '/configuracoes')->content();

        $this->assertContainsString('Recaptcha', $html);
        $this->assertContainsString('/configuracoes/turnstile', $html);
    }

    public function testAtivarSemChavesEhRecusado(): void
    {
        $this->loginAs($this->adminId, 'admin');

        $this->request('POST', '/configuracoes/turnstile', [
            'enabled' => '1',
            'site_key' => '',
            'secret_key' => '',
            '_token'  => $this->csrfToken(),
        ]);

        $this->assertFalse(
            SecureSetting::isEnabled(SecureSetting::SCOPE_TURNSTILE),
            'Ativar sem chaves trancaria a tela de login sem explicacao.'
        );
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    private function ativar(): void
    {
        SecureSetting::save(SecureSetting::SCOPE_TURNSTILE, [
            'enabled'    => '1',
            'site_key'   => '0x-site-de-teste',
            'secret_key' => '0x-secreta-de-teste',
        ]);
    }
}
