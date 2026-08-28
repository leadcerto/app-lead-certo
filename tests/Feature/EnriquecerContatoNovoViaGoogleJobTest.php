<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnriquecerContatoNovoViaGoogleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_nome_encontrado_no_google_pro_lead_novo(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'names'        => [['displayName' => 'Rodrigo Alves']],
                'phoneNumbers' => [['value' => '5521999998888']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999998888', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Rodrigo Alves', $contato->fresh()->nome);
    }

    public function test_sanitiza_display_name_duplicado_antes_de_salvar(): void
    {
        // Fix round 2: a sanitização de "índice de agenda + palavra duplicada"
        // é feita por ContatoSyncService::limparNome() (não mais por
        // GoogleService::limparNome(), que voltou a ser só trim de borda +
        // title case, igual ao caminho de push já em produção).

        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'names'        => [['displayName' => 'Kamily Kamily']],
                'phoneNumbers' => [['value' => '5521999991111']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999991111', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Kamily', $contato->fresh()->nome);
    }

    /**
     * Achado Important da revisão de branch: o job achava o contato no Google
     * e usava $pessoa só pra extrair os 4 valores de campo, jogando fora
     * resourceName/etag. Sem gravar o vínculo, o PushContatoParaGoogleJob
     * (disparado por outro caminho) não sabia que esse contato já existe lá e
     * criava um cartão DUPLICADO na agenda do cliente.
     */
    public function test_grava_resource_name_e_etag_do_contato_achado_no_google(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'resourceName' => 'people/c777',
                'etag'         => 'etag-777',
                'names'        => [['displayName' => 'Rodrigo Alves', 'givenName' => 'Rodrigo Alves']],
                'phoneNumbers' => [['value' => '5521999995555']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999995555', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $vinculo->refresh();
        $this->assertSame('people/c777', $vinculo->google_resource_name);
        $this->assertSame('etag-777', $vinculo->google_etag);
    }

    /**
     * Vínculo que já aponta pra um resource não pode ser sobrescrito pelo
     * resultado de uma busca — o etag da busca pode ser de outro cartão e
     * quebraria o próximo PATCH.
     */
    public function test_nao_sobrescreve_vinculo_que_ja_aponta_pra_um_resource(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'resourceName' => 'people/c888',
                'etag'         => 'etag-888',
                'names'        => [['givenName' => 'Rodrigo Alves']],
                'phoneNumbers' => [['value' => '5521999994444']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999994444', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create([
            'contato_id'           => $contato->id,
            'tenant_id'            => $tenant->id,
            'google_resource_name' => 'people/c111',
            'google_etag'          => 'etag-111',
        ]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $vinculo->refresh();
        $this->assertSame('people/c111', $vinculo->google_resource_name);
        $this->assertSame('etag-111', $vinculo->google_etag);
    }

    /**
     * Mesmo motivo do fix do pull em lote: displayName é composto pelo Google
     * a partir de givenName + middleName + familyName, e o middleName carrega
     * o ID do banco que nós mesmos gravamos lá.
     */
    public function test_usa_given_name_e_ignora_o_eco_do_nosso_id_no_display_name(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'resourceName' => 'people/c666',
                'etag'         => 'etag-666',
                'names'        => [[
                    'displayName' => 'Marcia 5000 Souza',
                    'givenName'   => 'Marcia',
                    'middleName'  => '5000',
                    'familyName'  => 'Souza',
                ]],
                'phoneNumbers' => [['value' => '5521999993333']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999993333', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Marcia', $contato->fresh()->nome);
    }

    /**
     * Achado da revisão de branch: o endpoint legado atualizarGoogleSobrenome()
     * ainda grava o ID do banco no familyName — sem a mesma guarda que
     * ContatoSyncService::extrairDados() já tem, esse ID vazaria pro campo
     * sobrenome também por este caminho de busca em tempo real.
     */
    public function test_sobrenome_so_digitos_nao_e_gravado_como_sobrenome(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'names'        => [['givenName' => 'Rodrigo', 'familyName' => '4321']],
                'phoneNumbers' => [['value' => '5521999992222']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999992222', 'nome' => 'Sem Nome', 'sobrenome' => null]);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertNull($contato->fresh()->sobrenome);
    }

    public function test_sem_google_token_nao_faz_nada(): void
    {
        Http::fake();

        $tenant  = Tenant::factory()->create(); // sem GoogleToken
        $contato = Contato::factory()->create(['telefone' => '5521999997777', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        Http::assertNothingSent();
        $this->assertSame('Sem Nome', $contato->fresh()->nome);
    }

    public function test_sem_resultado_no_google_nao_faz_nada(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response(['results' => []], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999996666', 'nome' => 'Sem Nome']);
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Sem Nome', $contato->fresh()->nome);
    }
}
