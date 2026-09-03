<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\User;
use App\Services\AgenteIaResolver;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgenteIaResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_agente_id_corretamente_pela_origem(): void
    {
        $cargo = Cargo::create(['nome' => 'Gestor Comercial', 'tipo' => 'comercial', 'descricao' => 'com']);
        $nathanel = User::factory()->create([
            'nome'  => 'Nathanel Fernandes',
            'email' => 'nathanel@leadcerto.com',
            'is_ia' => true,
        ]);
        $nathanel->cargos()->attach($cargo->id);

        $resolvedId = AgenteIaResolver::resolverAgenteId('gestor_kanban');
        $this->assertSame($nathanel->id, $resolvedId);

        $resolvedSdrId = AgenteIaResolver::resolverAgenteId('sdr_resposta');
        $this->assertSame($nathanel->id, $resolvedSdrId);
    }

    public function test_openrouter_service_grava_agente_id_automaticamente(): void
    {
        $cargo = Cargo::create(['nome' => 'Orquestrador Geral IA', 'tipo' => 'inteligencia', 'descricao' => 'orq']);
        $gabriel = User::factory()->create([
            'nome'  => 'Gabriel',
            'email' => 'gabriel@leadcerto.com',
            'is_ia' => true,
        ]);
        $gabriel->cargos()->attach($cargo->id);

        Http::fake([
            '*openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Resposta da IA']]],
                'usage'   => ['prompt_tokens' => 120, 'completion_tokens' => 45],
            ], 200),
        ]);

        $service = app(OpenRouterService::class);
        $res = $service->chat([['role' => 'user', 'content' => 'Olá']], 'simples', 100, 'qa_auditoria');

        $this->assertSame('Resposta da IA', $res);
        $this->assertDatabaseHas('ia_usages', [
            'agente_id' => $gabriel->id,
            'origem'    => 'qa_auditoria',
            'tokens_input' => 120,
            'tokens_output' => 45,
        ]);
    }
}
