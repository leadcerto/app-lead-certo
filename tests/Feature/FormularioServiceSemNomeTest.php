<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Formulario;
use App\Models\Tenant;
use App\Services\FormularioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class FormularioServiceSemNomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_envio_sem_nome_cria_contato_como_sem_nome_nao_como_telefone(): void
    {
        Bus::fake();

        $tenant     = Tenant::factory()->create();
        $formulario = Formulario::create([
            'tenant_id' => $tenant->id,
            'uuid'      => 'form-teste-1',
            'nome'      => 'Formulário de teste',
            'ativo'     => true,
        ]);

        $resultado = app(FormularioService::class)->processar($formulario, [
            'telefone' => '21999998888',
        ], 'teste.com.br');

        $this->assertTrue($resultado['ok']);

        $contato = Contato::where('telefone', '5521999998888')->first();
        $this->assertNotNull($contato);
        $this->assertSame('Sem Nome', $contato->nome);
        $this->assertNotSame($contato->telefone, $contato->nome);
    }

    public function test_envio_com_nome_usa_o_nome_fornecido(): void
    {
        Bus::fake();

        $tenant     = Tenant::factory()->create();
        $formulario = Formulario::create([
            'tenant_id' => $tenant->id,
            'uuid'      => 'form-teste-2',
            'nome'      => 'Formulário de teste',
            'ativo'     => true,
        ]);

        app(FormularioService::class)->processar($formulario, [
            'telefone' => '21988887777',
            'nome'     => 'Maria Silva',
        ], 'teste.com.br');

        $contato = Contato::where('telefone', '5521988887777')->first();
        $this->assertSame('Maria Silva', $contato->nome);
    }
}
