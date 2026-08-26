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
     * Segundo redesenho (2026-08-16): storeMensagem volta a chamar a IA na
     * hora de criar (mesmo prompt de `chamarIaEGerar()`), mas as 6 nascem
     * INATIVAS — resolve tanto o problema original (conteúdo indo pro
     * sorteio sem revisão) quanto o do redesenho anterior (6 cópias
     * idênticas, sem variedade nenhuma). Ver SequenciaVariacaoIaServiceTest
     * pro detalhe do serviço.
     */
    public function test_storeMensagem_cria_as_6_variacoes_via_ia_inativas(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $json      = json_encode(['variacoes' => [
            ['ordem' => 1, 'conteudo' => 'V1'], ['ordem' => 2, 'conteudo' => 'V2'],
            ['ordem' => 3, 'conteudo' => 'V3'], ['ordem' => 4, 'conteudo' => 'V4'],
            ['ordem' => 5, 'conteudo' => 'V5'], ['ordem' => 6, 'conteudo' => 'V6'],
        ]]);
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0,
        ]);

        $response->assertCreated();

        $msg = SequenciaMensagem::first();
        $this->assertSame(1, $msg->variacoes()->where('origem', 'humano')->where('protegida', true)->count());
        $this->assertSame(6, $msg->variacoes()->where('origem', 'ia')->where('protegida', false)->count());
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
        // Mesma regra de segurança do storeMensagem: variação nova via IA nasce
        // inativa, precisa de revisão humana antes de entrar no sorteio. Exclui
        // a $antiga (também origem=ia/ativa=false, mas por ter sido desativada
        // na regeneração, não por ter nascido assim) pra contar só as 6 novas.
        $this->assertSame(6, $msg->variacoes()->where('origem', 'ia')->where('ativa', false)->where('id', '!=', $antiga->id)->count());
    }
}
