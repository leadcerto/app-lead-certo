<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureTenantEquipeMarketingTest extends TestCase
{
    use RefreshDatabase;

    // Achado real 2026-08-20: quase todos os controllers do painel leem
    // $request->user()->tenant_id direto (30 arquivos, 0 usam a sessão que
    // o EnsureTenant seta) — então testar isso via HTTP num controller
    // qualquer não prova nada sobre ESTE middleware, só sobre aquele
    // controller. Este teste isola o que de fato mudou: o EnsureTenant
    // guarda em sessão o tenant escolhido via ?tenant_id=, e o TenantScope
    // (usado por WhatsappCanal e outros) respeita essa sessão. Migrar os
    // controllers pra também respeitarem isso é trabalho separado, maior,
    // registrado em TAREFAS.md — não faz parte desta mudança.

    private function rodarMiddleware(User $user, ?int $tenantIdQuery): void
    {
        $request = Request::create('/qualquer', 'GET', $tenantIdQuery ? ['tenant_id' => $tenantIdQuery] : []);
        $request->setUserResolver(fn () => $user);

        (new EnsureTenant())->handle($request, fn ($req) => response()->json([]));
    }

    public function test_diretor_marketing_tem_tenant_id_trocado_em_sessao_via_query_param(): void
    {
        $tenantCasa   = Tenant::factory()->create();
        $tenantAlheio = Tenant::factory()->create();
        $nathanel     = User::factory()->create(['tenant_id' => $tenantCasa->id, 'perfil' => 'diretor_marketing', 'ativo' => true]);

        $this->rodarMiddleware($nathanel, $tenantAlheio->id);

        $this->assertSame($tenantAlheio->id, session('tenant_id'));
    }

    public function test_gestor_de_trafego_dormente_tambem_troca_de_tenant_via_query_param(): void
    {
        // Ainda sem uso real hoje, mas o mecanismo já vale pra qualquer
        // perfil da equipe de marketing compartilhada, não só diretor_marketing.
        $tenantAlheio = Tenant::factory()->create();
        $gestor       = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'perfil' => 'gestor_trafego', 'ativo' => true]);

        $this->rodarMiddleware($gestor, $tenantAlheio->id);

        $this->assertSame($tenantAlheio->id, session('tenant_id'));
    }

    public function test_perfil_normal_ignora_query_param_e_usa_o_proprio_tenant(): void
    {
        $tenantCasa   = Tenant::factory()->create();
        $tenantAlheio = Tenant::factory()->create();
        $dono         = User::factory()->create(['tenant_id' => $tenantCasa->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->rodarMiddleware($dono, $tenantAlheio->id);

        $this->assertSame($tenantCasa->id, session('tenant_id'));
    }

    public function test_scope_por_sessao_realmente_filtra_modelo_que_usa_tenantscope(): void
    {
        // WhatsappCanal usa TenantScope (global scope automático) — prova
        // ponta a ponta que a sessão setada pelo middleware realmente filtra
        // consultas, não é só um valor solto sem efeito.
        $tenantAlheio = Tenant::factory()->create();
        $canalAlheio  = WhatsappCanal::factory()->create(['tenant_id' => $tenantAlheio->id]);
        WhatsappCanal::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $gestor = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'perfil' => 'diretor_marketing', 'ativo' => true]);
        $this->rodarMiddleware($gestor, $tenantAlheio->id);

        $visiveis = WhatsappCanal::all();

        $this->assertCount(1, $visiveis);
        $this->assertSame($canalAlheio->id, $visiveis->first()->id);
    }
}
