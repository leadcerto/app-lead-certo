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
}
