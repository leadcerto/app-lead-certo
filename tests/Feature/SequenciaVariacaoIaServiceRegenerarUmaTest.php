<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Services\SequenciaVariacaoIaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido do Leonardo (2026-08-21): além da geração em lote (todas as 6 de
 * uma vez, endpoint "gerar" já existente), um botão pra pedir à IA uma nova
 * versão de UMA variação específica — usa a mensagem original (protegida)
 * como referência, sem mexer nas outras variações.
 */
class SequenciaVariacaoIaServiceRegenerarUmaTest extends TestCase
{
    use RefreshDatabase;

    private function criarVariacao(string $conteudoOriginal, bool $ativa = false): SequenciaMensagemVariacao
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => $conteudoOriginal, 'delay_segundos' => 0, 'ativo' => true,
        ]);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => $conteudoOriginal, 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        return SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => $conteudoOriginal, 'origem' => 'humano', 'protegida' => false, 'ativa' => $ativa,
        ]);
    }

    public function test_gera_nova_versao_pra_variacao_especifica_e_marca_origem_ia(): void
    {
        $variacao = $this->criarVariacao('Olá {nome}, tudo bem?');

        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['conteudo' => 'E aí {nome}, tudo certo?'])]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $ok = app(SequenciaVariacaoIaService::class)->regenerarUma($variacao);

        $this->assertTrue($ok);
        $variacao->refresh();
        $this->assertSame('E aí {nome}, tudo certo?', $variacao->conteudo);
        $this->assertSame('ia', $variacao->origem);
    }

    public function test_mantem_estado_ativa_como_estava_antes(): void
    {
        $variacao = $this->criarVariacao('Olá {nome}, tudo bem?', ativa: true);

        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['conteudo' => 'Nova versão'])]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        app(SequenciaVariacaoIaService::class)->regenerarUma($variacao);

        $this->assertTrue($variacao->fresh()->ativa);
    }

    public function test_falha_da_ia_nao_altera_o_conteudo_e_retorna_false(): void
    {
        $variacao = $this->criarVariacao('Texto original');

        Http::fake(['openrouter.ai/*' => Http::response('erro', 500)]);

        $ok = app(SequenciaVariacaoIaService::class)->regenerarUma($variacao);

        $this->assertFalse($ok);
        $this->assertSame('Texto original', $variacao->fresh()->conteudo);
    }
}
