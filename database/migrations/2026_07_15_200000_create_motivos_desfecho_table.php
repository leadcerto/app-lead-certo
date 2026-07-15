<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motivos de encerramento (tag_desfecho) por tenant. Hoje cada tenant só tem
 * um Kanban (o de Vendas), então "por tenant" já resolve o pedido de "cada
 * Kanban tem os seus" — quando T-MULTI-KANBAN-ARQUITETURA existir, essa
 * tabela ganha uma coluna kanban_id e passa a ser escopada por Kanban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivos_desfecho', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('chave', 100);
            $table->string('label', 150);
            $table->boolean('e_venda')->default(false);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'chave']);
        });

        // Semeia os motivos que hoje estão fixos no código (kanban/index.blade.php)
        // pra todo tenant que já existe, pra ninguém perder as opções que já usa.
        $motivosPadrao = [
            ['chave' => 'venda_fechada', 'label' => 'Venda fechada', 'e_venda' => true,  'ordem' => 1],
            ['chave' => 'sem_interesse', 'label' => 'Sem interesse', 'e_venda' => false, 'ordem' => 2],
            ['chave' => 'preco_alto',    'label' => 'Preço alto',    'e_venda' => false, 'ordem' => 3],
            ['chave' => 'sem_resposta',  'label' => 'Sem resposta',  'e_venda' => false, 'ordem' => 4],
            ['chave' => 'fora_de_area',  'label' => 'Fora de área',  'e_venda' => false, 'ordem' => 5],
            ['chave' => 'outro',         'label' => 'Outro',         'e_venda' => false, 'ordem' => 6],
        ];

        $tenantIds = DB::table('tenants')->pluck('id');
        $agora     = now();

        foreach ($tenantIds as $tenantId) {
            foreach ($motivosPadrao as $motivo) {
                DB::table('motivos_desfecho')->insert([
                    'tenant_id'  => $tenantId,
                    'chave'      => $motivo['chave'],
                    'label'      => $motivo['label'],
                    'e_venda'    => $motivo['e_venda'],
                    'ordem'      => $motivo['ordem'],
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('motivos_desfecho');
    }
};
