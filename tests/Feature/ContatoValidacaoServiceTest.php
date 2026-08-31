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
        // Achado da revisão final (I3): com a validação de DDD real
        // adicionada, TelefoneReparoService::candidatos() não consegue mais
        // gerar 2 candidatos distintos e válidos a partir de UM único
        // telefone malformado -- a ambiguidade antiga ('09988887777') só
        // existia porque a regra direta de 11 dígitos lia "09" como DDD e
        // isso passava sem checagem; nenhum DDD brasileiro de verdade
        // começa com 0, então essa interpretação é sempre inválida agora, e
        // sobra só 1 candidato real por telefone. Esse teste passa a
        // verificar o ramo de ambiguidade de classificar() isoladamente,
        // mockando TelefoneReparoService pra simular 2 candidatos válidos
        // batendo com registros diferentes -- o comportamento que
        // ContatoValidacaoService precisa continuar tratando corretamente
        // mesmo que a geração de candidatos hoje nunca produza esse caso
        // sozinha.
        $registroA = Contato::factory()->create(['telefone' => '5511988887777']);
        $registroB = Contato::factory()->create(['telefone' => '5521988887777']);
        $ambiguo   = Contato::factory()->create(['telefone' => '5599988887777']);

        $this->mock(\App\Services\TelefoneReparoService::class, function ($mock) use ($ambiguo) {
            $mock->shouldReceive('ehCanonico')->with($ambiguo->telefone)->andReturn(false);
            $mock->shouldReceive('candidatos')->with($ambiguo->telefone)->andReturn(['5511988887777', '5521988887777']);
        });
        $service = app(ContatoValidacaoService::class);

        $resultado = $service->validar($ambiguo);

        $this->assertSame('lead_invalido', $resultado);
        $this->assertDatabaseHas('contatos', ['id' => $ambiguo->id, 'telefone' => '5599988887777', 'deleted_at' => null]);
        $this->assertDatabaseHas('contatos', ['id' => $registroA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('contatos', ['id' => $registroB->id, 'deleted_at' => null]);
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
