<?php

namespace Tests\Feature;

use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug real (visto em 29 e 30/07): `contatos:limpar-nomes` quebrava às 00:10
 * ao normalizar o telefone de um contato pra um valor que já pertencia a
 * OUTRO contato — o UPDATE direto estourava a constraint única de
 * `contatos.telefone` e derrubava o comando inteiro no meio do chunk.
 * Corrigido pra mesclar (via ContatoMergeService) em vez de sobrescrever,
 * mesmo padrão já usado em NormalizarTelefonesCommand/Auditoria de Contatos.
 */
class LimparNomesContatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_normaliza_telefone_que_colide_com_contato_existente_mescla_em_vez_de_quebrar(): void
    {
        // '21964143278' (11 dígitos, sem DDI) normaliza pra '5521964143278' —
        // que já é o telefone do contato canônico abaixo.
        $canonico  = Contato::factory()->create(['telefone' => '5521964143278', 'nome' => 'Fulano de Tal']);
        $duplicata = Contato::factory()->create(['telefone' => '21964143278', 'nome' => 'Sem Nome']);

        $this->artisan('contatos:limpar-nomes --so-telefones')->assertExitCode(0);

        $this->assertDatabaseHas('contatos', ['id' => $canonico->id, 'telefone' => '5521964143278']);
        $this->assertSoftDeleted('contatos', ['id' => $duplicata->id]);
    }

    public function test_normaliza_telefone_sem_colisao_continua_funcionando_normalmente(): void
    {
        $contato = Contato::factory()->create(['telefone' => '21964143278']);

        $this->artisan('contatos:limpar-nomes --so-telefones')->assertExitCode(0);

        $this->assertSame('5521964143278', $contato->fresh()->telefone);
    }

    public function test_dry_run_nao_mescla_nem_altera_nada(): void
    {
        $canonico  = Contato::factory()->create(['telefone' => '5521964143278']);
        $duplicata = Contato::factory()->create(['telefone' => '21964143278']);

        $this->artisan('contatos:limpar-nomes --so-telefones --dry-run')->assertExitCode(0);

        $this->assertSame('21964143278', $duplicata->fresh()->telefone);
        $this->assertDatabaseHas('contatos', ['id' => $duplicata->id, 'deleted_at' => null]);
    }
}
