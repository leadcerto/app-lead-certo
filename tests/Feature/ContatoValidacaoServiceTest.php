<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Services\ContatoValidacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatoValidacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContatoValidacaoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContatoValidacaoService::class);
    }

    public function test_telefone_ja_canonico_e_unico_vira_lead_certo(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);

        $resultado = $this->service->validar($contato);

        $this->assertSame('lead_certo', $resultado);
    }

    public function test_telefone_malformado_com_par_exato_mescla_e_sobrevivente_vira_lead_certo(): void
    {
        $canonico  = Contato::factory()->create(['telefone' => '5554981126376', 'nome' => 'Ademir Nunes']);
        $malformado = Contato::factory()->create(['telefone' => '54981126376', 'nome' => 'Ademir Nunes 11283']);

        $resultado = $this->service->validar($malformado);

        $this->assertSame('lead_certo', $resultado);
        $this->assertSoftDeleted('contatos', ['id' => $malformado->id]);
        $this->assertDatabaseHas('contatos', ['id' => $canonico->id, 'telefone' => '5554981126376']);
    }

    public function test_telefone_malformado_sem_par_autocorrige_e_vira_lead_certo(): void
    {
        // Unico registro daquele numero -- nao ha com quem mesclar, so
        // corrige o proprio formato.
        $contato = Contato::factory()->create(['telefone' => '54988887777']);

        $resultado = $this->service->validar($contato);

        $this->assertSame('lead_certo', $resultado);
        $this->assertDatabaseHas('contatos', ['id' => $contato->id, 'telefone' => '5554988887777']);
    }

    public function test_telefone_sem_candidato_nenhum_vira_lead_invalido(): void
    {
        $contato = Contato::factory()->create(['telefone' => '55481126376']);

        $resultado = $this->service->validar($contato);

        $this->assertSame('lead_invalido', $resultado);
        $this->assertDatabaseHas('contatos', ['id' => $contato->id, 'telefone' => '55481126376']);
    }

    public function test_pablo_e_paulo_nao_se_mesclam(): void
    {
        $pablo = Contato::factory()->create(['telefone' => '5519996731736', 'nome' => 'Pablo Cesar Da Silva']);
        $paulo = Contato::factory()->create(['telefone' => '5521996731736', 'nome' => 'Paulo Cesar']);

        $this->assertSame('lead_certo', $this->service->validar($pablo));
        $this->assertSame('lead_certo', $this->service->validar($paulo));
        $this->assertDatabaseHas('contatos', ['id' => $pablo->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('contatos', ['id' => $paulo->id, 'deleted_at' => null]);
    }

    public function test_dois_candidatos_batendo_com_registros_diferentes_vira_lead_invalido(): void
    {
        // '09988887777' (11 digitos, comeca com 0) gera dois candidatos
        // canonicos distintos: '5509988887777' (regra direta de 11
        // digitos) e '5599988887777' (via recursao do 0 espurio -> regra
        // de 10 digitos). Se os dois batem com registros DIFERENTES, é
        // ambiguidade real -- nao pode mesclar no primeiro que aparecer.
        Contato::factory()->create(['telefone' => '5509988887777']);
        Contato::factory()->create(['telefone' => '5599988887777']);
        $ambiguo = Contato::factory()->create(['telefone' => '09988887777']);

        $resultado = $this->service->validar($ambiguo);

        $this->assertSame('lead_invalido', $resultado);
        $this->assertDatabaseHas('contatos', ['id' => $ambiguo->id, 'telefone' => '09988887777', 'deleted_at' => null]);
    }

    /**
     * contatos.telefone tem constraint UNIQUE de verdade no banco
     * (herdada sem quebra desde a tabela original `consumidores`,
     * database/migrations/0003_create_consumidores_table.php:13) -- não
     * dá pra gravar dois registros com o mesmo telefone via
     * Contato::create()/update() pra simular o cenário de "duplicata
     * exata" fim a fim. classificar() só lê $contato->telefone (não
     * exige que esse valor tenha sido persistido) pra buscar outro
     * registro com o mesmo telefone -- então dá pra simular o cenário
     * fazendo um Contato JÁ SALVO "fingir" ter o telefone de outro só na
     * memória (sem tocar o banco), e testar a decisão sem violar a
     * constraint.
     */
    public function test_duplicata_exata_contato_validado_com_id_maior_vira_antigo(): void
    {
        $menor = Contato::factory()->create(['telefone' => '5521994359537']);
        $maior = Contato::factory()->create(['telefone' => '5521994359999']);
        $maior->telefone = '5521994359537'; // simula em memoria, nao grava

        $classificacao = $this->service->classificar($maior);

        $this->assertSame('mesclar', $classificacao['acao']);
        $this->assertSame($menor->id, $classificacao['alvo']->id);
        $this->assertSame('antigo', $classificacao['papel'], 'contato validado tem id maior -- ele deve ser o apagado');
    }

    public function test_duplicata_exata_contato_validado_com_id_menor_vira_canonico(): void
    {
        $menor = Contato::factory()->create(['telefone' => '5521994350001']);
        $maior = Contato::factory()->create(['telefone' => '5521994359537']);
        $menor->telefone = '5521994359537'; // simula em memoria, nao grava

        $classificacao = $this->service->classificar($menor);

        $this->assertSame('mesclar', $classificacao['acao']);
        $this->assertSame($maior->id, $classificacao['alvo']->id);
        $this->assertSame('canonico', $classificacao['papel'], 'contato validado tem id menor -- ele deve sobreviver');
    }
}
