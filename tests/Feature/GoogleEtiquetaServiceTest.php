<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleEtiquetaService;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleEtiquetaServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Achado real 2026-09-22 (Leonardo, caso real "Diego Ognibene" #98325):
     * quando sobrenome está vazio, o nome completo NUNCA é mais cortado no
     * espaço pra virar givenName/familyName separados — isso quebrava a
     * convenção dominante do sistema (nome completo num campo só). O ID do
     * banco continua indo no nome do meio, normalmente.
     */
    public function test_formatar_nome_para_google_mantem_nome_completo_sem_sobrenome_separado(): void
    {
        $contato = Contato::factory()->create([
            'id'        => 14380,
            'nome'      => 'Adalberto Martins',
            'sobrenome' => null,
        ]);

        $google = app(GoogleService::class);
        $entry  = $google->formatarNomeParaGoogle($contato);

        $this->assertSame('Adalberto Martins', $entry['givenName']);
        $this->assertSame('[14380]', $entry['middleName']);
        $this->assertArrayNotHasKey('familyName', $entry);
    }

    public function test_formatar_nome_para_google_usa_sobrenome_separado_quando_preenchido(): void
    {
        $contato = Contato::factory()->create([
            'id'        => 5500,
            'nome'      => 'Maria Clara',
            'sobrenome' => 'dos Santos',
        ]);

        $google = app(GoogleService::class);
        $entry  = $google->formatarNomeParaGoogle($contato);

        $this->assertSame('Maria Clara', $entry['givenName']);
        $this->assertSame('[5500]', $entry['middleName']);
        $this->assertSame('Dos Santos', $entry['familyName']);
    }

    public function test_sincronizar_grupos_padrao_mapeia_etiquetas_no_google(): void
    {
        $tenant = Tenant::factory()->create();
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        $token = GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@leadcerto.com',
            'access_token'  => 'tok',
            'refresh_token' => 'ref',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        // Mock das chamadas do Google People API
        Http::fake([
            '*contactGroups?pageSize=200*' => Http::response([
                'contactGroups' => [
                    ['name' => '🚩 NOVOS LEADS', 'resourceName' => 'contactGroups/novos_123'],
                    ['name' => '🚩 LEAD CERTO',  'resourceName' => 'contactGroups/lead_certo_456'],
                ],
            ], 200),
            '*contactGroups' => Http::response([
                'resourceName' => 'contactGroups/criado_789',
            ], 200),
        ]);

        $service = app(GoogleEtiquetaService::class);
        $mapeados = $service->sincronizarGrupos($token);

        $this->assertSame('contactGroups/novos_123', $mapeados['novos_leads'] ?? null);
        $this->assertSame('contactGroups/lead_certo_456', $mapeados['lead_certo'] ?? null);

        $this->assertDatabaseHas('etiqueta_google_grupos', [
            'tenant_id'                  => $tenant->id,
            'google_group_resource_name' => 'contactGroups/novos_123',
        ]);
    }

    /**
     * Achado real 24/09 (Leonardo, Frete Rio): o Leonardo renomeou/criou os
     * grupos reais no Google Contatos direto pela interface (ex.: "🚩 SEM
     * NOME" com 5.348 membros, "🚩 FORNECEDORES" com 70), mas
     * MAPEAMENTO_GRUPOS ainda só reconhecia os nomes antigos ("- 00 Sem
     * Nome", "- 00 Fornecedores") — o sistema vinha criando/usando grupos
     * órfãos e vazios em paralelo aos reais, sem o Leonardo notar porque ele
     * gerenciava os grupos certos manualmente. sincronizarGrupos() precisa
     * casar com o nome novo primeiro.
     */
    public function test_sincronizar_grupos_reconhece_nomes_novos_com_bandeirinha_pras_etiquetas_que_antes_usavam_prefixo_traco(): void
    {
        $tenant = Tenant::factory()->create();
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        $token = GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@leadcerto.com',
            'access_token'  => 'tok',
            'refresh_token' => 'ref',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        // sem_nome/fornecedor/pessoal só existem via EtiquetaSeeder em produção
        // (não roda nas migrations que RefreshDatabase aplica nos testes) —
        // leads_em_analise/lead_invalido/novos_leads/lead_certo já vêm da
        // migration 2026_08_28_000001, por isso não precisam ser criados aqui.
        foreach (['sem_nome' => '#F59E0B', 'fornecedor' => '#8B5CF6', 'pessoal' => '#06B6D4', 'cliente' => '#10B981'] as $slug => $cor) {
            Etiqueta::updateOrCreate(['tenant_id' => null, 'slug' => $slug], ['nome' => ucfirst($slug), 'cor' => $cor, 'ativo' => true]);
        }

        Http::fake([
            '*contactGroups?pageSize=200*' => Http::response([
                'contactGroups' => [
                    ['name' => '🚩 SEM NOME',      'resourceName' => 'contactGroups/sem_nome_real'],
                    ['name' => '🚩 FORNECEDORES',  'resourceName' => 'contactGroups/fornecedores_real'],
                    ['name' => '🚩 PESSOAL',       'resourceName' => 'contactGroups/pessoal_real'],
                    ['name' => '🚩 EM ANÁLISE',    'resourceName' => 'contactGroups/em_analise_real'],
                    // Achado real 24/09: "- CLIENTE" renomeado pra "🚩 CLIENTES"
                    // (lead que já comprou ao menos uma vez).
                    ['name' => '🚩 CLIENTES',      'resourceName' => 'contactGroups/clientes_real'],
                    // Grupo órfão antigo, ainda existe no Google mas vazio —
                    // não deve ser escolhido quando o nome novo também existe.
                    ['name' => '- 00 Sem Nome',    'resourceName' => 'contactGroups/sem_nome_orfao'],
                ],
            ], 200),
        ]);

        $mapeados = app(GoogleEtiquetaService::class)->sincronizarGrupos($token);

        $this->assertSame('contactGroups/sem_nome_real', $mapeados['sem_nome'] ?? null);
        $this->assertSame('contactGroups/fornecedores_real', $mapeados['fornecedor'] ?? null);
        $this->assertSame('contactGroups/pessoal_real', $mapeados['pessoal'] ?? null);
        $this->assertSame('contactGroups/em_analise_real', $mapeados['leads_em_analise'] ?? null);
        $this->assertSame('contactGroups/clientes_real', $mapeados['cliente'] ?? null);
    }

    /**
     * Achado real 29/09 (Leonardo, Frete Rio): nova etiqueta "🚩 FRIOS" pra
     * contatos extraídos de grupos/comunidades do WhatsApp (prospecção fria
     * futura, ainda sem contato feito). Pedido explícito: "anote tudo para
     * que nas novas contas você crie todas elas automaticamente" — precisa
     * casar com o grupo real quando já existe no Google, e criar do zero
     * (com o nome oficial certo) quando a conta é nova e o grupo não existe
     * ainda.
     */
    public function test_sincronizar_grupos_reconhece_etiqueta_frios(): void
    {
        $tenant = Tenant::factory()->create();
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        $token = GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@leadcerto.com',
            'access_token'  => 'tok',
            'refresh_token' => 'ref',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        Etiqueta::updateOrCreate(['tenant_id' => null, 'slug' => 'frios'], ['nome' => 'Frios', 'cor' => '#0284C7', 'ativo' => true]);

        Http::fake([
            '*contactGroups?pageSize=200*' => Http::response([
                'contactGroups' => [
                    ['name' => '🚩 FRIOS', 'resourceName' => 'contactGroups/frios_real'],
                ],
            ], 200),
        ]);

        $mapeados = app(GoogleEtiquetaService::class)->sincronizarGrupos($token);

        $this->assertSame('contactGroups/frios_real', $mapeados['frios'] ?? null);
    }

    public function test_sincronizar_grupos_cria_etiqueta_frios_do_zero_pra_conta_nova(): void
    {
        $tenant = Tenant::factory()->create();
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        $token = GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@leadcerto.com',
            'access_token'  => 'tok',
            'refresh_token' => 'ref',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        Etiqueta::updateOrCreate(['tenant_id' => null, 'slug' => 'frios'], ['nome' => 'Frios', 'cor' => '#0284C7', 'ativo' => true]);

        // Conta nova — nenhum grupo existe no Google ainda.
        Http::fake([
            '*contactGroups?pageSize=200*' => Http::response(['contactGroups' => []], 200),
            '*contactGroups' => Http::response(['resourceName' => 'contactGroups/frios_novo'], 200),
        ]);

        $mapeados = app(GoogleEtiquetaService::class)->sincronizarGrupos($token);

        $this->assertSame('contactGroups/frios_novo', $mapeados['frios'] ?? null);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'contactGroups')
            && ($request['contactGroup']['name'] ?? null) === '🚩 FRIOS');
    }

    /**
     * Achado real 24/09: os grupos "Fornecedores" e "Pessoal" já eram
     * provisionados no Google (sincronizarGrupos), mas atualizarMembrosContato()
     * nunca adicionava um contato a eles de verdade — só tratava sem_nome,
     * cliente e a promoção pra lead_certo.
     */
    public function test_atualiza_membro_do_grupo_fornecedor_quando_tipo_contato_e_fornecedor(): void
    {
        $tenant = Tenant::factory()->create();
        $token = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'teste@leadcerto.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $etiquetaFornecedor = Etiqueta::updateOrCreate(['tenant_id' => null, 'slug' => 'fornecedor'], ['nome' => 'Fornecedor', 'cor' => '#8B5CF6', 'ativo' => true]);
        EtiquetaGoogleGrupo::create([
            'etiqueta_id' => $etiquetaFornecedor->id, 'tenant_id' => $tenant->id,
            'google_group_resource_name' => 'contactGroups/fornecedores_real',
        ]);

        $contato = Contato::factory()->create(['tipo_contato' => 'fornecedor']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123',
        ]);

        Http::fake(['*contactGroups/fornecedores_real/members:modify*' => Http::response(['resourceName' => 'contactGroups/fornecedores_real'], 200)]);

        app(GoogleEtiquetaService::class)->atualizarMembrosContato($token, $contato, $vinculo);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fornecedores_real/members:modify')
            && in_array('people/c123', $request['resourceNamesToAdd'] ?? []));
    }

    public function test_atualiza_membro_do_grupo_pessoal_quando_tipo_contato_e_pessoal(): void
    {
        $tenant = Tenant::factory()->create();
        $token = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'teste@leadcerto.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $etiquetaPessoal = Etiqueta::updateOrCreate(['tenant_id' => null, 'slug' => 'pessoal'], ['nome' => 'Pessoal', 'cor' => '#06B6D4', 'ativo' => true]);
        EtiquetaGoogleGrupo::create([
            'etiqueta_id' => $etiquetaPessoal->id, 'tenant_id' => $tenant->id,
            'google_group_resource_name' => 'contactGroups/pessoal_real',
        ]);

        $contato = Contato::factory()->create(['tipo_contato' => 'pessoal']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c456',
        ]);

        Http::fake(['*contactGroups/pessoal_real/members:modify*' => Http::response(['resourceName' => 'contactGroups/pessoal_real'], 200)]);

        app(GoogleEtiquetaService::class)->atualizarMembrosContato($token, $contato, $vinculo);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'pessoal_real/members:modify')
            && in_array('people/c456', $request['resourceNamesToAdd'] ?? []));
    }
}
