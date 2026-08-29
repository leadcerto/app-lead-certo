<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContatosControllerSyncHumanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_edicao_no_painel_marca_campo_editado_humano_e_atualiza_linha_de_base(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);
        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['empresa' => null]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", ['empresa' => 'Fretes ABC'])
            ->assertOk();

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano);
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
    }

    public function test_edicao_por_usuario_nao_privilegiado_tambem_marca_campo_editado_humano(): void
    {
        // Ruling do controller (2026-08-26): qualquer usuário logado editando pelo
        // painel marca campos_editados_humano, independente de perfil. A distinção
        // dono/admin vs vendedor é sobre auditoria de conflito de NOME (ver bloco
        // acima), não sobre "isso conta como edição humana pro sync do Google".
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);
        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['empresa' => null]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", ['empresa' => 'Fretes ABC'])
            ->assertOk();

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano);
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
    }

    public function test_ramo_de_auditoria_marca_editado_humano_para_campos_aplicados_mas_nao_para_nome(): void
    {
        // Achado Critical da revisão da Task 4: quando o nome diverge do master
        // (vai pra auditoria) mas outro campo sincronizado (empresa) vem junto
        // na mesma requisição, esse outro campo É aplicado ao Contato e
        // empurrado pro Google — então precisa ser marcado em
        // campos_editados_humano, senão o próximo pull do cron sobrescreve o
        // valor que o humano acabou de digitar. 'nome' não foi aplicado ao
        // master (foi pra campos_pendentes_auditoria), então não deve ser
        // marcado como editado-humano.
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);
        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['nome' => 'João Silva', 'empresa' => null]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", [
                'nome'    => 'João S.',
                'empresa' => 'Fretes ABC',
            ])
            ->assertOk();

        $response->assertJson(['auditoria' => true]);

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano);
        $this->assertArrayNotHasKey('nome', $vinculo->campos_editados_humano ?? []);
        $this->assertArrayHasKey('nome', $vinculo->campos_pendentes_auditoria ?? []);
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);

        // Nome local do Contato master fica intacto (auditoria pendente).
        $this->assertSame('João Silva', $contato->fresh()->nome);
    }

    /**
     * Achado Important da revisão de branch: a ficha do painel
     * (resources/views/contatos/importar.blade.php, salvarFicha()) manda TODOS
     * os campos do formulário em toda gravação — não só o que mudou. Marcar
     * como editado-humano qualquer campo PRESENTE no payload fazia editar só
     * "observações" travar nome/sobrenome/empresa/email pra sempre, jogando
     * toda correção futura vinda do Google na fila de auditoria.
     */
    public function test_salvar_campo_sincronizado_com_o_mesmo_valor_nao_marca_editado_humano(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);
        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create([
            'nome'    => 'João Silva',
            'empresa' => 'Fretes ABC',
            'email'   => 'joao@fretes.com',
        ]);
        // Sem isso, o hook VinculoContatoTenant::created() dispara o job de
        // busca em tempo real de verdade (fila sync em teste) e o
        // assertNothingSent() abaixo pegaria a chamada dele, não a do PATCH.
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        // Ficha inteira reenviada; só 'observacoes' mudou de fato.
        $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", [
                'nome'        => 'João Silva',
                'empresa'     => 'Fretes ABC',
                'email'       => 'joao@fretes.com',
                'observacoes' => 'Ligou hoje pedindo orçamento',
            ])
            ->assertOk();

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertNull(
            $vinculo->campos_editados_humano,
            'reenviar a ficha sem alterar campo sincronizado não pode travá-los como editados por humano'
        );
        $this->assertSame('Ligou hoje pedindo orçamento', $contato->fresh()->observacoes);

        // Achado da revisão de branch: sincronizarComGoogle() disparava PATCH
        // pro Google toda vez que a ficha era salva, mesmo sem nenhum dos 4
        // campos sincronizados ter mudado de verdade.
        Http::assertNothingSent();
        $this->assertSame('etag-antigo', $vinculo->refresh()->google_etag, 'sem chamada ao Google, o etag não pode rotacionar');
    }

    public function test_apenas_o_campo_sincronizado_que_mudou_e_marcado_editado_humano(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);
        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create([
            'nome'    => 'João Silva',
            'empresa' => 'Fretes ABC',
        ]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", [
                'nome'    => 'João Silva',       // igual
                'empresa' => 'Transportes Silva', // mudou
            ])
            ->assertOk();

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano ?? []);
        $this->assertArrayNotHasKey('nome', $vinculo->campos_editados_humano ?? []);
    }

    /**
     * Linha de base gravada pelo push do painel também tem que ser o valor
     * TRANSFORMADO — GoogleService::enriquecerContato() manda
     * givenName = limparNome(nome) e familyName = limparNome(sobrenome).
     */
    public function test_linha_de_base_do_painel_guarda_o_valor_transformado(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);
        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['nome' => 'Antigo', 'sobrenome' => null]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", [
                'nome'      => 'marcia',
                'sobrenome' => 'souza',
            ])
            ->assertOk();

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertSame('Marcia', $vinculo->google_valores_enviados['nome'] ?? null);
        $this->assertSame('Souza', $vinculo->google_valores_enviados['sobrenome'] ?? null);
    }
}
