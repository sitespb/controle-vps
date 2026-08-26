<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\User;
use App\Services\AuthService;
use Tests\TestCase;

/**
 * Login, logout e protecao contra forca bruta (secoes 23, 33 e 43 do PLAN).
 */
final class AuthTest extends TestCase
{
    private const PASSWORD = 'SenhaDeTeste@2026';

    private int $adminId = 0;

    public function name(): string
    {
        return 'Autenticacao';
    }

    protected function setUp(): void
    {
        $this->truncate('login_attempts', 'audit_logs');
        Database::statement('DELETE FROM users');

        $this->adminId = User::create([
            'name'          => 'Admin de Teste',
            'email'         => 'admin@teste.local',
            'password_hash' => User::hashPassword(self::PASSWORD),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $this->logout();
    }

    public function testLoginComCredenciaisCorretas(): void
    {
        $result = AuthService::attempt('admin@teste.local', self::PASSWORD, '10.0.0.1');

        $this->assertTrue($result['ok'], 'O login deveria ter sido aceito.');
        $this->assertEquals($this->adminId, (int) $result['user']['id']);
        $this->assertTrue(AuthService::check(), 'A sessao deveria estar autenticada.');
    }

    public function testLoginComSenhaErradaEhRecusado(): void
    {
        $result = AuthService::attempt('admin@teste.local', 'senha-errada', '10.0.0.1');

        $this->assertFalse($result['ok']);
        $this->assertFalse(AuthService::check(), 'A sessao nao deveria ter sido criada.');
    }

    public function testMensagemNaoRevelaSeOEmailExiste(): void
    {
        // Enumerar usuarios validos por diferenca de mensagem e um vetor real.
        $inexistente = AuthService::attempt('naoexiste@teste.local', 'qualquer', '10.0.0.2');
        $this->logout();
        $senhaErrada = AuthService::attempt('admin@teste.local', 'qualquer', '10.0.0.3');

        $this->assertEquals($inexistente['message'], $senhaErrada['message']);
    }

    public function testUsuarioInativoNaoConsegueEntrar(): void
    {
        User::updateById($this->adminId, ['status' => 'inactive']);

        $result = AuthService::attempt('admin@teste.local', self::PASSWORD, '10.0.0.1');

        $this->assertFalse($result['ok']);
        $this->assertContainsString('inativo', mb_strtolower($result['message']));
    }

    public function testBloqueioAposExcessoDeTentativas(): void
    {
        $ip = '10.0.0.99';

        // O limite padrao e 5 tentativas na janela configurada.
        for ($i = 0; $i < 5; $i++) {
            AuthService::attempt('admin@teste.local', 'errada', $ip);
            $this->logout();
        }

        $this->assertTrue(
            AuthService::isLocked('admin@teste.local', $ip),
            'Deveria estar bloqueado apos 5 falhas.'
        );

        // Mesmo com a senha CORRETA o acesso e recusado durante o bloqueio.
        $result = AuthService::attempt('admin@teste.local', self::PASSWORD, $ip);

        $this->assertFalse($result['ok']);
        $this->assertContainsString('tentativas', mb_strtolower($result['message']));
    }

    public function testLoginBemSucedidoLimpaAsFalhasAnteriores(): void
    {
        $ip = '10.0.0.50';

        for ($i = 0; $i < 3; $i++) {
            AuthService::attempt('admin@teste.local', 'errada', $ip);
            $this->logout();
        }

        AuthService::attempt('admin@teste.local', self::PASSWORD, $ip);

        $failures = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0',
            ['admin@teste.local']
        );

        $this->assertEquals(0, $failures, 'As falhas deveriam ter sido descartadas.');
    }

    public function testLogoutEncerraASessao(): void
    {
        AuthService::attempt('admin@teste.local', self::PASSWORD, '10.0.0.1');
        $this->assertTrue(AuthService::check());

        AuthService::logout();

        $this->assertFalse(AuthService::check(), 'A sessao deveria ter sido encerrada.');
        $this->assertNull(AuthService::user());
    }

    public function testTentativasSaoRegistradasSemASenha(): void
    {
        AuthService::attempt('admin@teste.local', 'SenhaSecretaQueNaoPodeVazar', '10.0.0.7');

        $rows = Database::select('SELECT * FROM login_attempts');

        $this->assertCount(1, $rows);
        $this->assertNotContainsString(
            'SenhaSecretaQueNaoPodeVazar',
            (string) json_encode($rows),
            'A senha tentada nunca pode ser gravada.'
        );
    }

    public function testSenhaNuncaEhArmazenadaEmTextoPuro(): void
    {
        $user = User::find($this->adminId);

        $this->assertNotNull($user);
        $this->assertNotEquals(self::PASSWORD, $user['password_hash']);
        $this->assertTrue(
            password_verify(self::PASSWORD, (string) $user['password_hash']),
            'O hash gravado deveria validar a senha original.'
        );
    }

    public function testPaginaProtegidaRedirecionaVisitante(): void
    {
        $this->logout();

        $response = $this->request('GET', '/servidores');

        $this->assertStatus(302, $response, 'Visitante deveria ser mandado para o login.');
        $this->assertContainsString('/login', $response->headers()['location'] ?? '');
    }

    public function testUsuarioAutenticadoAcessaOPainel(): void
    {
        $this->loginAs($this->adminId, 'admin');

        $response = $this->request('GET', '/servidores');

        $this->assertStatus(200, $response);
    }
}
