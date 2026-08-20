<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\FeedbackAgente;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Redesenho 2026-08-20 (Leonardo): um bloco por agente (foto + composer
 * estilo WhatsApp interno) na tela /equipe/suporte — cliente escreve,
 * manda imagem/arquivo/áudio (áudio vira texto transcrito). Por trás
 * continua roteado por SETOR (cargo), não por pessoa.
 */
class FeedbackAgenteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function setorSuporte(Tenant $leadCerto): array
    {
        $cargo = Cargo::create([
            'nome' => 'Gerente de Suporte', 'descricao' => 'x',
            'descricao_cliente' => 'Vou te ajudar a entender sobre cada detalhe da nossa ferramenta.',
            'ordem' => 1, 'visivel_para_clientes' => true,
        ]);
        $agente = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'dono', 'nome' => 'Adriana Aviag']);
        $agente->cargos()->attach($cargo->id);

        return [$cargo, $agente];
    }

    public function test_lista_bloco_so_pra_cargo_visivel_com_descricao_amigavel(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $this->setorSuporte($leadCerto);
        Cargo::create(['nome' => 'Gestor de SEO', 'descricao' => 'interno', 'ordem' => 2, 'visivel_para_clientes' => false]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/equipe/suporte');

        $response->assertOk();
        $response->assertSee('Adriana Aviag');
        $response->assertSee('Vou te ajudar a entender sobre cada detalhe da nossa ferramenta.');
        $response->assertDontSee('Gestor de SEO');
    }

    public function test_usuario_manda_mensagem_de_texto_pro_setor(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor, $agente] = $this->setorSuporte($leadCerto);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/setor/{$setor->id}/conversar", [
            'mensagem' => 'O sistema está travando quando eu tento mover o card.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('feedbacks_agente', [
            'user_id'       => $agente->id,
            'cargo_id'      => $setor->id,
            'tenant_id'     => $tenant->id,
            'autor_user_id' => $user->id,
            'mensagem'      => 'O sistema está travando quando eu tento mover o card.',
            'resposta'      => FeedbackAgente::RESPOSTA_PADRAO,
            'status'        => 'pendente',
            'tipo_midia'    => null,
        ]);
    }

    public function test_usuario_manda_imagem_com_legenda(): void
    {
        Storage::fake('public');
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor] = $this->setorSuporte($leadCerto);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/setor/{$setor->id}/conversar", [
            'mensagem' => 'Olha essa tela aqui',
            'arquivo'  => UploadedFile::fake()->image('print.png'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('feedbacks_agente', [
            'cargo_id' => $setor->id, 'mensagem' => 'Olha essa tela aqui', 'tipo_midia' => 'imagem',
        ]);
        $fb = FeedbackAgente::first();
        $this->assertNotNull($fb->midia_url);
    }

    public function test_usuario_manda_audio_e_recebe_transcricao_como_mensagem(): void
    {
        Storage::fake('public');
        config(['services.groq.key' => 'fake-groq-key']);
        Http::fake(['api.groq.com/*' => Http::response(['text' => 'o card não está movendo de coluna'], 200)]);

        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor] = $this->setorSuporte($leadCerto);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/setor/{$setor->id}/conversar", [
            'arquivo' => UploadedFile::fake()->create('audio.ogg', 10, 'audio/ogg'),
        ]);

        $response->assertRedirect();
        $fb = FeedbackAgente::first();
        $this->assertSame('audio', $fb->tipo_midia);
        $this->assertStringContainsString('o card não está movendo de coluna', $fb->mensagem);
    }

    public function test_nao_deixa_mandar_sem_mensagem_e_sem_arquivo(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor] = $this->setorSuporte($leadCerto);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/setor/{$setor->id}/conversar", []);

        $response->assertSessionHasErrors('mensagem');
        $this->assertDatabaseCount('feedbacks_agente', 0);
    }

    public function test_nao_deixa_mandar_pra_cargo_nao_visivel(): void
    {
        $interno = Cargo::create(['nome' => 'Gestor de SEO', 'descricao' => 'x', 'ordem' => 1, 'visivel_para_clientes' => false]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/setor/{$interno->id}/conversar", ['mensagem' => 'oi']);

        $response->assertStatus(404);
    }

    public function test_ve_o_historico_so_da_propria_empresa(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor] = $this->setorSuporte($leadCerto);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);

        FeedbackAgente::create([
            'cargo_id' => $setor->id, 'tenant_id' => $tenantA->id,
            'mensagem' => 'Mensagem da empresa A', 'resposta' => FeedbackAgente::RESPOSTA_PADRAO,
        ]);
        FeedbackAgente::create([
            'cargo_id' => $setor->id, 'tenant_id' => $tenantB->id,
            'mensagem' => 'Mensagem da empresa B', 'resposta' => FeedbackAgente::RESPOSTA_PADRAO,
        ]);

        $response = $this->actingAs($userA)->get('/equipe/suporte');

        $response->assertOk();
        $response->assertSee('Mensagem da empresa A');
        $response->assertDontSee('Mensagem da empresa B');
    }
}
