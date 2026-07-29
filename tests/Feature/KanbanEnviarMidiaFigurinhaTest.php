<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KanbanEnviarMidiaFigurinhaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*/send/media' => Http::response(['id' => 'msg123'], 200)]);
    }

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-abc']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'token-abc'],
        ]);
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_arquivo_webp_e_enviado_como_figurinha_nao_como_imagem(): void
    {
        $ticket = $this->criarTicket();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('figurinha.webp')->mimeType('image/webp');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();

        Http::assertSent(fn ($request) => $request['type'] === 'sticker');
    }

    public function test_arquivo_jpg_continua_sendo_enviado_como_imagem(): void
    {
        $ticket = $this->criarTicket();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('foto.jpg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();

        Http::assertSent(fn ($request) => $request['type'] === 'image');
    }

    public function test_ticket_sem_canal_retorna_502_e_nao_deixa_arquivo_orfao_no_storage(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('foto.jpg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(502);
        Http::assertNotSent(fn ($request) => true);
        Storage::disk('public')->assertDirectoryEmpty('kanban-midia');
    }
}
