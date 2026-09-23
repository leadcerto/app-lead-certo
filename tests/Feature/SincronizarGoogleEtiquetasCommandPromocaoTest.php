<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 23/09 (Leonardo, caso real "Eduardo #14680"): o robô agendado
 * (contatos:sincronizar-google-etiquetas, roda a cada 10min) estrutura o nome
 * com o ID certinho, mas o contato fica preso em 🚩 NOVOS LEADS mesmo depois
 * de já ter 🚩 LEAD CERTO — nunca sai da etiqueta antiga. Causa raiz: desde a
 * implementação original (03/09), o comando chama
 * GoogleEtiquetaService::atualizarMembrosContato() sem o 4º argumento
 * ($promoverLeadCerto), que default pra false — a transição de etiqueta
 * (adiciona LEAD CERTO, remove NOVOS LEADS / LEADS EM ANÁLISE) nunca roda
 * pelo caminho automático, só quando alguém aciona manualmente pelo painel.
 */
class SincronizarGoogleEtiquetasCommandPromocaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_comando_move_contato_de_novos_leads_para_lead_certo(): void
    {
        $tenant = Tenant::factory()->create();

        Etiqueta::create(['tenant_id' => null, 'nome' => 'Novos Leads', 'slug' => 'novos_leads']);
        Etiqueta::create(['tenant_id' => null, 'nome' => 'Lead Certo', 'slug' => 'lead_certo']);

        $token = GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@leadcerto.com',
            'access_token'  => 'tok',
            'refresh_token' => 'ref',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        $contato = Contato::factory()->create(['id' => 14680, 'nome' => 'Eduardo', 'sobrenome' => null]);

        $vinculo = VinculoContatoTenant::create([
            'tenant_id'             => $tenant->id,
            'contato_id'            => $contato->id,
            'google_resource_name'  => 'people/c5452744310778490310',
            'google_etag'           => 'etag-fake',
        ]);

        Http::fake([
            '*contactGroups?pageSize=200*' => Http::response([
                'contactGroups' => [
                    ['name' => '🚩 NOVOS LEADS', 'resourceName' => 'contactGroups/novos_123'],
                    ['name' => '🚩 LEAD CERTO',  'resourceName' => 'contactGroups/lead_certo_456'],
                ],
            ], 200),
            '*:updateContact*' => Http::response(['resourceName' => $vinculo->google_resource_name], 200),
            '*members:modify'  => Http::response([], 200),
        ]);

        Artisan::call('contatos:sincronizar-google-etiquetas', ['--tenant' => $tenant->id]);

        Http::assertSent(function ($request) use ($vinculo) {
            return $request->url() === 'https://people.googleapis.com/v1/contactGroups/lead_certo_456/members:modify'
                && ($request->data()['resourceNamesToAdd'] ?? []) === [$vinculo->google_resource_name];
        });

        Http::assertSent(function ($request) use ($vinculo) {
            return $request->url() === 'https://people.googleapis.com/v1/contactGroups/novos_123/members:modify'
                && ($request->data()['resourceNamesToRemove'] ?? []) === [$vinculo->google_resource_name];
        });
    }
}
