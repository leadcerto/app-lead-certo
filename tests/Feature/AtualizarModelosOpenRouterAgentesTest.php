<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AtualizarModelosOpenRouterAgentesTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCatalogo(array $idsPresentes): void
    {
        $data = array_map(fn ($id) => [
            'id'             => $id,
            'context_length' => 32000,
        ], $idsPresentes);

        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => $data], 200),
        ]);
    }

    private function criarAgente(array $atributos = []): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create(array_merge([
            'tenant_id'         => $tenant->id,
            'is_ia'             => true,
            'ativo'             => true,
            'provedor_ia'       => 'openrouter',
            'openrouter_modelo' => 'meta-llama/llama-3.3-70b-instruct:free',
        ], $atributos));
    }

    public function test_troca_para_o_modelo_de_reserva_quando_o_modelo_do_agente_saiu_do_catalogo(): void
    {
        $this->fakeCatalogo(['openai/gpt-4o-mini', 'google/gemma-4-31b-it:free']);
        $agente = $this->criarAgente();

        $this->artisan('openrouter:atualizar-modelos')->assertSuccessful();

        $this->assertSame(OpenRouterService::MODELO_RESERVA, $agente->fresh()->openrouter_modelo);
    }

    public function test_nao_mexe_quando_o_modelo_do_agente_ainda_existe_no_catalogo(): void
    {
        $this->fakeCatalogo(['meta-llama/llama-3.3-70b-instruct:free', 'openai/gpt-4o-mini']);
        $agente = $this->criarAgente();

        $this->artisan('openrouter:atualizar-modelos')->assertSuccessful();

        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', $agente->fresh()->openrouter_modelo);
    }

    public function test_ignora_agente_inativo_ou_que_nao_usa_openrouter(): void
    {
        $this->fakeCatalogo(['openai/gpt-4o-mini']);
        $inativo    = $this->criarAgente(['ativo' => false]);
        $geminiDireto = $this->criarAgente(['provedor_ia' => 'gemini_direto']);

        $this->artisan('openrouter:atualizar-modelos')->assertSuccessful();

        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', $inativo->fresh()->openrouter_modelo);
        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', $geminiDireto->fresh()->openrouter_modelo);
    }

    public function test_registra_alerta_quando_ate_o_modelo_de_reserva_sumiu_do_catalogo(): void
    {
        $this->fakeCatalogo(['google/gemma-4-31b-it:free']);
        $this->criarAgente(['openrouter_modelo' => OpenRouterService::MODELO_RESERVA]);

        $this->artisan('openrouter:atualizar-modelos')->assertSuccessful();

        $this->assertNotNull(Cache::get('openrouter:reserva_indisponivel'));
    }
}
