<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ciclo COMPLETO push → pull, que nenhum teste da branch cobria (só metades
 * isoladas: ou o push gravando a linha de base, ou o pull lendo uma linha de
 * base montada à mão pelo próprio teste).
 *
 * O bug que estes testes fecham: os dois lados do ciclo sanitizam o nome com
 * funções DIFERENTES.
 *
 *   push  → GoogleService::limparNome()      (trim de borda + espaço + title case)
 *   pull  → ContatoSyncService::limparNome() (o acima + remove índice de agenda
 *                                             de 3-6 dígitos + palavra duplicada)
 *
 * Então "Kamily Kamily" (pushName do WhatsApp) e "Padaria 2000" (índice de
 * agenda) voltam do Google encolhidos, e as duas comparações que o pull faz
 * contra o lado local — a linha de base e o portão de similaridade — usavam
 * valores que nunca passaram pelo sanitizador do pull. Resultado: pendência
 * falsa de auditoria, ContatoPendente falso de "número possivelmente
 * reciclado", ou o nome local reescrito sozinho.
 */
class GoogleContatosRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private function criarToken(Tenant $tenant): GoogleToken
    {
        // GoogleToken::booted() dispara ProvisionarEtiquetasGoogleJob de
        // verdade (QUEUE_CONNECTION=sync) -- sem isso, os testes deste
        // arquivo fazem chamada HTTP real pra API do Google.
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        return GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'franqueado@empresa.com',
            'access_token'  => 'access-token-teste',
            'refresh_token' => 'refresh-token-teste',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);
    }

    /**
     * Devolve o bloco `names` EXATO que o nosso código mandou pro Google na
     * última chamada gravada — é isso que o Google guarda e devolve no pull.
     * Sem isso o teste estaria adivinhando o payload em vez de fazer round trip.
     */
    private function nomesEnviadosAoGoogle(): array
    {
        $names = null;
        foreach (Http::recorded() as [$request, $response]) {
            $body = $request->data();
            if (isset($body['names'][0])) {
                $names = $body['names'][0];
            }
        }

        $this->assertNotNull($names, 'nenhuma chamada com bloco names foi enviada ao Google');

        return $names;
    }

    /**
     * Fake do pull montado a partir do que o push realmente enviou. O Google
     * compõe o displayName sozinho, a partir de givenName + middleName +
     * familyName — replicado aqui.
     */
    private function fakePullComOEcoDoPush(string $telefone, array $names): void
    {
        $displayName = trim(implode(' ', array_filter([
            $names['givenName']  ?? null,
            $names['middleName'] ?? null,
            $names['familyName'] ?? null,
        ])));

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c777',
                    'etag'         => 'etag-777',
                    'names'        => [array_merge($names, ['displayName' => $displayName])],
                    'phoneNumbers' => [['value' => $telefone]],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);
    }

    /**
     * Nome com palavra duplicada consecutiva ("Kamily Kamily", típico de
     * pushName do WhatsApp). O pull encolhe pra "Kamily"; o lado local segue
     * com "Kamily Kamily" nas duas comparações → similaridade 63% (abaixo do
     * limiar de 75%) → ContatoPendente falso de número reciclado.
     */
    public function test_round_trip_de_nome_com_palavra_duplicada_nao_gera_contato_pendente(): void
    {
        $telefone = '5521999998888';

        Http::fake(['*people:createContact*' => Http::response(['resourceName' => 'people/c777'], 200)]);

        $tenant  = Tenant::factory()->create();
        $token   = $this->criarToken($tenant);
        $contato = Contato::factory()->create(['telefone' => $telefone, 'nome' => 'Kamily Kamily']);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new PushContatoParaGoogleJob($contato->id, $tenant->id))
            ->handle(app(GoogleService::class), app(ContatoSyncService::class));

        $names = $this->nomesEnviadosAoGoogle();
        $this->fakePullComOEcoDoPush($telefone, $names);

        app(ContatoSyncService::class)->sincronizar($token->fresh(), $tenant->id);

        $this->assertSame(
            0,
            ContatoPendente::where('contato_existente_id', $contato->id)->count(),
            'o eco do nosso próprio push não pode voltar como "número possivelmente reciclado"'
        );

        $this->assertSame(
            'Kamily Kamily',
            $contato->fresh()->nome,
            'o ciclo não pode reescrever sozinho o nome local'
        );

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayNotHasKey('nome', $vinculo->campos_pendentes_auditoria ?? []);
    }

    /**
     * Nome terminado em índice de agenda ("... 4500"). Aqui o primeiro nome é
     * longo o bastante pra passar no portão de similaridade, então o sintoma é
     * o outro: linha de base gravada pelo PushContatoParaGoogleJob divergindo do
     * que o pull deriva → pendência falsa em campos_pendentes_auditoria.
     */
    public function test_round_trip_de_nome_com_indice_de_agenda_nao_gera_pendencia_falsa(): void
    {
        $telefone = '5521999997777';

        Http::fake(['*people:createContact*' => Http::response(['resourceName' => 'people/c777'], 200)]);

        $tenant  = Tenant::factory()->create();
        $token   = $this->criarToken($tenant);
        $contato = Contato::factory()->create([
            'telefone' => $telefone,
            'nome'     => 'Distribuidora Central 4500',
        ]);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        (new PushContatoParaGoogleJob($contato->id, $tenant->id))
            ->handle(app(GoogleService::class), app(ContatoSyncService::class));

        // Backfill da seção 8 do design: todo campo sincronizado já preenchido
        // vira "editado por humano" no deploy — é esse estado que faz o pull
        // comparar contra a linha de base em vez de aceitar de olhos fechados.
        $vinculo->refresh()->update([
            'campos_editados_humano' => ['nome' => now()->toIso8601String()],
        ]);

        $names = $this->nomesEnviadosAoGoogle();
        $this->fakePullComOEcoDoPush($telefone, $names);

        app(ContatoSyncService::class)->sincronizar($token->fresh(), $tenant->id);

        $vinculo->refresh();
        $this->assertArrayNotHasKey(
            'nome',
            $vinculo->campos_pendentes_auditoria ?? [],
            'o índice de agenda que o próprio pull remove não pode virar sugestão de nome novo'
        );
        $this->assertSame('Distribuidora Central 4500', $contato->fresh()->nome);
        $this->assertSame(0, ContatoPendente::where('contato_existente_id', $contato->id)->count());
    }

    /**
     * Mesmo ciclo, mas com a linha de base gravada pelo OUTRO ponto de escrita:
     * ContatosController::valorEnviadoAoGoogle(), quando o humano edita o nome
     * na ficha do painel.
     */
    public function test_round_trip_da_edicao_no_painel_nao_gera_pendencia_falsa(): void
    {
        $telefone = '5521999996666';

        Http::fake(['*updateContact*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant  = Tenant::factory()->create();
        $token   = $this->criarToken($tenant);
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create(['telefone' => $telefone, 'nome' => 'Antigo']);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create([
            'contato_id'           => $contato->id,
            'tenant_id'            => $tenant->id,
            'google_resource_name' => 'people/c777',
            'google_etag'          => 'etag-antigo',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/painel/contato/{$contato->id}", ['nome' => 'Distribuidora Central 4500'])
            ->assertOk();

        $names = $this->nomesEnviadosAoGoogle();
        $this->fakePullComOEcoDoPush($telefone, $names);

        app(ContatoSyncService::class)->sincronizar($token->fresh(), $tenant->id);

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayNotHasKey(
            'nome',
            $vinculo->campos_pendentes_auditoria ?? [],
            'o nome que o humano acabou de digitar não pode voltar do Google como conflito'
        );
        $this->assertSame('Distribuidora Central 4500', $contato->fresh()->nome);
        $this->assertSame(0, ContatoPendente::where('contato_existente_id', $contato->id)->count());
    }
}
