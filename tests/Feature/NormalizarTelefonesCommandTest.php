<?php

namespace Tests\Feature;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizarTelefonesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_pendencia_de_numero_internacional_ja_reconhecido_como_valido(): void
    {
        // Simula o cenário real: um lead de Portugal foi marcado como "telefone
        // inválido" por uma versão antiga do validador (que só conhecia DDDs do
        // Brasil). O número em si já está correto, só precisa parar de ser
        // tratado como pendência.
        $contato = Contato::factory()->create(['telefone' => '351911926680']);

        $auditoria = AuditoriaContato::create([
            'contato_id'     => $contato->id,
            'tipo'           => 'telefone',
            'campo'          => 'telefone',
            'valor_original' => '351911926680',
            'status'         => 'pendente',
            'observacao'     => 'Formato desconhecido — DDD inválido: 35',
        ]);

        $this->artisan('contatos:normalizar-telefones')->assertExitCode(0);

        $this->assertSame('351911926680', $contato->fresh()->telefone);
        $this->assertSame('resolvido', $auditoria->fresh()->status);
    }

    public function test_numero_verdadeiramente_invalido_continua_pendente(): void
    {
        $contato = Contato::factory()->create(['telefone' => '123']);

        $this->artisan('contatos:normalizar-telefones')->assertExitCode(0);

        $auditoria = AuditoriaContato::where('contato_id', $contato->id)
            ->where('tipo', 'telefone')
            ->first();

        $this->assertNotNull($auditoria);
        $this->assertSame('pendente', $auditoria->status);
    }

    public function test_dry_run_nao_resolve_pendencia(): void
    {
        $contato = Contato::factory()->create(['telefone' => '351911926680']);

        $auditoria = AuditoriaContato::create([
            'contato_id'     => $contato->id,
            'tipo'           => 'telefone',
            'campo'          => 'telefone',
            'valor_original' => '351911926680',
            'status'         => 'pendente',
        ]);

        $this->artisan('contatos:normalizar-telefones --dry-run')->assertExitCode(0);

        $this->assertSame('pendente', $auditoria->fresh()->status);
    }
}
