<?php

namespace Tests\Unit;

use App\Models\AgendamentoAvaliacao;
use App\Models\CategoriaTemplate;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\TemplateAvaliacao;
use App\Models\User;
use App\Services\SorteioTemplateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SorteioTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private SorteioTemplateService $service;
    private Tenant $tenant;
    private PerfilGmb $perfil;
    private User $avaliador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new SorteioTemplateService();
        $this->tenant    = Tenant::factory()->create();
        $this->perfil    = PerfilGmb::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Frete Rio', 'city' => 'Rio de Janeiro',
            'state' => 'RJ', 'link_gmb' => 'https://maps.google.com/?cid=1', 'ativo' => true,
        ]);
        $this->avaliador = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'perfil' => 'avaliador',
            'city' => 'Rio de Janeiro', 'state' => 'RJ',
        ]);
    }

    private function criarTemplate(array $atributos = []): TemplateAvaliacao
    {
        $categoria = $atributos['categoria_id']
            ?? CategoriaTemplate::create(['tenant_id' => $this->tenant->id, 'nome' => 'Categoria ' . uniqid()])->id;

        return TemplateAvaliacao::create(array_merge([
            'tenant_id'    => $this->tenant->id,
            'codigo'       => 'T-' . uniqid(),
            'texto'        => 'Texto de exemplo',
            'categoria_id' => $categoria,
            'ativo'        => true,
        ], $atributos));
    }

    private function agendar(TemplateAvaliacao $template, Carbon $data, ?PerfilGmb $perfil = null): AgendamentoAvaliacao
    {
        return AgendamentoAvaliacao::create([
            'tenant_id' => $this->tenant->id, 'perfil_id' => ($perfil ?? $this->perfil)->id,
            'template_id' => $template->id, 'avaliador_id' => $this->avaliador->id,
            'data_agendada' => $data->toDateString(), 'status' => 'pendente',
        ]);
    }

    public function test_ignora_templates_inativos(): void
    {
        $inativo = $this->criarTemplate(['ativo' => false]);
        $ativo   = $this->criarTemplate();

        $sorteado = $this->service->sortear($this->perfil, Carbon::today());

        $this->assertSame($ativo->id, $sorteado->id);
    }

    public function test_nao_repete_template_usado_no_mesmo_perfil_em_60_dias(): void
    {
        $usadoRecente = $this->criarTemplate();
        $disponivel   = $this->criarTemplate();
        $this->agendar($usadoRecente, Carbon::today()->subDays(10));

        $sorteado = $this->service->sortear($this->perfil, Carbon::today());

        $this->assertSame($disponivel->id, $sorteado->id);
    }

    public function test_permite_repetir_apos_60_dias(): void
    {
        $template = $this->criarTemplate();
        $this->agendar($template, Carbon::today()->subDays(90));

        $sorteado = $this->service->sortear($this->perfil, Carbon::today());

        $this->assertSame($template->id, $sorteado->id);
    }

    public function test_nao_repete_template_ja_sorteado_na_semana_para_outro_perfil(): void
    {
        $categoria = CategoriaTemplate::create(['tenant_id' => $this->tenant->id, 'nome' => 'Cat ' . uniqid()]);
        $usadoNaSemana = $this->criarTemplate(['categoria_id' => $categoria->id]);
        $disponivel    = $this->criarTemplate(['categoria_id' => $categoria->id]);

        $outroPerfil = PerfilGmb::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Frete Rio 2', 'city' => 'Rio de Janeiro',
            'state' => 'RJ', 'link_gmb' => 'https://maps.google.com/?cid=2', 'ativo' => true,
        ]);
        $this->agendar($usadoNaSemana, Carbon::today(), $outroPerfil);

        $sorteado = $this->service->sortear($this->perfil, Carbon::today());

        $this->assertSame($disponivel->id, $sorteado->id);
    }

    public function test_diversifica_categoria_dentro_da_semana(): void
    {
        $categoriaA = CategoriaTemplate::create(['tenant_id' => $this->tenant->id, 'nome' => 'Categoria A']);
        $categoriaB = CategoriaTemplate::create(['tenant_id' => $this->tenant->id, 'nome' => 'Categoria B']);
        $templateA  = $this->criarTemplate(['categoria_id' => $categoriaA->id]);
        $templateB  = $this->criarTemplate(['categoria_id' => $categoriaB->id]);

        // templateA já foi sorteado nesta semana (outro dia) — categoria A deve ser evitada agora.
        $jaSorteados = collect([$templateA->id]);

        $sorteado = $this->service->sortear($this->perfil, Carbon::today(), $jaSorteados);

        $this->assertSame($templateB->id, $sorteado->id);
    }

    public function test_fallback_relaxa_regra_de_categoria_quando_so_sobra_a_mesma_categoria(): void
    {
        $categoria = CategoriaTemplate::create(['tenant_id' => $this->tenant->id, 'nome' => 'Única']);
        $templateJaSorteado = $this->criarTemplate(['categoria_id' => $categoria->id]);
        $unicoDisponivel    = $this->criarTemplate(['categoria_id' => $categoria->id]);

        $jaSorteados = collect([$templateJaSorteado->id]);

        // Regra 4 (categoria) eliminaria os dois templates dessa categoria — fallback
        // relaxa a regra e ainda assim entrega um template válido (não usado na semana).
        $sorteado = $this->service->sortear($this->perfil, Carbon::today(), $jaSorteados);

        $this->assertSame($unicoDisponivel->id, $sorteado->id);
    }

    public function test_retorna_null_quando_nao_ha_nenhum_template_ativo(): void
    {
        $sorteado = $this->service->sortear($this->perfil, Carbon::today());

        $this->assertNull($sorteado);
    }

    public function test_pode_agendar_bloqueia_apos_2_avaliacoes_na_semana(): void
    {
        $template = $this->criarTemplate();
        $this->agendar($template, Carbon::today());
        $this->agendar($this->criarTemplate(), Carbon::today());

        $this->assertFalse($this->service->podeAgendar($this->perfil, Carbon::today()));
    }

    public function test_pode_agendar_permite_com_menos_de_2_na_semana(): void
    {
        $template = $this->criarTemplate();
        $this->agendar($template, Carbon::today());

        $this->assertTrue($this->service->podeAgendar($this->perfil, Carbon::today()));
    }

    public function test_vagas_na_semana_calcula_o_restante(): void
    {
        $this->agendar($this->criarTemplate(), Carbon::today());

        $this->assertSame(1, $this->service->vagasNaSemana($this->perfil, Carbon::today()));
    }
}
