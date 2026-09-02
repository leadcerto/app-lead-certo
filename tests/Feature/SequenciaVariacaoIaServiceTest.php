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
 * Segundo redesenho (2026-08-16, mesma virada de sessão em que o
 * Leonardo testemunhou o resultado do primeiro): o redesenho de 2026-08-21
 * trocou a chamada de IA por 6 cópias verbatim do texto original — resolvia
 * o problema de conteúdo indo pro sorteio sem revisão, mas foi longe demais:
 * as 6 abas de Variações ficavam com texto idêntico, sem nenhuma variedade.
 * Meio-termo adotado agora: `gerarVariacoesIniciais()` volta a chamar a IA
 * (mesmo prompt/regras que já existiam em `chamarIaEGerar()`, reusado por
 * `regenerar()`), mas as 6 continuam nascendo INATIVAS — o humano revisa e
 * ativa cada uma que aprovar; a variação Original (protegida) continua
 * sendo a única ativa até isso acontecer, então o envio nunca quebra.
 */
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

    private function fakeRespostaIa(array $textos): void
    {
        $json = json_encode(['variacoes' => array_map(
            fn ($texto, $i) => ['ordem' => $i + 1, 'conteudo' => $texto],
            $textos,
            array_keys($textos),
        )]);

        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    public function test_gera_6_variacoes_via_ia_todas_inativas(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant, 'Olá {nome}, tudo bem?');
        $textos = ['V1', 'V2', 'V3', 'V4', 'V5', 'V6'];
        $this->fakeRespostaIa($textos);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(6, $criadas);

        $variacoes = SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)
            ->where('protegida', false)->get();
        $this->assertCount(6, $variacoes);
        foreach ($variacoes as $v) {
            $this->assertContains($v->conteudo, $textos);
            $this->assertSame('ia', $v->origem);
            $this->assertFalse($v->ativa);
        }
    }

    public function test_ia_indisponivel_cria_rascunhos_sugeridos_sem_quebrar(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);
        Http::fake(['openrouter.ai/*' => Http::response('erro interno', 500)]);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(6, $criadas);
        $this->assertSame(6, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->where('protegida', false)->count());
    }

    public function test_completa_slots_restantes_se_ja_existe_variacao(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Já existente', 'origem' => 'humano', 'protegida' => false, 'ativa' => false,
        ]);
        Http::fake(); // se chamar qualquer HTTP, o teste falha por request inesperada

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(5, $criadas);
        Http::assertNothingSent();
        $this->assertSame(6, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->where('protegida', false)->count());
    }

    public function test_mensagem_sem_conteudo_nao_gera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant, '');
        Http::fake();

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        Http::assertNothingSent();
        $this->assertSame(0, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->count());
    }
}
