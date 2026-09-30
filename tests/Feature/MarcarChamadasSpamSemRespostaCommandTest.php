<?php

namespace Tests\Feature;

use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pendência registrada em 22/09 (Leonardo): Caso B de spam/telemarketing na
 * Secretária Eletrônica — número TEM WhatsApp, mensagem de abertura foi
 * enviada, mas o lead nunca respondeu (padrão típico de quem liga sem
 * intenção real de falar com a empresa; quem liga de verdade responde).
 */
class MarcarChamadasSpamSemRespostaCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarChamada(array $overrides = []): ChamadaPerdida
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create(array_merge([
            'tenant_id'     => $tenant->id,
            'contato_id'    => $contato->id,
            'coluna_kanban' => 'em_atendimento',
            'agente_responsavel' => 'bot',
            'status'        => 'aberto',
            'aberto_em'     => now(),
        ], $overrides['ticket'] ?? []));

        return ChamadaPerdida::create(array_merge([
            'tenant_id'                   => $tenant->id,
            'contato_id'                  => $contato->id,
            'ticket_id'                   => $ticket->id,
            'numero_chamador'             => '5511999998888',
            'numero_receptor'             => '5521981813106',
            'chamou_em'                   => now()->subDays(5),
            'duracao_segundos'            => 0,
            'mensagem_enviada'            => true,
            'mensagem_enviada_em'         => now()->subDays(4),
            'numero_invalido'             => false,
            'provavel_spam_sem_resposta'  => false,
        ], $overrides['chamada'] ?? []));
    }

    public function test_marca_como_spam_quando_mensagem_enviada_ha_mais_de_3_dias_e_lead_nunca_respondeu(): void
    {
        $chamada = $this->criarChamada();

        $this->artisan('chamadas:marcar-spam-sem-resposta')->assertSuccessful();

        $this->assertTrue($chamada->fresh()->provavel_spam_sem_resposta);
    }

    public function test_nao_marca_quando_lead_ja_respondeu(): void
    {
        $chamada = $this->criarChamada([
            'ticket' => ['ultima_mensagem_lead_em' => now()->subDays(2)],
        ]);

        $this->artisan('chamadas:marcar-spam-sem-resposta')->assertSuccessful();

        $this->assertFalse($chamada->fresh()->provavel_spam_sem_resposta);
    }

    public function test_nao_marca_quando_ainda_dentro_do_prazo_padrao_de_3_dias(): void
    {
        $chamada = $this->criarChamada([
            'chamada' => ['mensagem_enviada_em' => now()->subDay()],
        ]);

        $this->artisan('chamadas:marcar-spam-sem-resposta')->assertSuccessful();

        $this->assertFalse($chamada->fresh()->provavel_spam_sem_resposta);
    }

    public function test_nao_marca_chamada_de_numero_invalido_caso_a(): void
    {
        $chamada = $this->criarChamada([
            'chamada' => ['numero_invalido' => true],
        ]);

        $this->artisan('chamadas:marcar-spam-sem-resposta')->assertSuccessful();

        $this->assertFalse($chamada->fresh()->provavel_spam_sem_resposta);
    }

    public function test_respeita_opcao_dias_customizada(): void
    {
        $chamada = $this->criarChamada([
            'chamada' => ['mensagem_enviada_em' => now()->subDays(2)],
        ]);

        $this->artisan('chamadas:marcar-spam-sem-resposta', ['--dias' => 1])->assertSuccessful();

        $this->assertTrue($chamada->fresh()->provavel_spam_sem_resposta);
    }

    public function test_nao_marca_quando_mensagem_nunca_foi_enviada(): void
    {
        $chamada = $this->criarChamada([
            'chamada' => ['mensagem_enviada' => false, 'mensagem_enviada_em' => null],
        ]);

        $this->artisan('chamadas:marcar-spam-sem-resposta')->assertSuccessful();

        $this->assertFalse($chamada->fresh()->provavel_spam_sem_resposta);
    }
}
