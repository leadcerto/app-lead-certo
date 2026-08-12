<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecretariaEletronicaEnvioAtivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_envia_mensagem_de_abertura_quando_envio_esta_desativado(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create([
            'secretaria_token'        => 'token-teste-off',
            'secretaria_envio_ativo'  => false,
        ]);

        $response = $this->postJson('/api/secretaria/token-teste-off', [
            'numero_chamador'  => '11999998888',
            'duracao_segundos' => 0,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SequenciaMensagemJob::class);
        $this->assertSame(1, TicketAtendimento::where('tenant_id', $tenant->id)->count());
    }

    public function test_envia_mensagem_de_abertura_quando_envio_esta_ativado(): void
    {
        Queue::fake();

        Tenant::factory()->create([
            'secretaria_token'       => 'token-teste-on',
            'secretaria_envio_ativo' => true,
        ]);

        $this->postJson('/api/secretaria/token-teste-on', [
            'numero_chamador'  => '11999997777',
            'duracao_segundos' => 0,
        ]);

        Queue::assertPushed(SequenciaMensagemJob::class);
    }

    public function test_dados_painel_expoe_o_estado_do_envio(): void
    {
        $tenant = Tenant::factory()->create(['secretaria_envio_ativo' => false]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/secretaria-eletronica/dados');

        $response->assertOk();
        $this->assertFalse($response->json('envio_ativo'));
    }

    public function test_toggle_envio_liga_e_desliga(): void
    {
        $tenant = Tenant::factory()->create(['secretaria_envio_ativo' => true]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/secretaria-eletronica/toggle', [
            'ativo' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($tenant->fresh()->secretaria_envio_ativo);
    }

    /**
     * Pedido do Leonardo (2026-08-12): poder anexar imagem na mensagem de abertura
     * da Secretária. A mensagem já passa por SequenciaMensagemJob, que já aceita
     * imagem — só faltava a tela de upload + repassar a URL salva na hora de disparar.
     */
    public function test_salvar_imagem_faz_upload_e_persiste_url(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->post('/api/painel/secretaria-eletronica/imagem', [
            'imagem' => UploadedFile::fake()->image('abertura.jpg'),
        ]);

        $response->assertOk();
        $url = $response->json('imagem_url');
        $this->assertNotEmpty($url);
        $this->assertSame($url, $tenant->fresh()->secretaria_mensagem_inicial_imagem_url);
        Storage::disk('public')->assertExists(str_replace(url('storage/') . '/', '', $url));
    }

    public function test_salvar_imagem_substitui_e_apaga_a_anterior(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->post('/api/painel/secretaria-eletronica/imagem', [
            'imagem' => UploadedFile::fake()->image('primeira.jpg'),
        ]);
        $primeiraUrl  = $tenant->fresh()->secretaria_mensagem_inicial_imagem_url;
        $primeiroPath = str_replace(url('storage/') . '/', '', $primeiraUrl);

        $this->actingAs($user)->post('/api/painel/secretaria-eletronica/imagem', [
            'imagem' => UploadedFile::fake()->image('segunda.jpg'),
        ]);

        Storage::disk('public')->assertMissing($primeiroPath);
        $this->assertNotSame($primeiraUrl, $tenant->fresh()->secretaria_mensagem_inicial_imagem_url);
    }

    public function test_remover_imagem_apaga_arquivo_e_limpa_url(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->post('/api/painel/secretaria-eletronica/imagem', [
            'imagem' => UploadedFile::fake()->image('abertura.jpg'),
        ]);
        $path = str_replace(url('storage/') . '/', '', $tenant->fresh()->secretaria_mensagem_inicial_imagem_url);

        $response = $this->actingAs($user)->deleteJson('/api/painel/secretaria-eletronica/imagem');

        $response->assertOk();
        $this->assertNull($tenant->fresh()->secretaria_mensagem_inicial_imagem_url);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_dados_painel_expoe_a_url_da_imagem(): void
    {
        $tenant = Tenant::factory()->create(['secretaria_mensagem_inicial_imagem_url' => 'https://app.leadcerto.app.br/storage/secretaria-imagens/foto.jpg']);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/secretaria-eletronica/dados');

        $response->assertOk();
        $this->assertSame('https://app.leadcerto.app.br/storage/secretaria-imagens/foto.jpg', $response->json('mensagem_imagem_url'));
    }

    public function test_chamada_perdida_dispara_sequencia_com_a_imagem_configurada(): void
    {
        Queue::fake();

        Tenant::factory()->create([
            'secretaria_token'                       => 'token-com-imagem',
            'secretaria_envio_ativo'                  => true,
            'secretaria_mensagem_inicial_imagem_url'  => 'https://app.leadcerto.app.br/storage/secretaria-imagens/foto.jpg',
        ]);

        $this->postJson('/api/secretaria/token-com-imagem', [
            'numero_chamador'  => '11999996666',
            'duracao_segundos' => 0,
        ]);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->imagemUrl === 'https://app.leadcerto.app.br/storage/secretaria-imagens/foto.jpg');
    }
}
