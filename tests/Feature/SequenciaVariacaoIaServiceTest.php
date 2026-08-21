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
 * Redesenho 2026-08-21 (Leonardo): "ficou muito estranho e complicado de
 * entender" — a versão anterior chamava a IA na hora de criar a mensagem e
 * já deixava as 6 variações ATIVAS no sorteio sem revisão nenhuma. Agora
 * `gerarVariacoesIniciais()` cria 6 CÓPIAS determinísticas do texto original
 * (sem IA, sem custo, sem risco de resposta esquisita indo pro sorteio sem
 * revisão) — todas INATIVAS até o humano revisar/editar e ativar. A geração
 * por IA vira uma ação explícita, por variação, via `regenerarUma()` (ver
 * SequenciaVariacaoIaServiceRegenerarUmaTest) ou em lote via `regenerar()`
 * (endpoint "Gerar variações com IA" já existente, sem mudança).
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

    public function test_cria_6_copias_do_conteudo_original_sem_chamar_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant, 'Olá {nome}, tudo bem?');

        Http::fake(); // se chamar qualquer HTTP, o teste falha por request inesperada

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(6, $criadas);
        Http::assertNothingSent();

        $variacoes = SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)
            ->where('protegida', false)->get();
        $this->assertCount(6, $variacoes);
        foreach ($variacoes as $v) {
            $this->assertSame('Olá {nome}, tudo bem?', $v->conteudo);
            $this->assertSame('humano', $v->origem);
            $this->assertFalse($v->ativa);
        }
    }

    public function test_nao_gera_de_novo_se_ja_existe_variacao_nao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Já existente', 'origem' => 'humano', 'protegida' => false, 'ativa' => false,
        ]);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        $this->assertSame(1, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->where('protegida', false)->count());
    }

    public function test_mensagem_sem_conteudo_nao_gera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant, '');

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        $this->assertSame(0, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->count());
    }
}
