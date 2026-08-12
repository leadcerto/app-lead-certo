<?php

namespace Tests\Feature;

use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bug real (visto em 29 e 30/07): `contatos:limpar-nomes` quebrava às 00:10
 * ao normalizar o telefone de um contato pra um valor que já pertencia a
 * OUTRO contato — o UPDATE direto estourava a constraint única de
 * `contatos.telefone` e derrubava o comando inteiro no meio do chunk.
 * Corrigido pra mesclar (via ContatoMergeService) em vez de sobrescrever,
 * mesmo padrão já usado em NormalizarTelefonesCommand/Auditoria de Contatos.
 *
 * Achado real (2026-08-11/12): o filtro de "nome suspeito" (regex) deixava
 * passar batido texto que não formata como sujeira de sistema mas também não
 * é nome de pessoa/empresa (ex: "Pai Saudade 💕", "Block rastreadores") — nunca
 * era nem enviado pra IA avaliar. Corrigido pra avaliar todo contato pelo
 * menos uma vez (`nome_revisado_ia_em` evita reprocessar pra sempre) e, pra
 * veredito "lixo", aplicar "Sem Nome" direto em vez de enfileirar pra revisão
 * humana — a fila antiga chegou a acumular 3.539 pendências nunca revisadas.
 */
class LimparNomesContatosTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRespostaIA(array $vereditos): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($vereditos)]]],
            ], 200),
        ]);
    }

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

    public function test_aplica_sem_nome_automaticamente_quando_ia_classifica_como_lixo(): void
    {
        config(['services.openrouter.key' => 'fake-key']);
        $contato = Contato::factory()->create(['nome' => 'Pai Saudade 💕']);

        $this->fakeRespostaIA([
            ['id' => $contato->id, 'tipo' => 'lixo', 'nome' => null, 'descritor' => null],
        ]);

        $this->artisan('contatos:limpar-nomes')->assertExitCode(0);

        $fresh = $contato->fresh();
        $this->assertSame('Sem Nome', $fresh->nome);
        $this->assertNotNull($fresh->nome_revisado_ia_em);
        $this->assertDatabaseMissing('auditoria_contatos', ['contato_id' => $contato->id]);
    }

    public function test_nao_reenvia_pra_ia_contato_ja_revisado(): void
    {
        config(['services.openrouter.key' => 'fake-key']);
        Contato::factory()->create(['nome' => 'Qualquer Coisa', 'nome_revisado_ia_em' => now()]);

        Http::fake();

        $this->artisan('contatos:limpar-nomes')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_marca_revisado_mesmo_quando_ia_confirma_nome_valido(): void
    {
        config(['services.openrouter.key' => 'fake-key']);
        $contato = Contato::factory()->create(['nome' => 'Carlos Eduardo']);

        $this->fakeRespostaIA([
            ['id' => $contato->id, 'tipo' => 'pessoa', 'nome' => 'Carlos Eduardo', 'descritor' => null],
        ]);

        $this->artisan('contatos:limpar-nomes')->assertExitCode(0);

        $this->assertNotNull($contato->fresh()->nome_revisado_ia_em);
    }

    public function test_dry_run_de_nomes_nao_aplica_sem_nome_nem_marca_revisado(): void
    {
        config(['services.openrouter.key' => 'fake-key']);
        $contato = Contato::factory()->create(['nome' => 'Block rastreadores']);

        $this->fakeRespostaIA([
            ['id' => $contato->id, 'tipo' => 'lixo', 'nome' => null, 'descritor' => null],
        ]);

        $this->artisan('contatos:limpar-nomes --dry-run')->assertExitCode(0);

        $fresh = $contato->fresh();
        $this->assertSame('Block rastreadores', $fresh->nome);
        $this->assertNull($fresh->nome_revisado_ia_em);
    }
}
