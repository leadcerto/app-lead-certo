<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaMensagemVariacaoBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_cria_variacao_protegida_para_mensagem_existente(): void
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        // Mensagem criada ANTES da migration de backfill rodar de novo (simula tenant antigo)
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá {nome}, bem-vindo!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        // A suíte roda em sqlite :memory: com RefreshDatabase reaproveitando o schema já
        // migrado; para exercitar o up() de fato, roda-se rollback + migrate direcionados
        // a essa migration específica (mesmo padrão usado em KanbanColunasBackfillTest).
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);

        $variacao = $msg->variacoes()->first();
        $this->assertNotNull($variacao);
        $this->assertSame('Olá {nome}, bem-vindo!', $variacao->conteudo);
        $this->assertSame('humano', $variacao->origem);
        $this->assertTrue($variacao->protegida);
        $this->assertTrue($variacao->ativa);
    }

    public function test_backfill_nao_cria_variacao_para_mensagem_so_imagem(): void
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => '', 'imagem_url' => 'https://exemplo.com/img.png', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);

        $this->assertCount(0, $msg->variacoes()->get());
    }
}
