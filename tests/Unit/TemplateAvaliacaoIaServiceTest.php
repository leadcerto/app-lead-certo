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
        $tenant = $atributos['tenant_id'] ?? Tenant::factory()->create()->id;

        return CategoriaTemplate::create(array_merge([
            'tenant_id' => $tenant,
            'nome'      => 'Custo-Benefício',
        ], $atributos, ['tenant_id' => $tenant]));
    }

    public function test_cria_templates_a_partir_da_resposta_da_ia(): void
    {
        $categoria = $this->criarCategoria(['palavras_chave' => ['pontualidade', 'preço justo']]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'Fui muito bem atendido pela [nome da empresa], recomendo!'],
                    ['texto' => 'Chegaram no horário combinado. A [nome da empresa] foi muito atenciosa.'],
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
                'templates' => [['texto' => 'Ótimo serviço da [nome da empresa]!']],
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
                    ['texto' => '⭐⭐⭐⭐⭐ Ótimo! [nome da empresa] foi excelente.'],
                    ['texto' => 'Sem estrela nenhuma aqui, [nome da empresa] foi ótima.'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 2);

        $this->assertSame(1, $criados);
        $this->assertSame(0, TemplateAvaliacao::whereRaw("texto LIKE '%⭐%'")->count());
    }

    public function test_descarta_rascunho_sem_marcador_de_empresa(): void
    {
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'O serviço foi excelente do início ao fim.'],
                ],
            ]));
        });

        $criados = app(TemplateAvaliacaoIaService::class)->gerar($categoria, 1);

        $this->assertSame(0, $criados);
        $this->assertSame(0, TemplateAvaliacao::where('categoria_id', $categoria->id)->count());
    }

    public function test_aceita_rascunho_sem_marcador_de_atendente_quando_permitido(): void
    {
        // Marcador de atendente é opcional por padrão — rascunho sem ele
        // continua válido, desde que tenha o marcador de empresa.
        $categoria = $this->criarCategoria();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'templates' => [
                    ['texto' => 'A [nome da empresa] cumpriu tudo que prometeu, recomendo!'],
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
                    ['texto' => 'A [nome da empresa] foi ótima, [nome de quem te atendeu] merece parabéns.'],
                    ['texto' => 'A [nome da empresa] foi impecável, superou minhas expectativas.'],
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

        $templates = array_fill(0, 10, ['texto' => 'Texto de exemplo da [nome da empresa].']);

        $this->mock(OpenRouterService::class, function ($mock) use ($templates) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['templates' => $templates]));
        });

        // Pediu 999, mas o service deve limitar a chamada a no máximo 10.
        app(TemplateAvaliacaoIaService::class)->gerar($categoria, 999);

        $this->assertLessThanOrEqual(10, TemplateAvaliacao::where('categoria_id', $categoria->id)->count());
    }
}
