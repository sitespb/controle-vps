<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Controller;
use App\Core\Database;
use App\Models\User;
use App\Services\ServerProvisionService;
use Tests\TestCase;

/**
 * Seletor de "itens por pagina", compartilhado pelas tres listagens paginadas:
 * sites, alertas e logs.
 *
 * As opcoes e o padrao vivem no Controller base de proposito - um seletor que
 * oferece 10/20/50/100 numa tela e 25/50/100 na outra e a diferenca que o
 * operador nota e nao entende. Estes testes existem para que as tres continuem
 * coerentes quando alguem mexer numa delas.
 */
final class PaginationTest extends TestCase
{
    private int $adminId = 0;

    public function name(): string
    {
        return 'Itens por pagina';
    }

    protected function setUp(): void
    {
        Database::statement('DELETE FROM servers');
        Database::statement('DELETE FROM users');
        $this->truncate('audit_logs');

        $this->adminId = User::create([
            'name'          => 'Admin Paginacao',
            'email'         => 'admin.pag@teste.local',
            'password_hash' => User::hashPassword('SenhaDeTeste@2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);

        $this->loginAs($this->adminId, 'admin');

        // A barra de paginacao - e portanto o seletor - so existe quando ha
        // registros: uma lista vazia mostra o estado "nenhum resultado". Por
        // isso cada listagem precisa de ao menos uma linha aqui.
        $this->semearUmDeCada();
    }

    private function semearUmDeCada(): void
    {
        $servidor = ServerProvisionService::create(['name' => 'VPS Semente'], null)['server_id'];

        $siteId = Database::insert('sites', [
            'server_id'  => $servidor,
            'domain'     => 'semente.com.br',
            'status'     => 'online',
            'discovered' => 1,
            'created_at' => now_string(),
            'updated_at' => now_string(),
        ]);

        Database::insert('alerts', [
            'server_id'     => $servidor,
            'site_id'       => $siteId,
            'type'          => 'site_offline',
            'severity'      => 'warning',
            'title'         => 'Semente',
            'message'       => 'Alerta de semente para a listagem.',
            'status'        => 'open',
            'fingerprint'   => sha1('semente'),
            'occurrences'   => 1,
            'first_seen_at' => now_string(),
            'last_seen_at'  => now_string(),
            'created_at'    => now_string(),
            'updated_at'    => now_string(),
        ]);

        Database::insert('audit_logs', [
            'action'      => 'teste.paginacao',
            'description' => 'Registro de semente para a listagem de logs.',
            'level'       => 'info',
            'created_at'  => now_string(),
        ]);
    }

    /** @return array<int,array{0:string,1:string}> caminho => rotulo */
    private function listagens(): array
    {
        return [
            ['/sites', 'sites'],
            ['/alertas', 'alertas'],
            ['/logs', 'logs'],
        ];
    }

    // =================================================================
    // O seletor
    // =================================================================

    public function testAsTresListagensOferecemAsMesmasOpcoes(): void
    {
        foreach ($this->listagens() as [$caminho, $rotulo]) {
            $html = $this->request('GET', $caminho)->content();

            $this->assertContainsString('id="por_pagina"', $html, "Falta o seletor em {$rotulo}.");

            foreach (Controller::PER_PAGE_OPTIONS as $opcao) {
                $this->assertContainsString(
                    '<option value="' . $opcao . '"',
                    $html,
                    "Falta a opcao {$opcao} em {$rotulo}."
                );
            }
        }
    }

    public function testAsTresComecamNoMenorValor(): void
    {
        foreach ($this->listagens() as [$caminho, $rotulo]) {
            $html = $this->request('GET', $caminho)->content();

            $this->assertContainsString(
                '<option value="' . Controller::PER_PAGE_DEFAULT . '" selected',
                $html,
                "O padrao de {$rotulo} deveria ser o menor valor da lista."
            );
        }
    }

    public function testValorForaDaListaVoltaAoPadrao(): void
    {
        // Pedir 100000 registros de uma vez derrubaria a pagina; a lista
        // fechada de opcoes existe exatamente para isso.
        foreach ($this->listagens() as [$caminho, $rotulo]) {
            $html = $this->request('GET', $caminho, [], [], ['por_pagina' => '100000'])->content();

            $this->assertContainsString(
                '<option value="' . Controller::PER_PAGE_DEFAULT . '" selected',
                $html,
                "Valor invalido em {$rotulo} tem que voltar ao padrao."
            );
        }
    }

    public function testEscolhaEhRespeitadaEmTodasAsListagens(): void
    {
        foreach ($this->listagens() as [$caminho, $rotulo]) {
            $html = $this->request('GET', $caminho, [], [], ['por_pagina' => '50'])->content();

            $this->assertContainsString(
                '<option value="50" selected',
                $html,
                "A escolha nao foi respeitada em {$rotulo}."
            );
        }
    }

    // =================================================================
    // A escolha precisa sobreviver aos outros caminhos da tela
    // =================================================================

    public function testEscolhaSobreviveAosLinksDePagina(): void
    {
        $servidor = ServerProvisionService::create(['name' => 'VPS Paginado'], null)['server_id'];

        // 12 sites com 10 por pagina = duas paginas, entao ha links a conferir.
        for ($i = 1; $i <= 12; $i++) {
            Database::insert('sites', [
                'server_id'  => $servidor,
                'domain'     => "site{$i}.com.br",
                'status'     => 'online',
                'discovered' => 1,
                'created_at' => now_string(),
                'updated_at' => now_string(),
            ]);
        }

        $html = $this->request('GET', '/sites', [], [], ['por_pagina' => '10'])->content();

        $this->assertContainsString(
            'por_pagina=10',
            $html,
            'Os links de pagina precisam carregar a escolha, senao ela se perde no clique.'
        );
    }

    public function testEscolhaSobreviveAoFormularioDeFiltros(): void
    {
        // O formulario de filtros e um GET separado: sem o campo oculto, todo
        // filtro aplicado descartaria a escolha silenciosamente.
        foreach ($this->listagens() as [$caminho, $rotulo]) {
            $html = $this->request('GET', $caminho, [], [], ['por_pagina' => '50'])->content();

            $this->assertContainsString(
                '<input type="hidden" name="por_pagina" value="50">',
                $html,
                "Falta o campo oculto no formulario de filtros de {$rotulo}."
            );
        }
    }

    // =================================================================
    // Quantidade real de registros
    // =================================================================

    public function testAQuantidadeEscolhidaLimitaMesmoAConsulta(): void
    {
        $servidor = ServerProvisionService::create(['name' => 'VPS Limite'], null)['server_id'];

        for ($i = 1; $i <= 12; $i++) {
            Database::insert('sites', [
                'server_id'  => $servidor,
                'domain'     => "limite{$i}.com.br",
                'status'     => 'online',
                'discovered' => 1,
                'created_at' => now_string(),
                'updated_at' => now_string(),
            ]);
        }

        $pagina = (new \App\Repositories\SiteRepository())->paginate([], 1, 10);

        // 12 criados aqui + 1 semeado no setUp.
        $this->assertCount(10, $pagina['items'], 'A consulta precisa respeitar o limite, nao so a tela.');
        $this->assertEquals(13, $pagina['total']);
        $this->assertEquals(2, $pagina['pages']);
    }
}
