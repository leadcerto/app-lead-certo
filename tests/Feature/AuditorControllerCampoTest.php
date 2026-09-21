<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AuditorControllerCampoTest extends TestCase
{
    use RefreshDatabase;

    private function vinculoComDoisPendentes(): VinculoContatoTenant
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcia', 'empresa' => 'Transportes Silva']);

        return VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'nome'    => ['sugerido' => 'Marcia Souza', 'origem' => 'google'],
                'empresa' => ['sugerido' => 'Fretes ABC',  'origem' => 'google'],
            ],
        ]);
    }

    private function vinculoComPendentesEmail(): VinculoContatoTenant
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['email' => 'marcia.souza@example.com']);

        return VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'email' => ['sugerido' => 'marcia.s@newexample.com', 'origem' => 'google'],
            ],
        ]);
    }

    /**
     * Achado real 2026-09-21 (pedido do Leonardo): a tela "Sugestões de Nomes
     * & Sincronização" mostrava uma linha por CAMPO pendente (nome e
     * sobrenome do mesmo contato viravam duas linhas separadas), dificultando
     * a revisão. Agora agrupa por vínculo — uma linha por contato, com as 3
     * colunas de nome (nome/id/sobrenome) já prontas na mesma linha. Pendência
     * de outro campo (ex: empresa) continua aparecendo — só não ganha coluna
     * de nome própria, fica listada em outros_campos_pendentes — pra nunca
     * desaparecer da auditoria só porque não é nome/sobrenome.
     */
    public function test_lista_pendentes_uma_linha_por_vinculo_com_nome_e_sobrenome_juntos(): void
    {
        $vinculo = $this->vinculoComDoisPendentes(); // pendente: nome + empresa
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $res = $this->actingAs($user)->getJson('/api/painel/auditor/pendentes')->assertOk();

        $itens = collect($res->json('data'));
        $this->assertCount(1, $itens); // um vínculo = uma linha, mesmo com 2 campos pendentes

        $item = $itens->first();
        $this->assertSame($vinculo->id, $item['vinculo_id']);
        $this->assertSame("[{$vinculo->contato_id}]", $item['id_formatado']);
        $this->assertSame('Marcia', $item['nome_atual']);
        $this->assertSame('Marcia Souza', $item['nome_sugerido']);
        $this->assertNull($item['sobrenome_sugerido']); // não havia pendência de sobrenome
        $this->assertSame(['empresa'], $item['outros_campos_pendentes']);
    }

    public function test_vinculo_com_pendencia_so_de_outro_campo_nao_desaparece_da_lista(): void
    {
        $vinculo = $this->vinculoComPendentesEmail(); // pendente: só email, sem nome/sobrenome

        $user = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $res = $this->actingAs($user)->getJson('/api/painel/auditor/pendentes')->assertOk();

        $itens = collect($res->json('data'));
        $this->assertCount(1, $itens);
        $item = $itens->first();
        $this->assertNull($item['nome_sugerido']);
        $this->assertNull($item['sobrenome_sugerido']);
        $this->assertSame(['email'], $item['outros_campos_pendentes']);
    }

    public function test_aprovar_um_campo_nao_afeta_o_outro_pendente(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/campo/nome/aprovar")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Marcia Souza', $vinculo->contato->fresh()->nome);
        $this->assertArrayNotHasKey('nome', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('empresa', $vinculo->campos_pendentes_auditoria); // intacto
        $this->assertArrayHasKey('nome', $vinculo->campos_editados_humano ?? []); // aprovar = decisão humana
    }

    public function test_rejeitar_um_campo_mantem_valor_local_e_remove_a_pendencia(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/campo/empresa/rejeitar")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->fresh()->empresa);
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('nome', $vinculo->campos_pendentes_auditoria); // intacto
    }

    /**
     * Achado real 2026-09-21 (pedido do Leonardo): botão "Aprovar Tudo" numa
     * linha só, aprovando nome e sobrenome pendentes de um vínculo de uma vez
     * — evita ter que aprovar campo a campo quando os dois já estão certos.
     */
    public function test_aprovar_tudo_aplica_nome_e_sobrenome_pendentes_de_uma_vez(): void
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Paloma', 'sobrenome' => '9384']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'nome'      => ['sugerido' => 'Paloma Ribeiro', 'origem' => 'google'],
                'sobrenome' => ['sugerido' => 'Vendas',         'origem' => 'google'],
            ],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/aprovar-tudo")
            ->assertOk();

        $contato->refresh();
        $this->assertSame('Paloma Ribeiro', $contato->nome);
        $this->assertSame('Vendas', $contato->sobrenome);
        $this->assertNull($vinculo->fresh()->campos_pendentes_auditoria);
    }

    public function test_aprovar_tudo_nao_mexe_em_pendencia_de_outro_campo(): void
    {
        $vinculo = $this->vinculoComDoisPendentes(); // pendente: nome + empresa
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/aprovar-tudo")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Marcia Souza', $vinculo->contato->fresh()->nome);
        $this->assertArrayHasKey('empresa', $vinculo->campos_pendentes_auditoria); // intacto, não é nome/sobrenome
    }

    public function test_aprovar_tudo_sem_nenhuma_pendencia_de_nome_retorna_erro(): void
    {
        $vinculo = $this->vinculoComPendentesEmail(); // pendente: só email
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/aprovar-tudo")
            ->assertStatus(422);
    }

    public function test_rejeitar_tudo_mantem_valores_locais_e_limpa_pendencias_de_nome(): void
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Paloma', 'sobrenome' => '9384']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'nome'      => ['sugerido' => 'Paloma Ribeiro', 'origem' => 'google'],
                'sobrenome' => ['sugerido' => 'Vendas',         'origem' => 'google'],
                'empresa'   => ['sugerido' => 'Fretes ABC',     'origem' => 'google'],
            ],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/rejeitar-tudo")
            ->assertOk();

        $contato->refresh();
        $this->assertSame('Paloma', $contato->nome); // não mudou
        $this->assertSame('9384', $contato->sobrenome); // não mudou
        $vinculo->refresh();
        $this->assertArrayNotHasKey('nome', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayNotHasKey('sobrenome', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('empresa', $vinculo->campos_pendentes_auditoria); // intacto
    }

    /**
     * Achado real 2026-09-21: autoLimparNaoPessoas() usava a variável
     * $pendencia (nunca definida no escopo) em vez de $pendentes[$campo] —
     * fazia a sugestão do Google ser sempre ignorada nesse botão, mesmo
     * quando a sugestão já era um nome de pessoa válido.
     */
    public function test_auto_limpar_usa_a_sugestao_do_google_quando_ela_ja_e_nome_de_pessoa(): void
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Frete Rio Transportes', 'sobrenome' => null]);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'nome' => ['sugerido' => 'Joana Ferreira', 'origem' => 'google'],
            ],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/painel/auditor/pendentes/auto-limpar-nao-pessoas')
            ->assertOk();

        // Antes do fix: usava o valor ATUAL ("Frete Rio Transportes", nome de
        // empresa/lixo) em vez da sugestão do Google ("Joana Ferreira", nome
        // de pessoa válido) — o contato virava "Sem Nome" à toa.
        $this->assertSame('Joana Ferreira', $contato->fresh()->nome);
    }
}
