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

class SequenciaVariacaoIaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarMensagem(Tenant $tenant, string $conteudo = 'Olá {nome}, tudo bem?'): SequenciaMensagem
    {
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);

        return SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => $conteudo, 'delay_segundos' => 0, 'ativo' => true,
        ]);
    }

    public function test_gera_6_variacoes_a_partir_da_resposta_da_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);

        $json = json_encode(['variacoes' => [
            ['ordem' => 1, 'conteudo' => 'Oi {nome}, como vai?'],
            ['ordem' => 2, 'conteudo' => 'Fala {nome}, tudo certo?'],
            ['ordem' => 3, 'conteudo' => 'E aí {nome}, beleza?'],
            ['ordem' => 4, 'conteudo' => 'Opa {nome}, tudo bem aí?'],
            ['ordem' => 5, 'conteudo' => 'Olá {nome}, como você está?'],
            ['ordem' => 6, 'conteudo' => 'Oii {nome}, tudo joia?'],
        ]]);

        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(6, $criadas);
        $this->assertSame(6, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->where('origem', 'ia')->count());
    }

    public function test_nao_gera_de_novo_se_ja_existe_variacao_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Já existente', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);

        Http::fake(); // se chamar a IA, o teste falha por request inesperada não fakeada com corpo

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        Http::assertNothingSent();
    }

    public function test_falha_da_ia_nao_quebra_e_retorna_zero(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);

        Http::fake(['openrouter.ai/*' => Http::response('erro', 500)]);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        $this->assertSame(0, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->count());
    }

    public function test_mensagem_sem_conteudo_nao_gera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant, '');

        Http::fake();

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        Http::assertNothingSent();
    }
}
