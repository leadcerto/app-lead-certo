<?php

namespace Tests\Unit;

use App\Models\CategoriaTemplate;
use App\Models\Tenant;
use App\Models\TemplateAvaliacao;
use App\Services\OpenRouterService;
use App\Services\TemplateAvaliacaoIaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateAvaliacaoIaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarCategoria(array $atributos = []): CategoriaTemplate
    {
        $tenantId = $atributos['tenant_id'] ?? Tenant::factory()->create(['nome' => 'Frete Rio'])->id;

        return CategoriaTemplate::create(array_merge([
            'tenant_id' => $tenantId,
            'nome'      => 'Custo-Benefício',
        ], $atributos, ['tenant_id' => $tenantId]));
    }

    public function test_cria_templates_a_partir_da_resposta_da_ia(): void
    {
        $categoria = $this->criarCategoria(['palavras_chave' => ['pontualidade', 'preço justo']]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'Fui muito bem atendido, recomendo! 🚚'],
                    ['texto' => 'Chegaram no horário combinado e foram muito atenciosos.'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 2);

        $this->assertSame(2, $criados);
        $this->assertSame(2, TemplateAvaliacao::where('categoria_id', $categoria->id)->count());
        $this->assertDatabaseHas('templates_avaliacao', [
            'tenant_id'    => $categoria->tenant_id,
            'categoria_id' => $categoria->id,
            'ativo'        => true,
        ]);
    }

    public function test_gera_codigo_com_prefixo_ia(): void
    {
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [['texto' => 'Ótimo serviço, super recomendo!']],
            ]));
        });

        app(TemplateAvaliacaoIaService::class)->gerar($categoria, 1);

        $template = TemplateAvaliacao::where('categoria_id', $categoria->id)->first();
        $this->assertStringStartsWith('IA-', $template->codigo);
    }

    public function test_descarta_rascunho_com_estrela_no_texto(): void
    {
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => '⭐⭐⭐⭐⭐ Ótimo! Foi excelente.'],
                    ['texto' => 'Sem estrela nenhuma aqui, foi ótimo.'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 2);

        $this->assertSame(1, $criados);
        $this->assertSame(0, TemplateAvaliacao::whereRaw("texto LIKE '%⭐%'")->count());
    }

    public function test_descarta_rascunho_que_cita_o_nome_real_da_empresa(): void
    {
        // Achado ao vivo (2026-08-18): o texto não deve mais citar o nome
        // da empresa (nem via marcador) — soa artificial. Se a IA
        // desobedecer e escrever o nome de qualquer jeito, descarta.
        $categoria = $this->criarCategoria(); // tenant "Frete Rio"

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'A Frete Rio se destacou pela honestidade e transparência.'],
                    ['texto' => 'O serviço se destacou pela honestidade e transparência. 🔑'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 2);

        $this->assertSame(1, $criados);
        $this->assertSame(0, TemplateAvaliacao::whereRaw("texto LIKE '%Frete Rio%'")->count());
    }

    public function test_aceita_rascunho_sem_marcador_de_atendente_quando_permitido(): void
    {
        // Marcador de atendente é opcional — rascunho sem ele continua válido.
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'A equipe cumpriu tudo que prometeu, recomendo!'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 1, null, true);

        $this->assertSame(1, $criados);
    }

    public function test_descarta_rascunho_com_marcador_de_atendente_quando_desativado(): void
    {
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'Foi ótimo, [nome de quem te atendeu] merece parabéns.'],
                    ['texto' => 'Foi impecável, superou minhas expectativas.'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 2, null, false);

        $this->assertSame(1, $criados);
        $this->assertSame(0, TemplateAvaliacao::whereRaw("texto LIKE '%nome de quem te atendeu%'")->count());
    }

    public function test_retorna_zero_quando_ia_indisponivel(): void
    {
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(null);
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 3);

        $this->assertSame(0, $criados);
    }

    public function test_retorna_zero_quando_resposta_nao_e_json_valido(): void
    {
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('isso não é json');
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 3);

        $this->assertSame(0, $criados);
    }

    public function test_limita_quantidade_ao_maximo_permitido(): void
    {
        $categoria = $this->criarCategoria();

        $templates = array_fill(0, 10, ['texto' => 'Texto de exemplo genérico.']);

        $this->mock(OpenRouterService::class, function ($mock) use ($templates) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['templates' => $templates]));
        });

        // Pediu 999, mas o service deve limitar a chamada a no máximo 10.
        app(TemplateAvaliacaoIaService::class)->gerar($categoria, 999);

        $this->assertLessThanOrEqual(10, TemplateAvaliacao::where('categoria_id', $categoria->id)->count());
    }
}
