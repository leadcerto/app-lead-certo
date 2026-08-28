<?php

namespace Tests\Unit;

use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatoExcluidoDoFunilComercialTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipo_contato_padrao_do_banco_nao_exclui(): void
    {
        // tipo_contato é NOT NULL com default 'lead' no banco — não dá pra
        // testar "nulo" de verdade, só confirmar que o default não exclui.
        // ->fresh() porque o default do banco não aparece no objeto em
        // memória até recarregar do banco.
        $contato = Contato::factory()->create()->fresh();

        $this->assertSame('lead', $contato->tipo_contato);
        $this->assertFalse($contato->excluidoDoFunilComercial());
    }

    public function test_tipo_contato_lead_nao_exclui(): void
    {
        $contato = Contato::factory()->create(['tipo_contato' => 'lead']);

        $this->assertFalse($contato->excluidoDoFunilComercial());
    }

    public function test_tipo_contato_pessoal_exclui(): void
    {
        $contato = Contato::factory()->create(['tipo_contato' => 'pessoal']);

        $this->assertTrue($contato->excluidoDoFunilComercial());
    }

    public function test_outros_tipos_tambem_excluem(): void
    {
        // 'colaborador' fica de fora de propósito: é um slug de Etiqueta
        // válido, mas não está no ENUM tipo_contato do banco (inconsistência
        // pré-existente, fora do escopo desta feature — anotada em
        // TAREFAS.md). Provisionar um grupo "colaborador" quebraria isso.
        foreach (['fornecedor', 'parceiro', 'cliente'] as $tipo) {
            $contato = Contato::factory()->create(['tipo_contato' => $tipo]);
            $this->assertTrue($contato->excluidoDoFunilComercial(), "tipo '{$tipo}' deveria excluir");
        }
    }
}
