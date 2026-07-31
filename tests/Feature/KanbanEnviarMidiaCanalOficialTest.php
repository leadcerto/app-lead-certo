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

class KanbanEnviarMidiaCanalOficialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.xyz'], 200)]);
    }

    private function criarTicketOficial(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);
    }

    public function test_envia_imagem_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('foto.jpg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'image');
    }

    public function test_envia_audio_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.ogg', 10, 'audio/ogg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'audio' && $request['audio']['voice'] === true);
    }

    public function test_envia_documento_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('boleto.pdf', 10, 'application/pdf');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'documento',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'document' && $request['document']['filename'] === 'boleto.pdf');
    }

    public function test_envia_figurinha_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('fig.webp')->mimeType('image/webp');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'sticker');
    }
}
