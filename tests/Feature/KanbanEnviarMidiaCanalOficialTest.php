<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
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

    /**
     * Nova funcionalidade (2026-08-03): áudio gravado/anexado direto no painel
     * (fora do WhatsApp) também é transcrito e ecoado como mensagem de texto na
     * conversa — mesma cobertura que o áudio do lead e do atendente via
     * WhatsApp Web já têm (ver EcoTranscricaoService).
     */
    public function test_audio_do_painel_e_transcrito_e_ecoado_na_conversa(): void
    {
        config(['services.groq.key' => 'fake-groq-key']);
        Http::fake([
            '*/messages/send' => Http::response(['id' => 'wamid.xyz'], 200),
            'api.groq.com/*'  => Http::response(['text' => 'aqui é o atendente confirmando o horário'], 200),
        ]);

        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.ogg', 10, 'audio/ogg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();

        $mensagemAudio = Mensagem::where('ticket_id', $ticket->id)->where('tipo', 'audio')->first();
        $this->assertNotNull($mensagemAudio);
        $this->assertSame('[Áudio transcrito: aqui é o atendente confirmando o horário]', $mensagemAudio->conteudo);

        $eco = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->latest()->first();
        $this->assertNotNull($eco, 'Eco da transcrição deveria ter sido salvo');
        $this->assertSame(
            "[Segue a transcrição do áudio enviado pelo Atendente]\n\naqui é o atendente confirmando o horário",
            $eco->conteudo
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && str_contains($request['text']['body'] ?? '', 'Segue a transcrição do áudio enviado pelo Atendente'));
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

    /**
     * Pedido do Leonardo (2026-08-05): poder ligar/desligar a transcrição por
     * coluna do Kanban — vale também pro áudio gravado direto no painel.
     */
    public function test_transcricao_desativada_na_coluna_nao_transcreve_audio_do_painel(): void
    {
        config(['services.groq.key' => 'fake-groq-key']);
        Http::fake([
            '*/messages/send' => Http::response(['id' => 'wamid.xyz'], 200),
            'api.groq.com/*'  => Http::response(['text' => 'não deveria chegar aqui'], 200),
        ]);

        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);
        \App\Models\KanbanColunaConfig::withoutGlobalScopes()->create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento', 'transcricao_ativa' => false,
        ]);

        $arquivo = UploadedFile::fake()->create('audio.ogg', 10, 'audio/ogg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => '[Áudio]']);
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'groq'));
    }

    /**
     * Reversão de 2026-08-21 (ver AudioConversorServiceTest): webm/wav agora
     * são convertidos pra ogg via ffmpeg antes de mandar pro Covercut, em vez
     * de só rejeitados. `Process::fake()` simula o ffmpeg criando o arquivo
     * de destino de verdade — sem ffmpeg instalado no ambiente de teste.
     */
    public function test_audio_webm_e_convertido_e_enviado_no_canal_oficial(): void
    {
        \Illuminate\Support\Facades\Process::fake(function ($process) {
            $destino = collect($process->command)->last();
            file_put_contents($destino, 'audio-convertido-fake');
            return \Illuminate\Support\Facades\Process::result(exitCode: 0);
        });

        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        // mimeType declarado como 'video/webm': é o que o guessExtension() do Symfony
        // resolve pra extensão 'webm' (o mapeamento reverso de 'audio/webm' cai em 'weba',
        // não em 'webm') — precisa bater com a extensão pra passar pela validação
        // 'mimes:mp3,ogg,webm,m4a,wav' já existente antes de chegar na checagem nova do Covercut.
        $arquivo = UploadedFile::fake()->create('audio.webm', 10, 'video/webm');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'audio' && $request['audio']['voice'] === true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'tipo' => 'audio']);
    }

    public function test_audio_wav_e_convertido_e_enviado_no_canal_oficial(): void
    {
        \Illuminate\Support\Facades\Process::fake(function ($process) {
            $destino = collect($process->command)->last();
            file_put_contents($destino, 'audio-convertido-fake');
            return \Illuminate\Support\Facades\Process::result(exitCode: 0);
        });

        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.wav', 10, 'audio/wav');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'audio');
    }

    /**
     * Rede de segurança: se o ffmpeg não estiver disponível/der erro no
     * servidor, cai de volta na mensagem de erro clara que já existia antes
     * — nunca manda o arquivo incompatível pro Covercut, nunca quebra com
     * erro genérico.
     */
    public function test_audio_webm_com_falha_de_conversao_cai_no_erro_especifico(): void
    {
        \Illuminate\Support\Facades\Process::fake(
            fn () => \Illuminate\Support\Facades\Process::result(exitCode: 127, errorOutput: 'ffmpeg: not found')
        );

        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.webm', 10, 'video/webm');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'O canal Oficial (WhatsApp Business) não aceita áudio nesse formato (.webm). Anexe um arquivo de áudio nos formatos .mp3, .ogg ou .m4a.']);
        Http::assertNothingSent();
        Storage::disk('public')->assertDirectoryEmpty('kanban-midia');
    }

    /**
     * Achado real 2026-08-14: a validação de upload aceitava até 32MB pra
     * qualquer tipo de mídia, em qualquer canal — bem acima dos limites reais
     * da Meta Cloud API (imagem 5MB). O upload passava na validação daqui e só
     * falhava na chamada real à API, virando o 502 genérico "Falha ao enviar
     * pelo WhatsApp" sem explicar o motivo real pro atendente.
     */
    public function test_imagem_acima_de_5mb_falha_na_validacao_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('foto-grande.jpg', 6000, 'image/jpeg'); // 6MB > limite de 5MB

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('arquivo');
        Http::assertNothingSent();
        Storage::disk('public')->assertDirectoryEmpty('kanban-midia');
    }

    public function test_figurinha_acima_de_500kb_falha_na_validacao_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('fig-grande.webp', 600, 'image/webp'); // 600KB > limite de 500KB pra sticker

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('arquivo');
        Http::assertNothingSent();
    }

    public function test_documento_acima_de_100mb_falha_na_validacao_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('doc-grande.pdf', 103000, 'application/pdf'); // ~100.6MB > limite de 100MB

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'documento',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('arquivo');
        Http::assertNothingSent();
    }

    public function test_gif_nao_e_aceito_como_imagem_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('anim.gif', 10, 'image/gif');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('arquivo');
        Http::assertNothingSent();
    }

    /**
     * Imagem dentro do limite (abaixo de 5MB) continua funcionando
     * normalmente — não é só rejeição, o caminho feliz não pode regredir.
     */
    public function test_imagem_dentro_do_limite_continua_funcionando_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('foto.jpg', 3000, 'image/jpeg'); // 3MB, dentro do limite de 5MB

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'image');
    }
}
