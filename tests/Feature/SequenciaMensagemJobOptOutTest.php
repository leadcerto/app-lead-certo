<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaMensagemJobOptOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_nao_envia_para_contato_bloqueado_naquele_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'bloqueado_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Oi {nome}, tudo bem?'))
            ->handle(app(\App\Services\HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
    }
}
