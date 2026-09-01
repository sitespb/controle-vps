<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Repositories\SiteRepository;
use App\Services\DuplicateSiteService;
use App\Services\ServerProvisionService;
use Tests\TestCase;

/**
 * Dominio hospedado em mais de um servidor.
 *
 * O ponto delicado nao e detectar a duplicidade - a chave unica
 * (server_id, domain) ja garante que sao duas linhas. E dizer QUAL copia esta
 * no ar, porque a tela sugere apagar a outra. Apontar errado faria o operador
 * apagar o site que funciona.
 */
final class DuplicateSiteTest extends TestCase
{
    private int $servidorA = 0;

    private int $servidorB = 0;

    private SiteRepository $repository;

    public function name(): string
    {
        return 'Dominios duplicados';
    }

    private int $adminId = 0;

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        Database::statement('DELETE FROM users');

        $this->repository = new SiteRepository();

        $this->adminId = \App\Models\User::create([
            'name'          => 'Admin Duplicados',
            'email'         => 'admin.dup@teste.local',
            'password_hash' => \App\Models\User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $this->logout();

        $a = ServerProvisionService::create(['name' => 'Servidor A', 'ip' => '203.0.113.10'], null);
        $b = ServerProvisionService::create(['name' => 'Servidor B', 'ip' => '203.0.113.20'], null);

        $this->servidorA = $a['server_id'];
        $this->servidorB = $b['server_id'];
    }

    // =================================================================
    // Deteccao
    // =================================================================

    public function testDominioEmUmServidorSoNaoEhDuplicado(): void
    {
        $this->criarSite($this->servidorA, 'unico.com.br', '203.0.113.10');

        $this->assertCount(0, $this->repository->duplicateDomains());
    }

    public function testDominioEmDoisServidoresEhDetectado(): void
    {
        $this->criarSite($this->servidorA, 'duplo.com.br', '203.0.113.10');
        $this->criarSite($this->servidorB, 'duplo.com.br', '203.0.113.10');

        $this->assertEquals(['duplo.com.br'], $this->repository->duplicateDomains());
    }

    public function testDominioNaoDescobertoNaoContaComoDuplicado(): void
    {
        // Uma copia que ja saiu do servidor nao e duplicidade - e historico.
        $this->criarSite($this->servidorA, 'saiu.com.br', '203.0.113.10');
        $id = $this->criarSite($this->servidorB, 'saiu.com.br', '203.0.113.10');

        Database::update('sites', ['discovered' => 0], ['id' => $id]);

        $this->assertCount(0, $this->repository->duplicateDomains());
    }

    // =================================================================
    // Qual copia esta no ar
    // =================================================================

    public function testIdentificaQuandoEsteServidorEhQuemResponde(): void
    {
        // O agente do A conectou em 203.0.113.10, que e o IP do proprio A.
        $idA = $this->criarSite($this->servidorA, 'site.com.br', '203.0.113.10');
        $this->criarSite($this->servidorB, 'site.com.br', '203.0.113.10');

        $analise = $this->analisar($idA);

        $this->assertEquals(DuplicateSiteService::SERVING_THIS, $analise['serving']);
        $this->assertEquals('203.0.113.10', $analise['resolved_ip']);
        $this->assertCount(1, $analise['copies']);
        $this->assertFalse($analise['copies'][0]['is_serving'], 'A copia em B nao responde.');
    }

    public function testIdentificaQuandoOutroServidorEhQuemResponde(): void
    {
        // Os dois agentes conectam em 203.0.113.20, que e o IP do servidor B.
        // Logo, e B quem responde - e a copia em A esta parada no disco.
        $idA = $this->criarSite($this->servidorA, 'site.com.br', '203.0.113.20');
        $this->criarSite($this->servidorB, 'site.com.br', '203.0.113.20');

        $analise = $this->analisar($idA);

        $this->assertEquals(DuplicateSiteService::SERVING_OTHER, $analise['serving']);
        $this->assertEquals('Servidor B', $analise['serving_server']);
        $this->assertTrue($analise['copies'][0]['is_serving']);
    }

    public function testProxyNaFrenteNaoPodeApontarServidorErrado(): void
    {
        // Com Cloudflare, o IP conectado e o do proxy e nao bate com servidor
        // nenhum. Chutar um deles faria o operador apagar o site que funciona.
        $idA = $this->criarSite($this->servidorA, 'atras-de-proxy.com.br', '104.21.0.1');
        $this->criarSite($this->servidorB, 'atras-de-proxy.com.br', '104.21.0.1');

        $analise = $this->analisar($idA);

        $this->assertEquals(
            DuplicateSiteService::SERVING_UNKNOWN,
            $analise['serving'],
            'Sem certeza, a resposta tem que ser "nao sei".'
        );
        $this->assertNull($analise['serving_server']);
    }

    public function testSemIpColetadoNaoAfirmaNada(): void
    {
        $idA = $this->criarSite($this->servidorA, 'sem-ip.com.br', null);
        $this->criarSite($this->servidorB, 'sem-ip.com.br', null);

        $analise = $this->analisar($idA);

        $this->assertEquals(DuplicateSiteService::SERVING_UNKNOWN, $analise['serving']);
        $this->assertNull($analise['resolved_ip']);
    }

    // =================================================================
    // Filtro da listagem
    // =================================================================

    public function testFiltroDeDuplicadosTrazSoAsCopias(): void
    {
        $this->criarSite($this->servidorA, 'duplo.com.br', '203.0.113.10');
        $this->criarSite($this->servidorB, 'duplo.com.br', '203.0.113.10');
        $this->criarSite($this->servidorA, 'sozinho.com.br', '203.0.113.10');

        $todos = $this->repository->paginate([]);
        $this->assertEquals(3, $todos['total']);

        $filtrado = $this->repository->paginate(['duplicados' => 'yes']);

        $this->assertEquals(2, $filtrado['total'], 'Somente as duas copias do dominio repetido.');

        foreach ($filtrado['items'] as $item) {
            $this->assertEquals('duplo.com.br', $item['domain']);
        }
    }

    // =================================================================
    // Telas
    // =================================================================

    public function testPaginaDoSiteMostraAFaixaDeDuplicidade(): void
    {
        $this->logarComoAdmin();

        $idA = $this->criarSite($this->servidorA, 'duplo.com.br', '203.0.113.20');
        $this->criarSite($this->servidorB, 'duplo.com.br', '203.0.113.20');

        $html = $this->request('GET', '/sites/' . $idA)->content();

        $this->assertContainsString('Esta copia nao esta sendo usada', $html);
        $this->assertContainsString('Servidor B', $html, 'Precisa dizer qual servidor responde.');
        $this->assertContainsString('203.0.113.20', $html, 'E para onde o DNS aponta.');
    }

    public function testPaginaDeSiteNormalNaoMostraFaixaNenhuma(): void
    {
        $this->logarComoAdmin();

        $id = $this->criarSite($this->servidorA, 'sozinho.com.br', '203.0.113.10');

        $html = $this->request('GET', '/sites/' . $id)->content();

        $this->assertNotContainsString('Esta copia nao esta sendo usada', $html);
        $this->assertNotContainsString('existe em mais de um servidor', $html);
    }

    public function testListaMarcaSomenteODominioDuplicado(): void
    {
        $this->logarComoAdmin();

        $this->criarSite($this->servidorA, 'duplo.com.br', '203.0.113.10');
        $this->criarSite($this->servidorB, 'duplo.com.br', '203.0.113.10');
        $this->criarSite($this->servidorA, 'sozinho.com.br', '203.0.113.10');

        $html = $this->request('GET', '/sites')->content();

        // Duas linhas do dominio repetido levam selo; a terceira nao.
        $this->assertEquals(
            2,
            substr_count($html, '>Duplicado<'),
            'O selo aparece uma vez por copia, e so nas copias.'
        );

        $this->assertContainsString('Somente duplicados', $html, 'O filtro precisa aparecer quando ha o que filtrar.');
    }

    public function testFiltroNaoAparecerQuandoNaoHaDuplicados(): void
    {
        $this->logarComoAdmin();

        $this->criarSite($this->servidorA, 'sozinho.com.br', '203.0.113.10');

        $html = $this->request('GET', '/sites')->content();

        $this->assertNotContainsString(
            'Somente duplicados',
            $html,
            'Um filtro que nunca encontra nada e ruido permanente na tela.'
        );
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    private function logarComoAdmin(): void
    {
        $this->loginAs($this->adminId, 'admin');
    }

    /** @param string|null $ipConectado IP em que o agente daquele servidor conectou */
    private function criarSite(int $serverId, string $domain, ?string $ipConectado): int
    {
        return Database::insert('sites', [
            'server_id'     => $serverId,
            'domain'        => $domain,
            'status'        => 'online',
            'ip'            => $ipConectado,
            'document_root' => '/home/' . $domain . '/public_html',
            'disk_usage'    => 340 * 1024 * 1024,
            'discovered'    => 1,
            'last_check_at' => now_string(),
            'created_at'    => now_string(),
            'updated_at'    => now_string(),
        ]);
    }

    /** @return array<string,mixed> */
    private function analisar(int $siteId): array
    {
        $site = \App\Models\Site::findDetailed($siteId);

        return DuplicateSiteService::analyse(
            $site,
            $this->repository->otherCopiesOf((string) $site['domain'], $siteId)
        );
    }
}
