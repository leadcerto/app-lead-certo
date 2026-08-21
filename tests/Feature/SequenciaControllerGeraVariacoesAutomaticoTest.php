<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaControllerGeraVariacoesAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    /**
     * Redesenho 2026-08-21: storeMensagem não chama mais a IA na hora de
     * criar — cria 6 cópias inativas do texto original na hora (grátis,
     * instantâneo, sem depender do OpenRouter estar de pé). Ver
     * SequenciaVariacaoIaServiceTest pro detalhe do serviço.
     */
    public function test_storeMensagem_cria_as_6_copias_inativas_sem_chamar_ia(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        Http::fake();

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0,
        ]);

        $response->assertCreated();
        Http::assertNothingSent();

        $msg = SequenciaMensagem::first();
        $this->assertSame(1, $msg->variacoes()->where('origem', 'humano')->where('protegida', true)->count());
        $this->assertSame(6, $msg->variacoes()->where('protegida', false)->count());
        $this->assertSame(0, $msg->variacoes()->where('ativa', true)->where('protegida', false)->count());
    }

    public function test_endpoint_de_regeneracao_em_lote_substitui_variacoes_ia(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $antiga = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Antiga', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);
        $json = json_encode(['variacoes' => [
            ['ordem' => 1, 'conteudo' => 'V1'], ['ordem' => 2, 'conteudo' => 'V2'],
            ['ordem' => 3, 'conteudo' => 'V3'], ['ordem' => 4, 'conteudo' => 'V4'],
            ['ordem' => 5, 'conteudo' => 'V5'], ['ordem' => 6, 'conteudo' => 'V6'],
        ]]);
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $response = $this->actingAs($user)->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/gerar");

        $response->assertOk();
        $this->assertFalse($antiga->fresh()->ativa);
        $this->assertSame(6, $msg->variacoes()->where('origem', 'ia')->where('ativa', true)->count());
    }
}
