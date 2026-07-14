<?php

namespace Tests\Feature;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrigirNomeIgualTelefoneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrige_contatos_com_nome_igual_ao_telefone(): void
    {
        $comProblema = Contato::factory()->create(['telefone' => '5521999998888', 'nome' => '5521999998888']);
        $normal      = Contato::factory()->create(['telefone' => '5521988887777', 'nome' => 'João Silva']);

        $auditoria = AuditoriaContato::create([
            'contato_id' => $comProblema->id, 'tipo' => 'nome_invalido', 'campo' => 'nome',
            'valor_original' => '5521999998888', 'valor_sugerido' => 'Sem Nome', 'status' => 'pendente',
        ]);

        $this->artisan('contatos:corrigir-nome-telefone')->assertExitCode(0);

        $this->assertSame('Sem Nome', $comProblema->fresh()->nome);
        $this->assertSame('João Silva', $normal->fresh()->nome);
        $this->assertSame('resolvido', $auditoria->fresh()->status);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521977776666', 'nome' => '5521977776666']);

        $this->artisan('contatos:corrigir-nome-telefone --dry-run')->assertExitCode(0);

        $this->assertSame('5521977776666', $contato->fresh()->nome);
    }
}
