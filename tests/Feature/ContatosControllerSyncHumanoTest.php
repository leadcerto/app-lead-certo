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
}
