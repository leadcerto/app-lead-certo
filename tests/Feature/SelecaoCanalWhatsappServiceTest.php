<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use App\Services\SelecaoCanalWhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelecaoCanalWhatsappServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seleciona_apenas_entre_canais_vinculados_e_conectados(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $vinculadoConectado    = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $vinculadoDesconectado = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'disconnected']);
        $naoVinculado          = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $kanban->canais()->attach([$vinculadoConectado->id, $vinculadoDesconectado->id]);

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertSame($vinculadoConectado->id, $selecionado->id);
    }

    public function test_retorna_null_quando_nao_ha_canal_disponivel(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertNull($selecionado);
    }

    public function test_prefere_canal_nao_oficial_quando_os_dois_tipos_estao_conectados(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $naoOficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'status' => 'connected']);
        $oficial    = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$naoOficial->id, $oficial->id]);

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertSame($naoOficial->id, $selecionado->id);
    }

    /**
     * Achado em 2026-08-03: quando o único canal não-oficial de um tenant é
     * desconectado (ex.: botão "Remover"), as chamadas proativas (ligação
     * perdida, formulário) passavam a sempre receber null aqui e o ticket
     * ficava sem NENHUM canal vinculado — nem dava pra responder manualmente
     * depois. Cair pro canal oficial evita esse buraco, mesmo que a Meta possa
     * rejeitar o envio por estar fora da janela de 24h.
     */
    public function test_cai_pro_canal_oficial_quando_nao_ha_canal_nao_oficial_conectado(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $oficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$oficial->id]);

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertNotNull($selecionado, 'Deveria cair pro canal oficial em vez de retornar null');
        $this->assertSame($oficial->id, $selecionado->id);
    }
}
