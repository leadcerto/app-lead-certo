<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Models\WhatsappEnvioDiario;
use App\Services\AquecimentoWhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AquecimentoWhatsappServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AquecimentoWhatsappService
    {
        return app(AquecimentoWhatsappService::class);
    }

    private function canal(array $overrides = []): WhatsappCanal
    {
        $tenant = Tenant::factory()->create();

        return WhatsappCanal::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
        ], $overrides));
    }

    // ─── Curva de rampa por dia ─────────────────────────────────────────────

    public function test_dia_zero_perfil_protegido_tem_limite_minimo(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()]);

        $limites = $this->service()->limiteHoje($canal);

        $this->assertSame(0, $limites['frio']);
        $this->assertLessThanOrEqual(5, $limites['quente']);
    }

    public function test_dia_quatorze_perfil_protegido_atinge_teto_de_regime(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()->subDays(20)]);

        $limites = $this->service()->limiteHoje($canal);

        $this->assertSame(50, $limites['frio']);
        $this->assertSame(200, $limites['quente']);
    }

    public function test_perfil_descartavel_rampa_mais_rapido_que_protegido_no_mesmo_dia(): void
    {
        $canalProtegido   = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()->subDays(4)]);
        $canalDescartavel = $this->canal(['perfil_aquecimento' => 'descartavel', 'aquecimento_iniciado_em' => now()->subDays(4)]);

        $limitesProtegido   = $this->service()->limiteHoje($canalProtegido);
        $limitesDescartavel = $this->service()->limiteHoje($canalDescartavel);

        $this->assertGreaterThan($limitesProtegido['frio'], $limitesDescartavel['frio']);
    }

    public function test_teto_de_regime_nunca_e_ultrapassado_mesmo_com_muitos_dias(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'descartavel', 'aquecimento_iniciado_em' => now()->subDays(500)]);

        $limites = $this->service()->limiteHoje($canal);

        $this->assertSame(50, $limites['frio']);
        $this->assertSame(200, $limites['quente']);
    }

    // ─── Horário permitido (bloqueio 23h-7h Brasília) ──────────────────────

    public function test_bloqueia_envio_durante_a_madrugada_horario_de_brasilia(): void
    {
        $meiaNoite = Carbon::parse('2026-08-20 00:30:00', 'America/Sao_Paulo');

        $this->assertFalse($this->service()->dentroDoHorarioPermitido($meiaNoite));
    }

    public function test_permite_envio_durante_o_dia_horario_de_brasilia(): void
    {
        $meioDia = Carbon::parse('2026-08-20 14:00:00', 'America/Sao_Paulo');

        $this->assertTrue($this->service()->dentroDoHorarioPermitido($meioDia));
    }

    // ─── Classificação frio/quente ──────────────────────────────────────────

    public function test_classifica_como_frio_quando_contato_nunca_mandou_mensagem(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('frio', $this->service()->classificarContato('5521999998888', $tenant->id));
    }

    public function test_classifica_como_quente_quando_contato_ja_mandou_mensagem_antes(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['telefone' => '5521999998888']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Mensagem::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi', 'enviado_em' => now(),
        ]);

        $this->assertSame('quente', $this->service()->classificarContato('5521999998888', $tenant->id));
    }

    // ─── podeEnviar() + registrarEnvio() — o teto de verdade ───────────────

    public function test_pode_enviar_quando_ainda_nao_bateu_o_limite_do_dia(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()->subDays(20)]);

        $this->assertTrue($this->service()->podeEnviar($canal, '5521999998888'));
    }

    public function test_bloqueia_quando_o_limite_frio_do_dia_ja_foi_atingido(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()->subDays(1)]);
        // Dia 1, perfil protegido: limite frio é 0 — qualquer envio frio já bloqueia.

        $this->assertFalse($this->service()->podeEnviar($canal, '5521999998888'));
    }

    public function test_registrar_envio_incrementa_o_contador_do_dia(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()->subDays(20)]);

        $this->service()->registrarEnvio($canal, '5521999998888');
        $this->service()->registrarEnvio($canal, '5521999997777');

        $envio = WhatsappEnvioDiario::where('whatsapp_canal_id', $canal->id)->first();
        $this->assertSame(2, $envio->contador_frio);
    }

    public function test_contador_de_ontem_nao_conta_pro_limite_de_hoje(): void
    {
        $canal = $this->canal(['perfil_aquecimento' => 'protegido', 'aquecimento_iniciado_em' => now()->subDays(20)]);
        WhatsappEnvioDiario::create([
            'whatsapp_canal_id' => $canal->id,
            'data'              => now()->subDay()->toDateString(),
            'contador_frio'     => 50, // já bateu o teto de regime, mas foi ONTEM
        ]);

        $this->assertTrue($this->service()->podeEnviar($canal, '5521999998888'));
    }
}
