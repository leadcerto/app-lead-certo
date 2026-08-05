<?php

namespace Tests\Feature;

use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KanbanColunaObjetivoModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Manually apply the new migrations since RefreshDatabase doesn't find them
        if (!Schema::hasTable('kanban_coluna_objetivos')) {
            Schema::create('kanban_coluna_objetivos', function ($table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('coluna_kanban', 50);
                $table->string('texto', 255);
                $table->unsignedSmallInteger('ordem')->default(1);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
                $table->index(['tenant_id', 'coluna_kanban', 'ativo']);
            });
        }

        if (!Schema::hasColumn('kanbans', 'conhecimento_geral')) {
            Schema::table('kanbans', function ($table) {
                $table->text('conhecimento_geral')->nullable()->after('nome');
            });
        }

        if (!Schema::hasColumn('tickets_atendimento', 'objetivos_cumpridos')) {
            Schema::table('tickets_atendimento', function ($table) {
                $table->json('objetivos_cumpridos')->nullable()->after('lista_itens');
            });
        }
    }

    public function test_cria_objetivo_com_casts_corretos(): void
    {
        $tenant = Tenant::factory()->create();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id'     => $tenant->id,
            'coluna_kanban' => 'em_atendimento',
            'texto'         => 'Endereço de origem confirmado',
            'ordem'         => 1,
            'ativo'         => true,
        ]);

        $this->assertTrue($objetivo->fresh()->ativo);
        $this->assertIsInt($objetivo->fresh()->ordem);
    }

    public function test_ticket_persiste_objetivos_cumpridos_como_array(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'objetivos_cumpridos' => [1, 3],
        ]);

        $this->assertSame([1, 3], $ticket->fresh()->objetivos_cumpridos);
    }
}
