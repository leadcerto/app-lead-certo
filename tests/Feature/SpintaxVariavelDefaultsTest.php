<?php

namespace Tests\Feature;

use App\Models\SpintaxVariavel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpintaxVariavelDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_novas_variaveis_padrao_existem_com_pelo_menos_3_opcoes(): void
    {
        foreach (['saudacao', 'despedida', 'cta', 'gancho', 'prova_social', 'urgencia'] as $nome) {
            $this->assertArrayHasKey($nome, SpintaxVariavel::$defaults, "Falta a variável padrão '{$nome}'");
            $opcoes = array_filter(array_map('trim', explode("\n", SpintaxVariavel::$defaults[$nome]['opcoes'])));
            $this->assertGreaterThanOrEqual(3, count($opcoes), "'{$nome}' precisa de pelo menos 3 opções");
            $this->assertNotEmpty(SpintaxVariavel::$defaults[$nome]['label']);
        }
    }

    public function test_sorteio_de_variavel_padrao_nova_funciona_para_tenant_sem_customizacao(): void
    {
        $resultado = SpintaxVariavel::sorteio(999999, 'saudacao');

        $this->assertNotSame('', $resultado);
    }
}
