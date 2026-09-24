<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Models\WhatsappEnvioDiario;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado do planejamento do canal WhatsApp Messenger próprio (23/09): os
 * caminhos de texto puro e imagem/fallback do SequenciaMensagemJob (canal
 * não-oficial) chamavam UazapiService/HumanizacaoService::processar()
 * diretamente com o token do canal, em vez de passar por
 * $canal->servico()->enviarTexto()/enviarImagem() — pulando por completo a
 * checagem de teto diário do AquecimentoWhatsappService (UazapiChannelService
 * é o único lugar que faz essa checagem). Uma Sequência de Mensagens
 * conseguia estourar o limite anti-ban do número sem nenhum bloqueio.
 */
class SequenciaMensagemJobRespeitaAquecimentoTest extends TestCase
{
    use RefreshDatabase;

    private function canalNoTeto(): WhatsappCanal
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id'               => $tenant->id,
            'perfil_aquecimento'      => 'protegido',
            'aquecimento_iniciado_em' => now()->subDays(20), // já no teto de regime (50/dia frio)
            'config'                  => ['instance_token' => 'tok-canal'],
        ]);

        WhatsappEnvioDiario::create([
            'whatsapp_canal_id' => $canal->id,
            'data'              => now()->toDateString(),
            'contador_frio'     => 50, // teto de regime já batido hoje
            'contador_quente'   => 0,
        ]);

        return $canal;
    }

    public function test_texto_puro_nao_envia_quando_canal_ja_bateu_o_teto_diario(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $canal   = $this->canalNoTeto();
        $contato = Contato::factory()->create(['telefone' => '5511999998888']); // nunca falou = frio
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $canal->tenant_id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Oi, tudo bem?'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
    }

    public function test_imagem_nao_envia_quando_canal_ja_bateu_o_teto_diario(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $canal   = $this->canalNoTeto();
        $contato = Contato::factory()->create(['telefone' => '5511999997777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $canal->tenant_id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Segue a imagem', 'https://exemplo.com/foto.jpg'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
    }
}
