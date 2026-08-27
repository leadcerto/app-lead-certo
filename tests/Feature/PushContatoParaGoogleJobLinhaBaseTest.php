<?php

namespace Tests\Feature;

use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushContatoParaGoogleJobLinhaBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_linha_de_base_mas_nao_marca_campo_editado_humano(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['resourceName' => 'people/c999'], 200)]);

        $tenant  = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['nome' => 'Marcos', 'empresa' => 'Faxa']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new PushContatoParaGoogleJob($contato->id, $tenant->id))->handle(app(\App\Services\GoogleService::class));

        $vinculo->refresh();
        $this->assertSame('Marcos', $vinculo->google_valores_enviados['nome'] ?? null);
        $this->assertSame('Faxa', $vinculo->google_valores_enviados['empresa'] ?? null);
        $this->assertNull($vinculo->campos_editados_humano);
    }
}
