<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContatosControllerSyncHumanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_edicao_no_painel_marca_campo_editado_humano_e_atualiza_linha_de_base(): void
    {
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
}
