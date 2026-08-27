<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    /**
     * Achado Critical da revisão de branch: a linha de base precisa guardar o
     * valor TRANSFORMADO que o Google recebeu (givenName = limparNome(nome),
     * familyName = limparNome(sobrenome)), não o valor cru do banco. Guardando
     * o cru, o pull seguinte comparava "Souza" (vindo do Google) contra "souza"
     * (linha de base crua) e abria conflito falso em todo contato com nome
     * fora do title case ou com sobrenome.
     */
    public function test_linha_de_base_guarda_o_valor_transformado_e_nao_o_cru(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['resourceName' => 'people/c999'], 200)]);

        $tenant  = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['nome' => 'marcia', 'sobrenome' => 'souza']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new PushContatoParaGoogleJob($contato->id, $tenant->id))->handle(app(\App\Services\GoogleService::class));

        $vinculo->refresh();
        $this->assertSame('Marcia', $vinculo->google_valores_enviados['nome'] ?? null);
        $this->assertSame('Souza', $vinculo->google_valores_enviados['sobrenome'] ?? null);
    }

    public function test_linha_de_base_do_nome_registra_o_placeholder_sem_nome(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['resourceName' => 'people/c999'], 200)]);

        $tenant  = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        // Nome igual ao telefone → criarContato() envia givenName 'Sem Nome'
        $contato = Contato::factory()->create(['telefone' => '5521999990000', 'nome' => '5521999990000']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new PushContatoParaGoogleJob($contato->id, $tenant->id))->handle(app(\App\Services\GoogleService::class));

        $vinculo->refresh();
        $this->assertSame('Sem Nome', $vinculo->google_valores_enviados['nome'] ?? null);
    }
}
