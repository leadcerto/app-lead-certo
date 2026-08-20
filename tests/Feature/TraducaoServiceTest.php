<?php

namespace Tests\Feature;

use App\Services\OpenRouterService;
use App\Services\TraducaoService;
use Tests\TestCase;

class TraducaoServiceTest extends TestCase
{
    public function test_detecta_idioma_ingles(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('en');
        });

        $this->assertSame('en', app(TraducaoService::class)->detectarIdioma('Do you deliver to São Paulo?'));
    }

    public function test_retorna_null_quando_ia_nao_consegue_identificar(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('NENHUM');
        });

        $this->assertNull(app(TraducaoService::class)->detectarIdioma('ok, obrigado'));
    }

    public function test_retorna_null_pra_texto_muito_curto_sem_nem_chamar_a_ia(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        $this->assertNull(app(TraducaoService::class)->detectarIdioma('ok'));
    }

    public function test_retorna_null_quando_openrouter_falha(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(null);
        });

        $this->assertNull(app(TraducaoService::class)->detectarIdioma('Do you deliver to São Paulo?'));
    }

    public function test_traduz_pt_para_ingles(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfect! I already have everything I need.');
        });

        $traduzido = app(TraducaoService::class)->traduzir('Perfeito! Já tenho tudo que preciso.', 'en');

        $this->assertSame('Perfect! I already have everything I need.', $traduzido);
    }

    public function test_nao_chama_ia_quando_idioma_alvo_e_igual_ao_de_origem(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        $texto = app(TraducaoService::class)->traduzir('Olá, tudo bem?', 'pt');

        $this->assertSame('Olá, tudo bem?', $texto);
    }

    public function test_retorna_null_quando_traducao_falha(): void
    {
        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(null);
        });

        $this->assertNull(app(TraducaoService::class)->traduzir('Olá', 'en'));
    }
}
