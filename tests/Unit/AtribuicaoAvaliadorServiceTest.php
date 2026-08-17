<?php

namespace Tests\Unit;

use App\Models\AgendamentoAvaliacao;
use App\Models\CategoriaTemplate;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\TemplateAvaliacao;
use App\Models\User;
use App\Services\AtribuicaoAvaliadorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtribuicaoAvaliadorServiceTest extends TestCase
{
    use RefreshDatabase;

    private AtribuicaoAvaliadorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtribuicaoAvaliadorService();
    }

    private function criarPerfil(int $tenantId, string $city = 'Rio de Janeiro', string $state = 'RJ'): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenantId, 'nome' => 'Perfil ' . uniqid(), 'city' => $city,
            'state' => $state, 'link_gmb' => 'https://maps.google.com/?cid=' . uniqid(), 'ativo' => true,
        ]);
    }

    private function criarAvaliador(int $tenantId, string $city = 'Rio de Janeiro', string $state = 'RJ', bool $ativo = true): User
    {
        return User::factory()->create([
            'tenant_id' => $tenantId, 'perfil' => 'avaliador', 'city' => $city, 'state' => $state, 'ativo' => $ativo,
        ]);
    }

    public function test_resolve_avaliador_do_mesmo_tenant_e_regiao(): void
    {
        $tenant    = Tenant::factory()->create();
        $perfil    = $this->criarPerfil($tenant->id);
        $avaliador = $this->criarAvaliador($tenant->id);

        $resolvido = $this->service->resolverAvaliador($perfil, Carbon::today());

        $this->assertSame($avaliador->id, $resolvido->id);
    }

    public function test_nao_atribui_avaliador_de_outro_tenant_mesmo_com_mesma_cidade(): void
    {
        // Regressão: antes o match era só por city+state, sem checar tenant_id —
        // dois franqueados na mesma cidade se misturavam.
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $perfil       = $this->criarPerfil($tenant->id);
        $this->criarAvaliador($outroTenant->id); // mesma cidade/estado, tenant errado

        $resolvido = $this->service->resolverAvaliador($perfil, Carbon::today());

        $this->assertNull($resolvido);
    }

    public function test_ignora_avaliador_de_cidade_diferente(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant->id, 'Rio de Janeiro', 'RJ');
        $this->criarAvaliador($tenant->id, 'São Paulo', 'SP');

        $resolvido = $this->service->resolverAvaliador($perfil, Carbon::today());

        $this->assertNull($resolvido);
    }

    public function test_ignora_avaliador_inativo(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant->id);
        $this->criarAvaliador($tenant->id, ativo: false);

        $resolvido = $this->service->resolverAvaliador($perfil, Carbon::today());

        $this->assertNull($resolvido);
    }

    public function test_atribui_ao_avaliador_com_menor_carga_na_semana(): void
    {
        $tenant       = Tenant::factory()->create();
        $perfil       = $this->criarPerfil($tenant->id);
        $sobrecarregado = $this->criarAvaliador($tenant->id);
        $livre          = $this->criarAvaliador($tenant->id);

        $categoria = CategoriaTemplate::create(['tenant_id' => $tenant->id, 'nome' => 'Cat']);
        $template  = TemplateAvaliacao::create([
            'tenant_id' => $tenant->id, 'codigo' => 'T-1', 'texto' => 'x',
            'categoria_id' => $categoria->id, 'ativo' => true,
        ]);
        AgendamentoAvaliacao::create([
            'tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'template_id' => $template->id,
            'avaliador_id' => $sobrecarregado->id, 'data_agendada' => Carbon::today()->toDateString(),
            'status' => 'pendente',
        ]);

        $resolvido = $this->service->resolverAvaliador($perfil, Carbon::today());

        $this->assertSame($livre->id, $resolvido->id);
    }

    public function test_listar_disponiveis_ordenados_do_menos_para_o_mais_carregado(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant->id);
        $livre  = $this->criarAvaliador($tenant->id);
        $sobrecarregado = $this->criarAvaliador($tenant->id);

        $categoria = CategoriaTemplate::create(['tenant_id' => $tenant->id, 'nome' => 'Cat']);
        $template  = TemplateAvaliacao::create([
            'tenant_id' => $tenant->id, 'codigo' => 'T-1', 'texto' => 'x',
            'categoria_id' => $categoria->id, 'ativo' => true,
        ]);
        AgendamentoAvaliacao::create([
            'tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'template_id' => $template->id,
            'avaliador_id' => $sobrecarregado->id, 'data_agendada' => Carbon::today()->toDateString(),
            'status' => 'pendente',
        ]);

        $lista = $this->service->listarDisponiveisOrdenados($perfil, Carbon::today());

        $this->assertSame($livre->id, $lista->first()->id);
        $this->assertSame($sobrecarregado->id, $lista->last()->id);
    }
}
