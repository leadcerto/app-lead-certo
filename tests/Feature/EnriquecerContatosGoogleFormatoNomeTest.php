<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 2026-09-17 (Leonardo): contatos do WhatsApp aparecendo com
 * formatos diferentes ("Nina 98277 Cardoso" vs "Wagner Nascimento [21] BAU
 * 0300"). Investigação achou DUAS implementações divergentes do mesmo nome
 * pro Google no código: GoogleService::formatarNomeParaGoogle() (usada na
 * criação/edição individual de contato) e o comando em lote
 * EnriquecerContatosGoogle, que injetava "[{$contato->id}]" colado no
 * SOBRENOME (formato diferente, e no campo errado). Rodar esse comando em
 * lote sobrescrevia contatos já corretos com o formato errado. Fix: o
 * comando passa a reusar GoogleService::formatarNomeParaGoogle() como fonte
 * única de verdade, em vez de duplicar a lógica de montagem do nome.
 *
 * Padrão final confirmado com o Leonardo direto no Google Contacts
 * (2026-09-17): ID do banco entre colchetes no NOME DO MEIO, ex: "[4]" —
 * não no sobrenome, e sem duplicar o ID em outro campo.
 */
class EnriquecerContatosGoogleFormatoNomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_comando_em_lote_usa_mesmo_formato_com_id_entre_colchetes_da_criacao_individual(): void
    {
        Bus::fake();
        Http::fake([
            'https://people.googleapis.com/v1/contactGroups*' => Http::response(['contactGroups' => []], 200),
            'https://people.googleapis.com/v1/contactGroups' => Http::response(['resourceName' => 'contactGroups/fake'], 200),
            'https://people.googleapis.com/v1/*:updateContact*' => Http::response(['etag' => 'novo-etag'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'teste@exemplo.com',
            'access_token' => 'token-valido', 'refresh_token' => 'refresh',
            'token_type' => 'Bearer', 'expires_at' => now()->addHour(), 'scopes' => [],
        ]);

        $contato = Contato::factory()->create([
            'nome' => 'Wagner Nascimento', 'sobrenome' => 'BAU 0300',
            'nome_do_meio' => null, 'telefone' => '5521970044875',
        ]);
        $contato->update(['nome_do_meio' => (string) $contato->id]);

        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $this->artisan('google:enriquecer', ['--tenant' => $tenant->id])->assertSuccessful();

        Http::assertSent(function ($request) use ($contato) {
            if (! str_contains($request->url(), ':updateContact')) {
                return false;
            }

            $nome = $request->data()['names'][0] ?? [];

            return ($nome['givenName'] ?? null) === 'Wagner Nascimento'
                && ($nome['middleName'] ?? null) === "[{$contato->id}]"
                && ! str_contains($nome['familyName'] ?? '', '[')
                && ! str_contains($nome['familyName'] ?? '', ']');
        });
    }
}
