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

    public function test_ignora_canais_oficiais_na_selecao_de_prospeccao(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $oficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$oficial->id]);

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertNull($selecionado);
    }
}
