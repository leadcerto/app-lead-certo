<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeteccaoIdiomaAlvoDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_covercut_traduz_pro_idioma_do_vendedor_atribuido_nao_mais_fixo_em_portugues(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.x'], 200)]);
        $tenant   = Tenant::factory()->create(['locale' => 'pt-BR']);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'idioma' => 'es-ES']);
        $canal    = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900001111']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano', 'vendedor_id' => $vendedor->id,
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $this->mock(TraducaoService::class, function ($mock) use ($vendedor) {
            $mock->shouldReceive('resolverIdiomaAtendente')->once()
                ->with($vendedor->id, 'pt-BR')->andReturn('es-ES');
            $mock->shouldReceive('detectarIdioma')->once()
                ->with('Do you deliver to São Paulo? I need this done urgently please.')
                ->andReturn('en');
            // Ticket ainda não tem idioma_lead nenhum (Camada 1 não rodou aqui —
            // o ticket já existia antes do webhook, então esta é a primeira
            // detecção do ticket): é sempre aceita direto, sem regra
            // anti-oscilação — não há "idioma atual" pra comparar/proteger.
            $mock->shouldReceive('deveAtualizarIdiomaLead')->never();
            $mock->shouldReceive('traduzir')->once()
                ->with('Do you deliver to São Paulo? I need this done urgently please.', 'es-ES', 'en')
                ->andReturn('¿Entregan en São Paulo? Necesito esto urgentemente por favor.');
        });

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900001111', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.det-1', 'type' => 'text', 'text' => 'Do you deliver to São Paulo? I need this done urgently please.'],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-det');
        $canal->update(['config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-det']]);

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'lead')->first();
        $this->assertSame('¿Entregan en São Paulo? Necesito esto urgentemente por favor.', $mensagem->conteudo_pt);
        // idioma_lead guarda o idioma DETECTADO do lead ('en'), não o idioma do
        // atendente ('es-ES') — os dois conceitos são deliberadamente separados
        // pela spec de design (docs/superpowers/specs/2026-08-21-idioma-
        // multilingue-atendimento-design.md, seção "Separação de conceitos":
        // "Idioma do atendente" e "Idioma preferido do cliente" nunca se
        // confundem). O idioma do atendente (resolvido via
        // resolverIdiomaAtendente()) é só o ALVO da tradução desta mensagem,
        // nunca é persistido em idioma_lead — corrigido aqui em relação ao
        // brief original da Task 5, que tinha essa asserção trocada.
        $this->assertSame('en', $ticket->fresh()->idioma_lead);
        $this->assertSame('ia', $ticket->fresh()->idioma_origem);
    }

    /**
     * Regra de prioridade da spec: escolha explícita (Camada 2) ou manual
     * (Camada 4) nunca é sobreposta silenciosamente pela IA — mesmo que o
     * texto seja longo/consistente o bastante pra passar na regra
     * anti-oscilação normalmente.
     */
    public function test_ia_nao_sobrepoe_idioma_definido_por_escolha_explicita(): void
    {
        $tenant  = Tenant::factory()->create(['locale' => 'pt-BR']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-travado'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900009999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'janela_expira_em' => now()->addHours(10),
            'idioma_lead' => 'es', 'idioma_origem' => 'botao',
        ]);

        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('resolverIdiomaAtendente')->once()->andReturn('pt-BR');
            $mock->shouldReceive('detectarIdioma')->once()
                ->with('I would like to know if my reservation for next week is already confirmed, please.')
                ->andReturn('en');
            $mock->shouldNotReceive('deveAtualizarIdiomaLead');
            $mock->shouldReceive('traduzir')->once()->andReturn('Tradução qualquer');
        });

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900009999', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.travado-1', 'type' => 'text', 'text' => 'I would like to know if my reservation for next week is already confirmed, please.'],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-travado');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        // idioma_lead continua 'es' (escolha explícita), mesmo a mensagem sendo
        // claramente em inglês e longa o bastante pra passar na regra normal.
        $this->assertSame('es', $ticket->fresh()->idioma_lead);
        $this->assertSame('botao', $ticket->fresh()->idioma_origem);
    }

    /**
     * Caso 3 dos critérios de aceite da spec: DDI bate com o tenant
     * (idioma_lead pré-preenchido pela Camada 1, idioma_origem='ddi', não
     * travado) mas a IA consulta a regra anti-oscilação — que autoriza a
     * troca porque manda um sinal considerado forte o bastante.
     */
    public function test_ia_atualiza_idioma_quando_regra_anti_oscilacao_autoriza(): void
    {
        $tenant  = Tenant::factory()->create(['locale' => 'pt-BR']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-autoriza'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900001212']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'janela_expira_em' => now()->addHours(10),
            'idioma_lead' => 'pt', 'idioma_origem' => 'ddi',
        ]);

        $texto = 'I would like to know if my reservation for next week is already confirmed, please.';

        $this->mock(TraducaoService::class, function ($mock) use ($texto) {
            $mock->shouldReceive('resolverIdiomaAtendente')->once()->andReturn('pt-BR');
            $mock->shouldReceive('detectarIdioma')->once()->with($texto)->andReturn('en');
            $mock->shouldReceive('deveAtualizarIdiomaLead')->once()
                ->with('pt', 'en', \Mockery::type(\Illuminate\Support\Collection::class), $texto)
                ->andReturn(true);
            $mock->shouldReceive('traduzir')->once()->andReturn('Tradução qualquer');
        });

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900001212', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.autoriza-1', 'type' => 'text', 'text' => $texto],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-autoriza');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $this->assertSame('en', $ticket->fresh()->idioma_lead);
        $this->assertSame('ia', $ticket->fresh()->idioma_origem);
        $this->assertNotNull($ticket->fresh()->idioma_atualizado_em);
    }

    /**
     * Caso 4 dos critérios de aceite da spec: mesma situação do caso 3, mas
     * a regra anti-oscilação recusa a troca (mensagem curta isolada) —
     * idioma_lead não muda, embora a mensagem em si tenha sido detectada e
     * ainda seja traduzida normalmente pro atendente.
     */
    public function test_ia_nao_atualiza_idioma_quando_regra_anti_oscilacao_recusa(): void
    {
        $tenant  = Tenant::factory()->create(['locale' => 'pt-BR']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-recusa'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900001313']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'janela_expira_em' => now()->addHours(10),
            'idioma_lead' => 'pt', 'idioma_origem' => 'ddi',
        ]);

        $texto = 'ok thanks';

        $this->mock(TraducaoService::class, function ($mock) use ($texto) {
            $mock->shouldReceive('resolverIdiomaAtendente')->once()->andReturn('pt-BR');
            $mock->shouldReceive('detectarIdioma')->once()->with($texto)->andReturn('en');
            $mock->shouldReceive('deveAtualizarIdiomaLead')->once()->andReturn(false);
            $mock->shouldReceive('traduzir')->once()->andReturn('ok obrigado');
        });

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900001313', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.recusa-1', 'type' => 'text', 'text' => $texto],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-recusa');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $this->assertSame('pt', $ticket->fresh()->idioma_lead);
        $this->assertSame('ddi', $ticket->fresh()->idioma_origem);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'lead')->first();
        $this->assertSame('en', $mensagem->idioma);
        $this->assertSame('ok obrigado', $mensagem->conteudo_pt);
    }
}
